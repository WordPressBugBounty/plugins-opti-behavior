<?php
/**
 * Funnel Auto-Builder
 *
 * Turns a recipe (Opti_Behavior_Funnel_Recipes) plus a site context
 * (Opti_Behavior_Funnel_Site_Detector) into a real funnel row, and produces the
 * suggestions payload the Funnels page renders.
 *
 * Design rules (spec.md §0 decisions 2 & 8, §1.3, §3.5, §4.2 — non-negotiable):
 *
 * 1. **One write path.** Every row is created through
 *    `Opti_Behavior_Funnel_Page::persist_funnel()`, which owns validation, the
 *    provenance columns (`source` / `recipe_id`) and the single
 *    `purge_page_caches()` site. The auto-builder never touches `$wpdb->insert`.
 * 2. **Dedupe flags, never hides.** `get_suggestions()` returns EVERY offered
 *    recipe; an equivalent existing funnel is reported through
 *    `already_created` / `similar_funnel`, never by dropping the card. The only
 *    thing that removes a card is an explicit user dismissal.
 * 3. **The create path is the authoritative guard.** A recipe whose step
 *    signature (or whose `recipe_id`) already exists returns
 *    `array( 'duplicate' => true, 'existing_funnel_id' => N )` instead of
 *    inserting, so a double-click or a stale client cannot produce a duplicate.
 * 4. **The tier gate is server-side.** `locked` in the payload is cosmetic;
 *    `create_from_recipe()` re-checks the ENV gate and rejects a Pro recipe on a
 *    Free site whatever the client claims (same contract as
 *    `funnel_advanced_filter_available()`, spec.md §1.3).
 * 5. **Never persist an unvalidated step.** `ajax_save_funnel()` validates
 *    nothing beyond "non-empty array" (spec.md §1.3), so the recipe registry's
 *    `validate_steps()` result is what gets written — and a recipe that cannot
 *    produce at least MIN_STEPS valid steps is refused, not truncated.
 *
 * @package opti-behavior
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-database.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-site-detector.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-recipes.php';

/**
 * Funnel Auto-Builder.
 *
 * @since 1.8.4
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
class Opti_Behavior_Funnel_Auto_Builder {

	/**
	 * Option holding the dismissed recipe ids (spec.md §4.3).
	 *
	 * @var string
	 */
	const DISMISSED_OPTION = 'opti_behavior_funnel_suggestions_dismissed';

	/**
	 * The persistence service — any object exposing
	 * `persist_funnel( array $data, $funnel_id = 0 )`. In production this is the
	 * Opti_Behavior_Funnel_Page instance that owns the single write path.
	 *
	 * @var object|null
	 */
	private $persister = null;

	/**
	 * Constructor.
	 *
	 * @param object|null $persister Object exposing persist_funnel().
	 */
	public function __construct( $persister = null ) {
		$this->persister = $persister;
	}

	/**
	 * Set (or replace) the persistence service.
	 *
	 * @param object $persister Object exposing persist_funnel().
	 * @return void
	 */
	public function set_persister( $persister ) {
		$this->persister = $persister;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * The site context, from the detector (transient-cached unless forced).
	 *
	 * @param bool $force Bypass the detection cache.
	 * @return array
	 */
	public function get_context( $force = false ) {
		return Opti_Behavior_Funnel_Site_Detector::instance()->detect( (bool) $force );
	}

	/**
	 * Build the suggestions payload of spec.md §4.2.
	 *
	 * Every offered recipe is returned — including Pro-tier ones (rendered
	 * locked with an upsell) and including recipes an equivalent funnel already
	 * exists for (flagged, never hidden — locked decision 8). Dismissed recipes
	 * are the sole omission, and a forced re-scan clears the dismissal list, so
	 * the omission is always reversible.
	 *
	 * A locked Pro recipe carries an EMPTY `steps` array until the Pro plugin
	 * supplies its builder through `opti_behavior_funnel_recipe_steps`; the UI
	 * must tolerate an empty step preview.
	 *
	 * @param array|null $context Site context; detected when null.
	 * @return array {
	 *     @type array  $site_types  Detected families, ordered by confidence.
	 *     @type array  $unresolved  Families detected with no usable URL.
	 *     @type array  $suggestions Cards (see build_suggestion()).
	 *     @type array  $dismissed   Currently dismissed recipe ids.
	 *     @type string $detected_at Detection timestamp.
	 *     @type bool   $pro_active  Whether the Pro tier gate is open.
	 * }
	 */
	public function get_suggestions( $context = null ) {
		$context = is_array( $context ) ? $context : $this->get_context();

		$dismissed = $this->get_dismissed();
		$existing  = $this->get_existing_index();
		$available = Opti_Behavior_Funnel_Recipes::instance()->get_available( $context );

		$suggestions = array();
		foreach ( $available as $recipe ) {
			if ( in_array( $recipe['id'], $dismissed, true ) ) {
				continue;
			}
			$suggestions[] = $this->build_suggestion( $recipe, $existing );
		}

		return array(
			'site_types'  => isset( $context['site_types'] ) ? array_values( (array) $context['site_types'] ) : array(),
			'unresolved'  => isset( $context['unresolved'] ) ? array_values( (array) $context['unresolved'] ) : array(),
			'suggestions' => $suggestions,
			'dismissed'   => $dismissed,
			'detected_at' => isset( $context['detected_at'] ) ? (string) $context['detected_at'] : '',
			'pro_active'  => $this->pro_available(),
		);
	}

	/**
	 * Materialize one recipe as a funnel.
	 *
	 * @param string     $recipe_id Recipe id.
	 * @param array|null $context   Site context; detected when null.
	 * @return array|WP_Error {
	 *     Success shape (spec.md §4.2):
	 *
	 *     @type int    $funnel_id           Created funnel id.
	 *     @type string $name                Funnel name.
	 *     @type bool   $backfilled          Whether history was replayed. False on
	 *                                       Free; Pro reports it through the
	 *                                       `opti_behavior_funnel_created_result`
	 *                                       filter.
	 *     @type int    $backfilled_sessions Sessions replayed (0 on Free).
	 *
	 *     Duplicate shape:
	 *
	 *     @type bool $duplicate          Always true.
	 *     @type int  $existing_funnel_id The funnel that already covers it.
	 * }
	 */
	public function create_from_recipe( $recipe_id, $context = null ) {
		$recipe_id = $this->sanitize_recipe_id( $recipe_id );
		if ( '' === $recipe_id ) {
			return new WP_Error( 'opti_behavior_funnel_recipe_invalid', __( 'Invalid recipe.', 'opti-behavior' ) );
		}

		$context = is_array( $context ) ? $context : $this->get_context();
		$recipes = Opti_Behavior_Funnel_Recipes::instance();

		// Offered set only: this enforces `phase`, `requires` and the minimum
		// step count in one place, so a recipe whose family resolved no URL can
		// never be forced into existence by a hand-crafted POST.
		$recipe = null;
		foreach ( $recipes->get_available( $context ) as $candidate ) {
			if ( $candidate['id'] === $recipe_id ) {
				$recipe = $candidate;
				break;
			}
		}

		if ( null === $recipe ) {
			return new WP_Error( 'opti_behavior_funnel_recipe_unavailable', __( 'This funnel recipe is not available for this site.', 'opti-behavior' ) );
		}

		// Tier gate — authoritative. The client `locked` flag is cosmetic.
		if ( Opti_Behavior_Funnel_Recipes::TIER_PRO === $recipe['tier'] && ! $this->pro_available() ) {
			return new WP_Error( 'opti_behavior_funnel_recipe_locked', __( 'This funnel recipe requires Opti-Behavior Pro.', 'opti-behavior' ) );
		}

		$steps = isset( $recipe['steps'] ) && is_array( $recipe['steps'] )
			? $recipe['steps']
			: $recipes->get_steps( $recipe_id, $context, $recipe );

		// Re-validate: `steps` may have travelled through a filter since it was
		// built, and persist_funnel() only checks "non-empty array".
		$steps = $recipes->validate_steps( $steps );

		if ( count( $steps ) < Opti_Behavior_Funnel_Recipes::MIN_STEPS ) {
			return new WP_Error( 'opti_behavior_funnel_recipe_unbuildable', __( 'This funnel recipe could not be built for this site.', 'opti-behavior' ) );
		}

		// Idempotency (spec.md §3.5): an identical journey, or the same recipe
		// materialized earlier, is reported — never inserted twice.
		$duplicate = $this->find_duplicate( $steps, $recipe_id );
		if ( null !== $duplicate ) {
			return array(
				'duplicate'          => true,
				'existing_funnel_id' => (int) $duplicate['id'],
				'name'               => (string) $duplicate['name'],
			);
		}

		if ( ! is_object( $this->persister ) || ! method_exists( $this->persister, 'persist_funnel' ) ) {
			return new WP_Error( 'opti_behavior_funnel_no_persister', __( 'Funnel could not be saved.', 'opti-behavior' ) );
		}

		$funnel_id = $this->persister->persist_funnel(
			array(
				'name'        => $recipe['label'],
				'description' => $recipe['description'],
				'steps'       => $steps,
				'status'      => 'active',
				'source'      => 'auto',
				'recipe_id'   => $recipe_id,
			)
		);

		if ( is_wp_error( $funnel_id ) ) {
			return $funnel_id;
		}

		$funnel_id = (int) $funnel_id;

		/**
		 * Fires after the auto-builder created a funnel from a recipe.
		 *
		 * The seam the Pro plugin uses for historical backfill (spec.md §4.4) —
		 * no Free-to-Pro hard coupling.
		 *
		 * @since 1.8.4
		 *
		 * @param int    $funnel_id Created funnel id.
		 * @param string $recipe_id Recipe that produced it.
		 * @param array  $context   Site context used to build the steps.
		 */
		do_action( 'opti_behavior_funnel_created', $funnel_id, $recipe_id, $context );

		$result = array(
			'funnel_id'           => $funnel_id,
			'name'                => (string) $recipe['label'],
			// Free never replays history. Pro's autopilot has already run its
			// backfill on the action above and reports the count through the
			// filter below — the seam exists so Free needs no knowledge of Pro.
			'backfilled'          => false,
			'backfilled_sessions' => 0,
		);

		/**
		 * Filters the create response before it reaches the client.
		 *
		 * Fired immediately AFTER `opti_behavior_funnel_created`, so a listener of
		 * that action (the Pro autopilot's historical backfill) can report what it
		 * did — `backfilled` / `backfilled_sessions` — in the same round-trip the
		 * user is waiting on.
		 *
		 * This is a reporting seam, not an override: the identity of the created
		 * funnel (`funnel_id`, `name`) and the idempotency contract (`duplicate`
		 * is only ever set by the duplicate branch above) are restored after the
		 * filter, so a misbehaving listener cannot corrupt the response.
		 *
		 * @since 1.8.4
		 *
		 * @param array  $result    Response array (see the return contract above).
		 * @param int    $funnel_id Created funnel id.
		 * @param string $recipe_id Recipe that produced it.
		 * @param array  $context   Site context used to build the steps.
		 */
		$result = apply_filters( 'opti_behavior_funnel_created_result', $result, $funnel_id, $recipe_id, $context );

		return $this->normalize_created_result( $result, $funnel_id, (string) $recipe['label'] );
	}

	/**
	 * Re-assert the create-response contract after the reporting filter.
	 *
	 * @param mixed  $result    Filtered result.
	 * @param int    $funnel_id Created funnel id.
	 * @param string $name      Funnel name.
	 * @return array
	 */
	protected function normalize_created_result( $result, $funnel_id, $name ) {
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		// A listener may only ADD reporting keys — never restate identity, and
		// never claim the create was a duplicate (that branch returns earlier).
		unset( $result['duplicate'], $result['existing_funnel_id'] );

		$result['funnel_id']           = (int) $funnel_id;
		$result['name']                = $name;
		$result['backfilled']          = ! empty( $result['backfilled'] );
		$result['backfilled_sessions'] = isset( $result['backfilled_sessions'] )
			? max( 0, (int) $result['backfilled_sessions'] )
			: 0;

		// "Backfilled" without a count is meaningless to the UI, and a count
		// without the flag would never be shown — keep the pair consistent.
		if ( ! $result['backfilled'] ) {
			$result['backfilled_sessions'] = 0;
		}

		return $result;
	}

	/**
	 * Create the whole recommended set — every offered, non-dismissed, unlocked
	 * recipe. Also the endpoint behind the onboarding opt-in checkbox.
	 *
	 * Locked (Pro) recipes are skipped rather than failed: a Free site asking for
	 * "the recommended funnels" wants the Free set, not an error.
	 *
	 * @param array|null $context Site context; detected when null.
	 * @return array {
	 *     @type array $created Entries of { recipe_id, funnel_id, name, backfilled,
	 *                          backfilled_sessions }.
	 *     @type array $skipped Entries of { recipe_id, reason, existing_funnel_id? }.
	 * }
	 */
	public function create_recommended( $context = null ) {
		$context = is_array( $context ) ? $context : $this->get_context();

		$created   = array();
		$skipped   = array();
		$dismissed = $this->get_dismissed();

		foreach ( Opti_Behavior_Funnel_Recipes::instance()->get_available( $context ) as $recipe ) {
			$recipe_id = $recipe['id'];

			if ( in_array( $recipe_id, $dismissed, true ) ) {
				$skipped[] = array(
					'recipe_id' => $recipe_id,
					'reason'    => 'dismissed',
				);
				continue;
			}

			if ( Opti_Behavior_Funnel_Recipes::TIER_PRO === $recipe['tier'] && ! $this->pro_available() ) {
				$skipped[] = array(
					'recipe_id' => $recipe_id,
					'reason'    => 'locked',
				);
				continue;
			}

			$result = $this->create_from_recipe( $recipe_id, $context );

			if ( is_wp_error( $result ) ) {
				$skipped[] = array(
					'recipe_id' => $recipe_id,
					'reason'    => $result->get_error_code(),
				);
				continue;
			}

			if ( ! empty( $result['duplicate'] ) ) {
				$skipped[] = array(
					'recipe_id'          => $recipe_id,
					'reason'             => 'duplicate',
					'existing_funnel_id' => (int) $result['existing_funnel_id'],
				);
				continue;
			}

			// Each item carries its own backfill report: create_from_recipe()
			// already applied `opti_behavior_funnel_created_result` per funnel,
			// so the bulk response needs no second filter pass — it just
			// forwards what was reported, and the UI can sum the sessions.
			$created[] = array(
				'recipe_id'           => $recipe_id,
				'funnel_id'           => (int) $result['funnel_id'],
				'name'                => (string) $result['name'],
				'backfilled'          => ! empty( $result['backfilled'] ),
				'backfilled_sessions' => isset( $result['backfilled_sessions'] ) ? (int) $result['backfilled_sessions'] : 0,
			);
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	// -------------------------------------------------------------------------
	// Dismissals (spec.md §3.5 / §4.3)
	// -------------------------------------------------------------------------

	/**
	 * Currently dismissed recipe ids.
	 *
	 * @return array<int,string>
	 */
	public function get_dismissed() {
		$stored = get_option( self::DISMISSED_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids = array();
		foreach ( $stored as $id ) {
			$id = $this->sanitize_recipe_id( $id );
			if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Dismiss one suggestion. Idempotent.
	 *
	 * @param string $recipe_id Recipe id.
	 * @return bool True when the id is dismissed after the call.
	 */
	public function dismiss( $recipe_id ) {
		$recipe_id = $this->sanitize_recipe_id( $recipe_id );
		if ( '' === $recipe_id ) {
			return false;
		}

		$dismissed = $this->get_dismissed();
		if ( in_array( $recipe_id, $dismissed, true ) ) {
			return true;
		}

		$dismissed[] = $recipe_id;
		update_option( self::DISMISSED_OPTION, $dismissed, false );

		return true;
	}

	/**
	 * Clear every dismissal — what "Re-scan site" does, so hiding a card is
	 * always reversible (spec.md §3.5).
	 *
	 * @return void
	 */
	public function clear_dismissed() {
		update_option( self::DISMISSED_OPTION, array(), false );
	}

	// -------------------------------------------------------------------------
	// Tier gate
	// -------------------------------------------------------------------------

	/**
	 * Whether Pro-tier recipes may be created.
	 *
	 * Deliberately the SAME coarse ENV gate as
	 * `Opti_Behavior_Funnel_Page::funnel_advanced_filter_available()`
	 * (spec.md §1.3): `Opti_Behavior_Pro_Feature_Guard::can_access()` only accepts
	 * server-signed feature keys from the license manifest, and minting a new key
	 * needs a license-server deployment. Kept in ONE helper so a later switch to a
	 * dedicated key is a single-spot change.
	 *
	 * @return bool
	 */
	public function pro_available() {
		return function_exists( 'opti_behavior_pro_active' )
			&& function_exists( 'opti_behavior_pro_validate_env' )
			&& opti_behavior_pro_active()
			&& opti_behavior_pro_validate_env();
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * One suggestion card.
	 *
	 * `build_steps` (a callable) is stripped here — the payload is JSON-encoded
	 * for the browser.
	 *
	 * @param array $recipe   Available recipe (already carries `steps`).
	 * @param array $existing Index from get_existing_index().
	 * @return array
	 */
	protected function build_suggestion( array $recipe, array $existing ) {
		$steps     = isset( $recipe['steps'] ) && is_array( $recipe['steps'] ) ? $recipe['steps'] : array();
		$signature = Opti_Behavior_Funnel_Database::get_steps_signature( $steps );

		$already_created = false;
		$similar         = null;

		// Same recipe materialized earlier wins the reporting: it is the more
		// precise statement ("you already created this one").
		if ( isset( $existing['by_recipe'][ $recipe['id'] ] ) ) {
			$already_created = true;
			$similar         = $existing['by_recipe'][ $recipe['id'] ];
		} elseif ( '' !== $signature && isset( $existing['by_signature'][ $signature ] ) ) {
			$similar = $existing['by_signature'][ $signature ];
		}

		return array(
			'id'              => $recipe['id'],
			'label'           => $recipe['label'],
			'description'     => $recipe['description'],
			'icon'            => $recipe['icon'],
			'tier'            => $recipe['tier'],
			'locked'          => ( Opti_Behavior_Funnel_Recipes::TIER_PRO === $recipe['tier'] && ! $this->pro_available() ),
			'steps'           => $steps,
			'already_created' => $already_created,
			'similar_funnel'  => $similar,
		);
	}

	/**
	 * Existing funnels indexed by step signature and by recipe provenance.
	 *
	 * Soft-deleted funnels are excluded on purpose: deleting a funnel must let
	 * the user create it again from its recipe.
	 *
	 * @return array {
	 *     @type array $by_signature signature => { id, name }
	 *     @type array $by_recipe    recipe_id => { id, name }
	 * }
	 */
	protected function get_existing_index() {
		global $wpdb;

		$index = array(
			'by_signature' => array(),
			'by_recipe'    => array(),
		);

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $index;
		}

		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			"SELECT id, name, steps, recipe_id
			FROM {$table_funnels}
			WHERE status != 'deleted'
			ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return $index;
		}

		foreach ( $rows as $row ) {
			$entry = array(
				'id'   => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'name' => isset( $row['name'] ) ? (string) $row['name'] : '',
			);
			if ( $entry['id'] <= 0 ) {
				continue;
			}

			$signature = Opti_Behavior_Funnel_Database::get_steps_signature( isset( $row['steps'] ) ? $row['steps'] : '' );
			if ( '' !== $signature && ! isset( $index['by_signature'][ $signature ] ) ) {
				$index['by_signature'][ $signature ] = $entry;
			}

			$recipe_id = isset( $row['recipe_id'] ) ? $this->sanitize_recipe_id( $row['recipe_id'] ) : '';
			if ( '' !== $recipe_id && ! isset( $index['by_recipe'][ $recipe_id ] ) ) {
				$index['by_recipe'][ $recipe_id ] = $entry;
			}
		}

		return $index;
	}

	/**
	 * The funnel that already covers this recipe, or null.
	 *
	 * Both keys are checked (spec.md §3.5): the step signature catches a funnel
	 * hand-built with the same journey, `recipe_id` catches the same recipe
	 * materialized earlier whose steps have since drifted (a slug changed, a
	 * filter altered a pattern) — without it, every re-scan after such a drift
	 * would offer to create the same funnel again.
	 *
	 * @param array  $steps     Generated steps.
	 * @param string $recipe_id Recipe id.
	 * @return array|null { id, name }
	 */
	protected function find_duplicate( array $steps, $recipe_id ) {
		$index = $this->get_existing_index();

		if ( isset( $index['by_recipe'][ $recipe_id ] ) ) {
			return $index['by_recipe'][ $recipe_id ];
		}

		$signature = Opti_Behavior_Funnel_Database::get_steps_signature( $steps );
		if ( '' !== $signature && isset( $index['by_signature'][ $signature ] ) ) {
			return $index['by_signature'][ $signature ];
		}

		return null;
	}

	/**
	 * Normalize a recipe id coming from the client, an option or a DB row.
	 *
	 * Mirrors the `sanitize_key()` + 64-char truncation persist_funnel() applies
	 * to the `recipe_id` column, so a lookup can never miss because of casing or
	 * length.
	 *
	 * @param mixed $recipe_id Raw id.
	 * @return string Sanitized id, or '' when unusable.
	 */
	protected function sanitize_recipe_id( $recipe_id ) {
		if ( ! is_string( $recipe_id ) || '' === $recipe_id ) {
			return '';
		}

		return substr( sanitize_key( $recipe_id ), 0, 64 );
	}
}
