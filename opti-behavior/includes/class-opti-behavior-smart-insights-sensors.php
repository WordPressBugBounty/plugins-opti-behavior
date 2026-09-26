<?php
/**
 * Smart Insights sensors ("How it works" strip).
 *
 * Shows the product loop on top of the Smart Insights screen: the collector
 * modules (sensors) feed Smart Insights, Smart Insights says what to fix, an
 * A/B test proves the fix. One pill per module with its state and what it
 * collected over the period.
 *
 * Version-skew contract (Free and Pro update independently):
 *   - every string, the markup and the validation live here, in Free;
 *   - Free builds all rows alone first. A Pro module is `locked` when its
 *     feature gate is closed, `needs_update` when it is open but no provider
 *     answered;
 *   - a newer Pro upgrades its rows through the
 *     `opti_behavior_smart_insights_sensors` filter. An older Pro never hooks
 *     it, an older Free never fires it: both pairings degrade silently;
 *   - whatever the filter returns is re-validated against a whitelist, so a
 *     faulty callback can never break the screen. No version number is compared.
 *
 * Nothing is recomputed: sessions come from Opti_Behavior_Stats_Repository,
 * insight counts from Opti_Behavior_Smart_Insights_Repository::count_insights()
 * with the exact arguments of the insight list ("Active" = new + viewed + in
 * progress, same date range, same spam scope, groups collapsed).
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights sensors.
 */
class Opti_Behavior_Smart_Insights_Sensors {

	const STATE_ACTIVE       = 'active';
	const STATE_NO_DATA      = 'no_data';
	const STATE_LOCKED       = 'locked';
	const STATE_NEEDS_UPDATE = 'needs_update';

	/**
	 * Sensor volumes cache lifetime. Insight counts are never cached: they are
	 * cheap and must match the list below the strip on every page load.
	 */
	const CACHE_TTL = 900;

	// "Fixed and confirmed": improved outcomes resolved in the last N days.
	const CONFIRMED_DAYS = 90;

	/**
	 * Transient prefix (covered by the `_transient_opti_behavior_%` sweep of uninstall.php).
	 */
	const CACHE_PREFIX = 'opti_behavior_si_sensors_';

	/**
	 * Sensor definitions: admin page slug, Pro feature gate, insight entity type.
	 *
	 * `feature` is empty for a Free module. `entity_type` is set only where an
	 * insight maps to exactly one module; a page / device / source insight is
	 * fed by several modules at once and is never attributed.
	 *
	 * @return array
	 */
	public static function get_definitions() {
		return array(
			'analytics'  => array(
				'slug'        => 'opti-behavior-analytics',
				'feature'     => '',
				'entity_type' => '',
			),
			'heatmaps'   => array(
				'slug'        => 'opti-behavior-heatmaps',
				'feature'     => '',
				'entity_type' => '',
			),
			'recordings' => array(
				'slug'        => 'opti-behavior-recordings',
				'feature'     => 'recordings',
				'entity_type' => '',
			),
			'funnels'    => array(
				'slug'        => 'opti-behavior-funnels',
				'feature'     => '',
				'entity_type' => 'funnel',
			),
			'journeys'   => array(
				'slug'        => 'opti-behavior-user-journey',
				'feature'     => 'user_journey',
				'entity_type' => '',
			),
			'errors'     => array(
				'slug'        => 'opti-behavior-errors',
				'feature'     => 'error_tracking',
				'entity_type' => 'error',
			),
			'forms'      => array(
				'slug'        => 'opti-behavior-form-analytics',
				'feature'     => 'form_analytics',
				'entity_type' => 'form',
			),
		);
	}

	/**
	 * Allowed states.
	 *
	 * @return array
	 */
	public static function get_states() {
		return array( self::STATE_ACTIVE, self::STATE_NO_DATA, self::STATE_LOCKED, self::STATE_NEEDS_UPDATE );
	}

	/**
	 * Allowed volume units (each one has a translated label in volume_text()).
	 *
	 * @return array
	 */
	public static function get_units() {
		return array( 'sessions', 'funnels', 'recordings', 'journeys', 'events', 'forms' );
	}

	/**
	 * Build the loop summary for a period.
	 *
	 * @param array $args {
	 *     Optional. `period`, `start_date`, `end_date` (Y-m-d, custom period),
	 *     `exclude_spam` (bool).
	 * }
	 * @return array {
	 *     `sensors` (id => row), `open`, `in_progress`, `resolved` (int),
	 *     `range` (`from`, `to`).
	 * }
	 */
	public static function get_summary( $args = array() ) {
		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'period'       => 'last30days',
				'start_date'   => '',
				'end_date'     => '',
				'exclude_spam' => true,
			)
		);

		$context = self::build_context( $args );
		$sensors = self::get_sensor_rows( $context );
		$sensors = self::add_insight_counts( $sensors, $context );

		return array(
			'sensors'     => $sensors,
			'open'        => self::count_insights( $context, array( 'status__in' => self::active_statuses() ) ),
			'in_progress' => self::count_insights( $context, array( 'status' => 'in_progress' ) ),
			'resolved'    => self::count_insights( $context, array( 'status__in' => array( 'resolved', 'auto_resolved' ) ) ),
			'confirmed'   => self::count_confirmed_fixes(),
			'range'       => array(
				'from' => $context['date_from'],
				'to'   => $context['date_to'],
			),
		);
	}

	/**
	 * Resolve the period into the context handed to every reader and to Pro.
	 *
	 * @param array $args Parsed args.
	 * @return array
	 */
	private static function build_context( $args ) {
		$period = sanitize_key( (string) $args['period'] );
		$today  = current_time( 'Y-m-d' );
		$range  = array(
			'start_date' => gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ),
			'end_date'   => $today,
			'start'      => gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ) . ' 00:00:00',
			'end'        => $today . ' 23:59:59',
		);

		if ( class_exists( 'Opti_Behavior_Stats_Date_Range' ) ) {
			$range = Opti_Behavior_Stats_Date_Range::resolve(
				'' !== $period ? $period : 'last30days',
				sanitize_text_field( (string) $args['start_date'] ),
				sanitize_text_field( (string) $args['end_date'] )
			);
		}

		return array(
			'period'       => $period,
			'date_from'    => (string) $range['start_date'],
			'date_to'      => (string) $range['end_date'],
			'start'        => (string) $range['start'],
			'end'          => (string) $range['end'],
			'exclude_spam' => ! empty( $args['exclude_spam'] ),
		);
	}

	/**
	 * Sensor rows (state + volume), cached for CACHE_TTL.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private static function get_sensor_rows( $context ) {
		$rows = self::build_free_rows( $context, false );

		// The gate states are part of the key: a licence change must not serve a
		// row cached under the previous state. So is the presence of a provider:
		// right after a Pro update the "update Pro" rows must not linger.
		$signature = array(
			'provider' => (bool) has_filter( 'opti_behavior_smart_insights_sensors' ),
		);
		foreach ( $rows as $id => $row ) {
			$signature[ $id ] = $row['state'];
		}
		$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( array( $context, $signature ) ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::validate_rows( $cached, $rows, true );
		}

		$rows = self::build_free_rows( $context, true );

		/**
		 * Let Pro describe its own sensors.
		 *
		 * Rows arrive with Free's verdict (`locked` or `needs_update` for a Pro
		 * module). A provider may only touch its own rows and must check its own
		 * feature gate. Allowed keys: `state` (one of get_states()), `volume`
		 * (int|null), `unit` (one of get_units()). Anything else is dropped.
		 *
		 * @param array $rows    Sensor rows keyed by sensor id.
		 * @param array $context `period`, `date_from`, `date_to`, `start`, `end`, `exclude_spam`.
		 */
		try {
			$filtered = apply_filters( 'opti_behavior_smart_insights_sensors', $rows, $context );
		} catch ( Throwable $e ) {
			$filtered = $rows;
		}

		$rows = self::validate_rows( $filtered, $rows );

		set_transient( $cache_key, $rows, self::CACHE_TTL );

		return $rows;
	}

	/**
	 * Drop every cached sensor row set.
	 *
	 * @return int Number of option rows deleted.
	 */
	public static function flush_cache() {
		if ( ! function_exists( 'opti_behavior_delete_transients_by_prefix' ) ) {
			return 0;
		}

		return (int) opti_behavior_delete_transients_by_prefix( array( self::CACHE_PREFIX ) );
	}

	/**
	 * Rows as Free sees them on its own.
	 *
	 * @param array $context      Context.
	 * @param bool  $with_volumes Whether to run the volume readers.
	 * @return array
	 */
	private static function build_free_rows( $context, $with_volumes ) {
		$rows = array();

		foreach ( self::get_definitions() as $id => $definition ) {
			$row = array(
				'state'  => self::STATE_NO_DATA,
				'volume' => null,
				'unit'   => '',
			);

			if ( '' !== $definition['feature'] ) {
				$row['state'] = self::pro_feature_open( $definition['feature'] ) ? self::STATE_NEEDS_UPDATE : self::STATE_LOCKED;
			}

			$rows[ $id ] = $row;
		}

		if ( ! $with_volumes ) {
			return $rows;
		}

		$sessions                   = self::count_sessions( $context );
		$rows['analytics']['volume'] = $sessions;
		$rows['analytics']['unit']   = 'sessions';
		$rows['analytics']['state']  = $sessions > 0 ? self::STATE_ACTIVE : self::STATE_NO_DATA;

		$rows['heatmaps']['state'] = self::has_heatmap_data( $context ) ? self::STATE_ACTIVE : self::STATE_NO_DATA;

		$funnels                  = self::count_active_funnels();
		$rows['funnels']['volume'] = $funnels;
		$rows['funnels']['unit']   = 'funnels';
		$rows['funnels']['state']  = $funnels > 0 ? self::STATE_ACTIVE : self::STATE_NO_DATA;

		return $rows;
	}

	/**
	 * Re-validate rows coming back from the filter or the cache.
	 *
	 * A row that fails validation falls back to Free's own row. Through the
	 * filter, a Free module can never be re-described and a closed Pro gate
	 * stays closed; from the cache, Free's own volumes are read back as stored.
	 *
	 * @param mixed $candidate  Rows to validate.
	 * @param array $fallback   Free's own rows.
	 * @param bool  $from_cache Whether $candidate is Free's own cached output.
	 * @return array
	 */
	public static function validate_rows( $candidate, $fallback, $from_cache = false ) {
		$definitions = self::get_definitions();
		$clean       = array();

		foreach ( $fallback as $id => $own ) {
			$is_pro = '' !== $definitions[ $id ]['feature'];
			$row    = ( is_array( $candidate ) && isset( $candidate[ $id ] ) ) ? $candidate[ $id ] : null;

			if ( ! self::rows_match_shape( $row ) ) {
				$row = $own;
			} elseif ( ! $is_pro && ( ! $from_cache || self::is_pro_state( $row['state'] ) ) ) {
				$row = $own;
			} elseif ( $is_pro && self::STATE_LOCKED === $own['state'] ) {
				$row = $own;
			}

			$volume = $row['volume'];
			$clean[ $id ] = array(
				'state'  => $row['state'],
				'volume' => ( null === $volume || '' === $volume ) ? null : absint( $volume ),
				'unit'   => in_array( $row['unit'], self::get_units(), true ) ? $row['unit'] : '',
			);

			if ( in_array( $clean[ $id ]['state'], array( self::STATE_LOCKED, self::STATE_NEEDS_UPDATE ), true ) ) {
				// No number without data behind it.
				$clean[ $id ]['volume'] = null;
				$clean[ $id ]['unit']   = '';
			}
		}

		return $clean;
	}

	/**
	 * Whether a row carries the three expected keys with sane types.
	 *
	 * @param mixed $row Row.
	 * @return bool
	 */
	private static function rows_match_shape( $row ) {
		if ( ! is_array( $row ) || ! isset( $row['state'] ) || ! array_key_exists( 'volume', $row ) || ! isset( $row['unit'] ) ) {
			return false;
		}
		if ( ! is_string( $row['state'] ) || ! in_array( $row['state'], self::get_states(), true ) ) {
			return false;
		}
		if ( ! is_string( $row['unit'] ) ) {
			return false;
		}
		if ( null !== $row['volume'] && ! is_numeric( $row['volume'] ) ) {
			return false;
		}

		return null === $row['volume'] || $row['volume'] >= 0;
	}

	/**
	 * States only a Pro module can be in.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	private static function is_pro_state( $state ) {
		return in_array( $state, array( self::STATE_LOCKED, self::STATE_NEEDS_UPDATE ), true );
	}

	/**
	 * Attach the open-insight count to the sensors an insight maps to 1:1.
	 *
	 * @param array $sensors Sensor rows.
	 * @param array $context Context.
	 * @return array
	 */
	private static function add_insight_counts( $sensors, $context ) {
		foreach ( self::get_definitions() as $id => $definition ) {
			$sensors[ $id ]['insights'] = null;

			if ( '' === $definition['entity_type'] || self::is_pro_state( $sensors[ $id ]['state'] ) ) {
				continue;
			}

			$sensors[ $id ]['insights'] = self::count_insights(
				$context,
				array(
					'status__in'  => self::active_statuses(),
					'entity_type' => $definition['entity_type'],
				)
			);
		}

		return $sensors;
	}

	/**
	 * The "Active" status group of the insight list.
	 *
	 * @return array
	 */
	private static function active_statuses() {
		return array( 'new', 'viewed', 'in_progress' );
	}

	/**
	 * Count insights with the arguments of the insight list.
	 *
	 * @param array $context Context.
	 * @param array $extra   Extra repository args.
	 * @return int
	 */
	/**
	 * Fixes the outcome evaluator measured as improved over the last
	 * CONFIRMED_DAYS days (what it already computes; nothing recomputed).
	 * Capped at the repository's 100-row outcome read.
	 *
	 * @return int
	 */
	private static function count_confirmed_fixes() {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Repository' ) ) {
			return 0;
		}
		try {
			$repository = new Opti_Behavior_Smart_Insights_Repository();
			if ( ! method_exists( $repository, 'get_outcomes' ) ) {
				return 0;
			}
			return count(
				(array) $repository->get_outcomes(
					array(
						'verdict'        => 'improved',
						'resolved_since' => gmdate( 'Y-m-d H:i:s', time() - self::CONFIRMED_DAYS * DAY_IN_SECONDS ),
						'limit'          => 100,
					)
				)
			);
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	private static function count_insights( $context, $extra ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Repository' ) ) {
			return 0;
		}

		try {
			// One repository for every count of the request: each new instance
			// re-probes the insights table columns (13 INFORMATION_SCHEMA reads).
			static $repository = null;
			if ( null === $repository ) {
				$repository = new Opti_Behavior_Smart_Insights_Repository();
			}
			if ( ! method_exists( $repository, 'count_insights' ) ) {
				return 0;
			}

			return absint(
				$repository->count_insights(
					array_merge(
						array(
							'date_from'       => $context['date_from'],
							'date_to'         => $context['date_to'],
							'spam_scope'      => $context['exclude_spam'] ? 'exclude_spam_1' : 'exclude_spam_0',
							'collapse_groups' => true,
						),
						$extra
					)
				)
			);
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Canonical session count for the period.
	 *
	 * @param array $context Context.
	 * @return int
	 */
	private static function count_sessions( $context ) {
		if ( ! class_exists( 'Opti_Behavior_Stats_Repository' ) || ! method_exists( 'Opti_Behavior_Stats_Repository', 'count_sessions_in_range' ) ) {
			return 0;
		}

		try {
			return absint(
				Opti_Behavior_Stats_Repository::count_sessions_in_range(
					$context['start'],
					$context['end'],
					array( 'exclude_spam' => $context['exclude_spam'] )
				)
			);
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Whether any heatmap data point landed in the period.
	 *
	 * Existence only, on purpose: the dated click totals of the Heatmaps screen
	 * carry page-merge and spam rules that must not be duplicated here.
	 *
	 * @param array $context Context.
	 * @return bool
	 */
	private static function has_heatmap_data( $context ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_heatmap_daily';
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Existence probe on a plugin table (name built from $wpdb->prefix); result cached by the caller's transient.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE day >= %s AND day <= %s LIMIT 1",
				$context['date_from'],
				$context['date_to']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return null !== $found;
	}

	/**
	 * Number of active funnels.
	 *
	 * @return int
	 */
	private static function count_active_funnels() {
		global $wpdb;

		$table = $wpdb->prefix . 'opti_behavior_funnels';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Row count on a plugin table (name built from $wpdb->prefix); result cached by the caller's transient.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				'active'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return absint( $count );
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe; result cached by the caller's transient.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Whether a Pro feature gate is open.
	 *
	 * Mirrors Opti_Behavior_Heatmap_Dashboard::pro_feature_pages_available(),
	 * per feature. Every symbol is probed first, so a missing, older or broken
	 * Pro reads as "closed".
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	private static function pro_feature_open( $feature ) {
		/**
		 * Short-circuit the Pro gate probe (tests, staging).
		 *
		 * @param bool|null $open    Null to run the real probe.
		 * @param string    $feature Feature key.
		 */
		$forced = apply_filters( 'opti_behavior_smart_insights_sensor_feature_open', null, $feature );
		if ( null !== $forced ) {
			return (bool) $forced;
		}

		if ( ! function_exists( 'opti_behavior_pro_active' ) || ! opti_behavior_pro_active() ) {
			return false;
		}
		if ( ! function_exists( 'opti_behavior_pro_validate_env' ) || ! opti_behavior_pro_validate_env() ) {
			return false;
		}
		if ( ! class_exists( 'Opti_Behavior_Pro_Feature_Guard' ) || ! method_exists( 'Opti_Behavior_Pro_Feature_Guard', 'can_access' ) ) {
			return false;
		}

		try {
			return (bool) Opti_Behavior_Pro_Feature_Guard::can_access( $feature );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Translated module name.
	 *
	 * @param string $id Sensor id.
	 * @return string
	 */
	public static function label( $id ) {
		$labels = array(
			'analytics'  => __( 'Analytics', 'opti-behavior' ),
			'heatmaps'   => __( 'Heatmaps', 'opti-behavior' ),
			'recordings' => __( 'Recordings', 'opti-behavior' ),
			'funnels'    => __( 'Funnels', 'opti-behavior' ),
			'journeys'   => __( 'User Journeys', 'opti-behavior' ),
			'errors'     => __( 'Errors Tracking', 'opti-behavior' ),
			'forms'      => __( 'Forms', 'opti-behavior' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * What Smart Insights cannot see while a Pro module is locked.
	 *
	 * @param string $id Sensor id.
	 * @return string
	 */
	private static function locked_text( $id ) {
		$texts = array(
			'recordings' => __( 'Pro — no session replay as evidence', 'opti-behavior' ),
			'journeys'   => __( 'Pro — visitor paths stay invisible', 'opti-behavior' ),
			'errors'     => __( 'Pro — JavaScript errors and rage clicks stay invisible', 'opti-behavior' ),
			'forms'      => __( 'Pro — form abandonment stays invisible', 'opti-behavior' ),
		);

		return isset( $texts[ $id ] ) ? $texts[ $id ] : __( 'Pro', 'opti-behavior' );
	}

	/**
	 * One-line status of a sensor.
	 *
	 * @param string $id  Sensor id.
	 * @param array  $row Sensor row.
	 * @return string
	 */
	public static function status_text( $id, $row ) {
		if ( self::STATE_LOCKED === $row['state'] ) {
			return self::locked_text( $id );
		}
		if ( self::STATE_NEEDS_UPDATE === $row['state'] ) {
			// The "update Pro" hint is printed once under the pills, not on each of them.
			return __( 'Active', 'opti-behavior' );
		}
		if ( self::STATE_NO_DATA === $row['state'] ) {
			return __( 'Active — no data for this period yet', 'opti-behavior' );
		}

		$parts  = array();
		$volume = self::volume_text( $row );
		if ( '' !== $volume ) {
			$parts[] = $volume;
		} else {
			$parts[] = __( 'Collecting data', 'opti-behavior' );
		}

		if ( isset( $row['insights'] ) && null !== $row['insights'] ) {
			$parts[] = $row['insights'] > 0
				? sprintf(
					/* translators: %s: number of open insights fed by this module. */
					_n( '%s insight', '%s insights', $row['insights'], 'opti-behavior' ),
					number_format_i18n( $row['insights'] )
				)
				: __( 'no insight yet', 'opti-behavior' );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Translated "N units" text, empty when the row carries no number.
	 *
	 * @param array $row Sensor row.
	 * @return string
	 */
	private static function volume_text( $row ) {
		if ( null === $row['volume'] ) {
			return '';
		}

		$n = (int) $row['volume'];
		$f = number_format_i18n( $n );

		switch ( $row['unit'] ) {
			case 'sessions':
				/* translators: %s: number of visitor sessions. */
				return sprintf( _n( '%s session', '%s sessions', $n, 'opti-behavior' ), $f );
			case 'funnels':
				/* translators: %s: number of funnels. */
				return sprintf( _n( '%s funnel', '%s funnels', $n, 'opti-behavior' ), $f );
			case 'recordings':
				/* translators: %s: number of session recordings. */
				return sprintf( _n( '%s recording', '%s recordings', $n, 'opti-behavior' ), $f );
			case 'journeys':
				/* translators: %s: number of visitor journeys. */
				return sprintf( _n( '%s journey', '%s journeys', $n, 'opti-behavior' ), $f );
			case 'events':
				/* translators: %s: number of error and friction events. */
				return sprintf( _n( '%s event', '%s events', $n, 'opti-behavior' ), $f );
			case 'forms':
				/* translators: %s: number of tracked forms. */
				return sprintf( _n( '%s form', '%s forms', $n, 'opti-behavior' ), $f );
		}

		return $f;
	}

	/**
	 * Print the strip.
	 *
	 * @param array $args See get_summary().
	 * @return void
	 */
	public static function render( $args = array() ) {
		try {
			$summary = self::get_summary( $args );
		} catch ( Throwable $e ) {
			return;
		}

		$definitions = self::get_definitions();
		?>
		<details class="ob-si-loop" open data-ob-si-loop>
			<summary class="ob-si-loop__summary">
				<span class="ob-si-loop__title"><?php esc_html_e( 'How it works', 'opti-behavior' ); ?></span>
				<span class="ob-si-loop__lede"><?php esc_html_e( 'Your modules collect. Smart Insights finds what to fix. An A/B test proves the fix.', 'opti-behavior' ); ?></span>
				<span class="ob-si-loop__dots" aria-hidden="true">
					<?php foreach ( $summary['sensors'] as $row ) : ?>
						<i class="ob-si-loop__dot is-<?php echo esc_attr( $row['state'] ); ?>"></i>
					<?php endforeach; ?>
				</span>
			</summary>

			<ol class="ob-si-loop__steps">
				<li class="ob-si-loop__step ob-si-loop__step--collect">
					<span class="ob-si-loop__step-name"><?php esc_html_e( '1 · Collect', 'opti-behavior' ); ?></span>
					<ul class="ob-si-loop__sensors">
						<?php foreach ( $summary['sensors'] as $id => $row ) : ?>
							<li>
								<a class="ob-si-loop__sensor is-<?php echo esc_attr( $row['state'] ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $definitions[ $id ]['slug'] ) ); ?>" data-sensor="<?php echo esc_attr( $id ); ?>" data-state="<?php echo esc_attr( $row['state'] ); ?>">
									<i class="ob-si-loop__dot is-<?php echo esc_attr( $row['state'] ); ?>" aria-hidden="true"></i>
									<span class="ob-si-loop__sensor-text">
										<strong><?php echo esc_html( self::label( $id ) ); ?></strong>
										<small><?php echo esc_html( self::status_text( $id, $row ) ); ?></small>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( in_array( self::STATE_NEEDS_UPDATE, wp_list_pluck( $summary['sensors'], 'state' ), true ) ) : ?>
						<p class="ob-si-loop__note">
							<i class="ob-si-loop__dot is-needs_update" aria-hidden="true"></i>
							<?php esc_html_e( 'Update Opti-Behavior Pro to see here what these modules collected.', 'opti-behavior' ); ?>
						</p>
					<?php endif; ?>
				</li>
				<li class="ob-si-loop__step is-current">
					<span class="ob-si-loop__step-name"><?php esc_html_e( '2 · Understand', 'opti-behavior' ); ?></span>
					<strong class="ob-si-loop__figure" data-ob-si-loop-open><?php echo esc_html( number_format_i18n( $summary['open'] ) ); ?></strong>
					<span class="ob-si-loop__caption"><?php echo esc_html( _n( 'open problem found by Smart Insights', 'open problems found by Smart Insights', $summary['open'], 'opti-behavior' ) ); ?></span>
				</li>
				<li class="ob-si-loop__step">
					<span class="ob-si-loop__step-name"><?php esc_html_e( '3 · Fix', 'opti-behavior' ); ?></span>
					<strong class="ob-si-loop__figure"><?php echo esc_html( number_format_i18n( $summary['in_progress'] ) ); ?></strong>
					<span class="ob-si-loop__caption">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of resolved insights, 2: number of fixes measured as improved. */
								__( 'in progress · %1$s resolved · %2$s fixed and confirmed', 'opti-behavior' ),
								number_format_i18n( $summary['resolved'] ),
								number_format_i18n( isset( $summary['confirmed'] ) ? (int) $summary['confirmed'] : 0 )
							)
						);
						?>
					</span>
				</li>
				<li class="ob-si-loop__step">
					<span class="ob-si-loop__step-name"><?php esc_html_e( '4 · Prove', 'opti-behavior' ); ?></span>
					<a class="ob-si-loop__link" href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-ab-testing' ) ); ?>"><?php esc_html_e( 'A/B Testing', 'opti-behavior' ); ?></a>
					<span class="ob-si-loop__caption"><?php esc_html_e( 'Test the fix, keep what wins', 'opti-behavior' ); ?></span>
				</li>
			</ol>
		</details>
		<?php
	}
}
