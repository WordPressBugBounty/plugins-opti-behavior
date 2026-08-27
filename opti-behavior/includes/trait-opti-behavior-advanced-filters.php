<?php
/**
 * Shared Advanced Filters Trait
 *
 * Single source of truth for the advanced-filters machinery reused by BOTH the
 * FREE Analytics Dashboard (Opti_Behavior_Heatmap_Dashboard) and the Funnels
 * page (Opti_Behavior_Funnel_Page):
 *
 *  - sanitize_advanced_filters_from_request() — request parsing / allow-listing.
 *  - get_advanced_filters_allowed_fields()    — the 16 allow-listed field keys.
 *  - build_advanced_filters_sql()             — sanitized filters => WHERE + params.
 *  - build_traffic_channel_case_sql()         — SQL CASE for the traffic_channel field.
 *  - get_traffic_channel_domain_maps()        — known search/social domain maps.
 *
 * Extracted verbatim from class-opti-behavior-heatmap-dashboard.php and
 * trait-opti-behavior-ajax-handlers.php (Funnels PRO advanced filter — Task 3
 * "shared-trait extraction"). Bodies are byte-identical to the originals so the
 * Dashboard/Heatmap behaviour is unchanged; the Funnels page now reuses the very
 * same SQL builder (matching `s`/`v` aliases).
 *
 * @package opti-behavior
 * @since 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait: advanced-filters parsing + SQL building shared across pages.
 */
trait Opti_Behavior_Advanced_Filters_Trait {

	/**
	 * Parse + sanitize the `advanced_filters` request field into an
	 * allow-listed filters array.
	 *
	 * JSON-encoded object of allow-listed field => scalar value, e.g.
	 * '{"browser":"Chrome","duration_min":"30","traffic_channel":"Organic Search"}'.
	 * Unknown/non-allow-listed keys are silently dropped (defense in depth —
	 * build_advanced_filters_sql() re-checks the allow-list independently).
	 * Empty/missing input, invalid JSON, or a non-object JSON value all
	 * resolve to an empty array (i.e. "unfiltered").
	 *
	 * @since 1.0.4
	 * @param array $source Request superglobal to read from (e.g. $_POST).
	 * @return array Sanitized filters array, allow-listed keys only.
	 */
	private function sanitize_advanced_filters_from_request( $source ) {
		if ( ! is_array( $source ) || empty( $source['advanced_filters'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- unslashed explicitly below.
		$raw = wp_unslash( $source['advanced_filters'] );

		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = null;
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$allowed_fields = method_exists( $this, 'get_advanced_filters_allowed_fields' ) ? $this->get_advanced_filters_allowed_fields() : array();
		$numeric_fields = array( 'duration_min', 'duration_max', 'page_count_min', 'page_count_max' );
		// Fields that accept multiple values (OR semantics within the field).
		// A single-item array collapses to a scalar so every downstream
		// consumer keeps its original scalar contract.
		$multi_fields = array( 'browser', 'country', 'device_type', 'os', 'utm_campaign', 'utm_source', 'utm_medium' );

		$filters = array();
		foreach ( $decoded as $field => $value ) {
			if ( ! is_string( $field ) || ! in_array( $field, $allowed_fields, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( ! in_array( $field, $multi_fields, true ) ) {
					continue; // Only multi-capable fields accept arrays.
				}
				$clean = array();
				foreach ( $value as $item ) {
					if ( is_array( $item ) || is_object( $item ) ) {
						continue;
					}
					$sanitized_item = sanitize_text_field( (string) $item );
					if ( '' !== $sanitized_item && ! in_array( $sanitized_item, $clean, true ) ) {
						$clean[] = $sanitized_item;
					}
				}
				if ( empty( $clean ) ) {
					continue;
				}
				$filters[ $field ] = ( 1 === count( $clean ) ) ? $clean[0] : array_values( $clean );
				continue;
			}
			if ( is_object( $value ) ) {
				continue;
			}
			if ( null === $value || '' === trim( (string) $value ) ) {
				continue; // Treat empty/"not set" the same as absent.
			}

			if ( in_array( $field, $numeric_fields, true ) ) {
				if ( ! is_numeric( $value ) ) {
					continue;
				}
				$filters[ $field ] = (int) $value;
				continue;
			}

			$sanitized = sanitize_text_field( (string) $value );
			if ( '' === $sanitized ) {
				continue;
			}
			$filters[ $field ] = $sanitized;
		}

		return $filters;
	}

	/**
	 * Allow-listed FREE dashboard advanced-filter fields.
	 *
	 * @since 1.0.4
	 * @return string[]
	 */
	private function get_advanced_filters_allowed_fields() {
		return array(
			'browser',
			'country',
			'device_type',
			'os',
			'visitor_type',
			'duration_min',
			'duration_max',
			'page_count_min',
			'page_count_max',
			'entry_page',
			'exit_page',
			'referrer',
			'traffic_channel',
			'utm_campaign',
			'utm_source',
			'utm_medium',
		);
	}

	/**
	 * Centralized builder that turns a sanitized advanced-filters array into
	 * a SQL WHERE fragment + matching wpdb::prepare() params, so every
	 * aggregate query method reuses one source of truth instead of
	 * duplicating filter SQL ~13 times.
	 *
	 * Unknown/non-allow-listed keys are silently ignored (defense in depth —
	 * callers are also expected to allow-list before this point). Empty
	 * string/null values are treated as "not set" and skipped.
	 *
	 * Assumes the standard FREE dashboard query aliases: `s` for
	 * optibehavior_sessions and `v` for optibehavior_visitors (both already
	 * joined in every aggregate query this will be wired into in Task 3).
	 *
	 * @since 1.0.4
	 * @param array $filters Sanitized filters, allow-listed keys only.
	 * @return array{where: string, params: array} WHERE fragment (leading " AND (...)" or empty string) + params.
	 */
	private function build_advanced_filters_sql( array $filters ) {
		global $wpdb;

		if ( empty( $filters ) ) {
			return array(
				'where'  => '',
				'params' => array(),
			);
		}

		$allowed_fields   = $this->get_advanced_filters_allowed_fields();
		$duration_expr    = "(CASE WHEN s.duration > 0 THEN s.duration ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)) END)";
		$page_count_expr  = '(CASE WHEN COALESCE(s.page_views, 0) > 0 THEN COALESCE(s.page_views, 0) ELSE 1 END)';
		$conditions       = array();
		$params           = array();

		// Emits `expr = %s` for a scalar or `expr IN (%s, ...)` for an array so
		// multi-select filters (several browsers/countries/...) use OR semantics
		// within the field while distinct fields still AND together.
		$add_eq_or_in = function ( $expr, $value, $transform = null ) use ( &$conditions, &$params ) {
			$values = is_array( $value ) ? array_values( $value ) : array( $value );
			$values = array_map(
				function ( $v ) use ( $transform ) {
					$v = sanitize_text_field( $v );
					return $transform ? call_user_func( $transform, $v ) : $v;
				},
				$values
			);
			$values = array_values( array_unique( array_filter( $values, 'strlen' ) ) );
			if ( empty( $values ) ) {
				return;
			}
			if ( 1 === count( $values ) ) {
				$conditions[] = $expr . ' = %s';
				$params[]     = $values[0];
				return;
			}
			$conditions[] = $expr . ' IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
			foreach ( $values as $v ) {
				$params[] = $v;
			}
		};

		foreach ( $filters as $field => $value ) {
			if ( ! is_string( $field ) || ! in_array( $field, $allowed_fields, true ) ) {
				continue;
			}
			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) || ( is_array( $value ) && empty( $value ) ) ) {
				continue;
			}

			switch ( $field ) {
				case 'browser':
					$add_eq_or_in( 'v.browser', $value );
					break;

				case 'country':
					$add_eq_or_in( 'UPPER(TRIM(v.country))', $value, 'strtoupper' );
					break;

				case 'device_type':
					$add_eq_or_in( 'v.device_type', $value );
					break;

				case 'os':
					$add_eq_or_in( 'v.os', $value );
					break;

				case 'visitor_type':
					$visitor_type = strtolower( sanitize_text_field( $value ) );
					if ( 'new' === $visitor_type ) {
						$conditions[] = 'COALESCE(v.visit_count, 1) <= 1';
					} elseif ( 'returning' === $visitor_type ) {
						$conditions[] = 'COALESCE(v.visit_count, 1) > 1';
					}
					break;

				case 'duration_min':
					$conditions[] = $duration_expr . ' >= %d';
					$params[]     = absint( $value );
					break;

				case 'duration_max':
					$conditions[] = $duration_expr . ' <= %d';
					$params[]     = absint( $value );
					break;

				case 'page_count_min':
					$conditions[] = $page_count_expr . ' >= %d';
					$params[]     = absint( $value );
					break;

				case 'page_count_max':
					$conditions[] = $page_count_expr . ' <= %d';
					$params[]     = absint( $value );
					break;

				case 'entry_page':
					$conditions[] = 's.entry_page LIKE %s';
					$params[]     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
					break;

				case 'exit_page':
					$conditions[] = 's.exit_page LIKE %s';
					$params[]     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
					break;

				case 'referrer':
					$conditions[] = 's.referrer LIKE %s';
					$params[]     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
					break;

				case 'traffic_channel':
					$conditions[] = '(' . $this->build_traffic_channel_case_sql() . ') = %s';
					$params[]     = sanitize_text_field( $value );
					break;

				case 'utm_campaign':
					$add_eq_or_in( 's.utm_campaign', $value );
					break;

				case 'utm_source':
					$add_eq_or_in( 's.utm_source', $value );
					break;

				case 'utm_medium':
					$add_eq_or_in( 's.utm_medium', $value );
					break;
			}
		}

		if ( empty( $conditions ) ) {
			return array(
				'where'  => '',
				'params' => array(),
			);
		}

		return array(
			'where'  => ' AND (' . implode( ' AND ', $conditions ) . ')',
			'params' => $params,
		);
	}

	/**
	 * Build the SQL CASE expression that derives the same six Traffic
	 * Channel buckets as classify_traffic_channel(), but in pure SQL so it
	 * can be evaluated per-row inside a WHERE clause without pulling every
	 * session into PHP first.
	 *
	 * Mirrors classify_traffic_channel()'s rule order (known search/social
	 * domain substrings on s.referrer, then UTM keyword rules, then Direct)
	 * using LIKE matching against the raw referrer column — it does not
	 * strip the site's own host as "same-site" the way the PHP helper does,
	 * since that requires a runtime home_url() comparison unsuited to a
	 * cached SQL fragment; same-site referrers fall into Referral instead of
	 * Direct.
	 *
	 * @since 1.0.4
	 * @return string SQL CASE expression (no trailing alias).
	 */
	private function build_traffic_channel_case_sql() {
		list( $search_map, $social_map ) = $this->get_traffic_channel_domain_maps();

		/*
		 * This fragment is always appended to a larger query string that gets
		 * passed through $wpdb->prepare() by the caller (see
		 * build_advanced_filters_sql() docblock). $wpdb->prepare() treats a
		 * lone "%" immediately followed by s/d/f/F/i as a printf-style
		 * placeholder (e.g. the "%s" inside "%startpage.com%" or "%d" inside
		 * "%duckduckgo.com%"), which desyncs the placeholder/argument count
		 * and corrupts the whole query. Doubling the percent signs here
		 * ("%%needle%%") makes prepare() treat them as escaped literal "%"
		 * characters, so the executed SQL still has single "%" wildcards.
		 */
		$needle_likes = function ( $needle ) {
			if ( 0 === strpos( $needle, '=' ) ) {
				/*
				 * Exact-host needle: anchor on "://host" so the LIKE cannot
				 * match the needle inside a longer domain (mirrors the exact
				 * host comparison in classify_traffic_channel()).
				 */
				$host = esc_sql( substr( $needle, 1 ) );
				return "(s.referrer LIKE '%%://" . $host . "/%%'"
					. " OR s.referrer LIKE '%%://www." . $host . "/%%'"
					. " OR s.referrer LIKE '%%://" . $host . "'"
					. " OR s.referrer LIKE '%%://www." . $host . "')";
			}
			return "s.referrer LIKE '%%" . esc_sql( $needle ) . "%%'";
		};

		$search_likes = array();
		foreach ( array_keys( $search_map ) as $needle ) {
			$search_likes[] = $needle_likes( $needle );
		}
		$social_likes = array();
		foreach ( array_keys( $social_map ) as $needle ) {
			$social_likes[] = $needle_likes( $needle );
		}

		return "CASE
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' AND (" . implode( ' OR ', $search_likes ) . ") THEN 'Organic Search'
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' AND (" . implode( ' OR ', $social_likes ) . ") THEN 'Social Media'
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' THEN 'Referral'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('cpc','ppc','paid','paid_search') OR LOWER(TRIM(s.utm_source)) IN ('cpc','ppc','paid','paid_search') THEN 'Paid Ads'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('email','newsletter') OR LOWER(TRIM(s.utm_source)) IN ('email','newsletter') THEN 'Email'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('social','social_media') OR LOWER(TRIM(s.utm_source)) IN ('social','social_media') THEN 'Social Media'
				WHEN LOWER(TRIM(s.utm_medium)) = 'organic' OR LOWER(TRIM(s.utm_source)) = 'organic' THEN 'Organic Search'
				WHEN (s.utm_source IS NOT NULL AND s.utm_source <> '') OR (s.utm_medium IS NOT NULL AND s.utm_medium <> '') THEN 'Referral'
				ELSE 'Direct'
			END";
	}

	/**
	 * Shared known-domain maps used by both classify_traffic_channel() and
	 * the SQL-level traffic_channel filter expression built in
	 * build_advanced_filters_sql(), so the two stay in sync.
	 *
	 * @since 1.0.4
	 * @return array{0: array<string,string>, 1: array<string,string>} [ $search_map, $social_map ]
	 */
	private function get_traffic_channel_domain_maps() {
		/*
		 * Needle syntax:
		 *  - plain string  => substring match against the referrer/host
		 *    (safe only for needles too distinctive to appear inside other
		 *    domains, e.g. "duckduckgo.com", "google.").
		 *  - "=" prefix    => exact-host match ("=x.com" matches x.com /
		 *    www.x.com only). Required for short domains whose raw substring
		 *    would false-positive inside unrelated hosts ("x.com" is inside
		 *    gmx.com/fedex.com, "t.co" is inside every *t.com domain).
		 *
		 * 2026 landscape: AI assistants (ChatGPT, Perplexity, Claude,
		 * Copilot) are classified as Organic Search — they answer queries
		 * and refer clicks the same way search engines do. Gemini arrives
		 * via gemini.google.com (covered by "google.").
		 */
		$search_map = array(
			'google.' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo',
			'yahoo.' => 'Yahoo', 'yandex.' => 'Yandex', 'baidu.' => 'Baidu', 'naver.com' => 'Naver',
			'ecosia.org' => 'Ecosia', 'startpage.com' => 'Startpage', 'qwant.com' => 'Qwant',
			'search.brave.com' => 'Brave Search', 'kagi.com' => 'Kagi',
			'seznam.cz' => 'Seznam', 'sogou.com' => 'Sogou',
			// AI assistants / answer engines.
			'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT',
			'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude',
			'copilot.microsoft.com' => 'Copilot',
		);
		$social_map = array(
			'facebook.com' => 'Facebook', '=fb.com' => 'Facebook', '=fb.me' => 'Facebook',
			'instagram.com' => 'Instagram',
			'twitter.com' => 'Twitter/X', '=x.com' => 'Twitter/X', '=t.co' => 'Twitter/X',
			'linkedin.com' => 'LinkedIn', '=lnkd.in' => 'LinkedIn',
			'pinterest.' => 'Pinterest', '=pin.it' => 'Pinterest',
			'reddit.com' => 'Reddit', '=redd.it' => 'Reddit',
			'tiktok.com' => 'TikTok', 'snapchat.com' => 'Snapchat',
			'youtube.com' => 'YouTube', '=youtu.be' => 'YouTube',
			'threads.net' => 'Threads', 'threads.com' => 'Threads',
			'bsky.app' => 'Bluesky', 'mastodon.' => 'Mastodon',
			'whatsapp.com' => 'WhatsApp', '=wa.me' => 'WhatsApp',
			'telegram.org' => 'Telegram', '=t.me' => 'Telegram',
			'discord.com' => 'Discord', 'discord.gg' => 'Discord',
			'twitch.tv' => 'Twitch', '=vk.com' => 'VK', 'weibo.com' => 'Weibo',
			'quora.com' => 'Quora', 'nextdoor.com' => 'Nextdoor',
		);

		return array( $search_map, $social_map );
	}

	/**
	 * Shared renderer for the 4-column advanced-filters panel used by BOTH the
	 * FREE dashboard (render_advanced_filters_panel()) and the Funnels detail
	 * page (render_funnel_advanced_filters_panel()). One markup source => the
	 * two surfaces stay pixel-identical (same ids, classes, labels, dropdowns),
	 * styled by the shared dashboard_styles.css + filter-ui.css.
	 *
	 * Field element ids match the allow-listed keys consumed by
	 * sanitize_advanced_filters_from_request() / build_advanced_filters_sql().
	 * The panel is safe to reuse verbatim because each surface renders it once
	 * (no id collision within a page).
	 *
	 * @since 1.0.5
	 * @param array $args {
	 *     @type bool   $include_exit_page Render the Exit Page field (dashboard: true; funnel: false, spec §3-6). Default true.
	 *     @type bool   $locked            Disabled/blurred "PRO upsell" variant (funnel free). Default false.
	 *     @type string $upgrade_url       Upgrade CTA target for the locked overlay. Default ''.
	 * }
	 */
	protected function render_shared_advanced_filters_panel( array $args = array() ) {
		$include_exit_page = ! array_key_exists( 'include_exit_page', $args ) || (bool) $args['include_exit_page'];
		$locked            = ! empty( $args['locked'] );
		$upgrade_url       = isset( $args['upgrade_url'] ) ? (string) $args['upgrade_url'] : '';
		$disabled          = $locked ? ' disabled' : '';
		$panel_class       = 'advanced-filters-panel' . ( $locked ? ' is-locked' : '' );
		?>
		<div class="<?php echo esc_attr( $panel_class ); ?>" id="advanced-filters-panel" style="display: none;">
			<div class="advanced-filters-grid">
				<!-- Column 1: Visitor Attributes -->
				<div class="filter-column">
					<h3 class="filter-column-title"><?php esc_html_e( 'Visitor Attributes', 'opti-behavior' ); ?></h3>

					<div class="filter-group">
						<label for="filter-browser"><?php esc_html_e( 'Browser Name', 'opti-behavior' ); ?></label>
						<select id="filter-browser" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Browsers', 'opti-behavior' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="filter-country"><?php esc_html_e( 'Country', 'opti-behavior' ); ?></label>
						<select id="filter-country" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Countries', 'opti-behavior' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="filter-device"><?php esc_html_e( 'Device Type', 'opti-behavior' ); ?></label>
						<select id="filter-device" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Devices', 'opti-behavior' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="filter-os"><?php esc_html_e( 'Operating System', 'opti-behavior' ); ?></label>
						<select id="filter-os" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All OS', 'opti-behavior' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="filter-visitor-type"><?php esc_html_e( 'Visitor Type', 'opti-behavior' ); ?></label>
						<select id="filter-visitor-type" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Visitors', 'opti-behavior' ); ?></option>
							<option value="new"><?php esc_html_e( 'New Visitor', 'opti-behavior' ); ?></option>
							<option value="returning"><?php esc_html_e( 'Returning Visitor', 'opti-behavior' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Column 2: Session Attributes -->
				<div class="filter-column">
					<h3 class="filter-column-title"><?php esc_html_e( 'Session Attributes', 'opti-behavior' ); ?></h3>

					<div class="filter-group">
						<label for="filter-duration-min"><?php esc_html_e( 'Min Duration (seconds)', 'opti-behavior' ); ?></label>
						<input type="number" id="filter-duration-min" class="advanced-filter-input" min="0" placeholder="0"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>

					<div class="filter-group">
						<label for="filter-duration-max"><?php esc_html_e( 'Max Duration (seconds)', 'opti-behavior' ); ?></label>
						<input type="number" id="filter-duration-max" class="advanced-filter-input" min="0" placeholder="&#8734;"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>

					<div class="filter-group">
						<label for="filter-page-count-min"><?php esc_html_e( 'Min Page Count', 'opti-behavior' ); ?></label>
						<input type="number" id="filter-page-count-min" class="advanced-filter-input" min="1" placeholder="1"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>

					<div class="filter-group">
						<label for="filter-page-count-max"><?php esc_html_e( 'Max Page Count', 'opti-behavior' ); ?></label>
						<input type="number" id="filter-page-count-max" class="advanced-filter-input" min="1" placeholder="&#8734;"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>
				</div>

				<!-- Column 3: Pages & Traffic -->
				<div class="filter-column">
					<h3 class="filter-column-title"><?php esc_html_e( 'Pages & Traffic', 'opti-behavior' ); ?></h3>

					<div class="filter-group">
						<label for="filter-entry-page"><?php esc_html_e( 'Entry Page', 'opti-behavior' ); ?></label>
						<input type="text" id="filter-entry-page" class="advanced-filter-input" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g., /home', 'opti-behavior' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>

					<?php if ( $include_exit_page ) : ?>
					<div class="filter-group">
						<label for="filter-exit-page"><?php esc_html_e( 'Exit Page', 'opti-behavior' ); ?></label>
						<input type="text" id="filter-exit-page" class="advanced-filter-input" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g., /thank-you', 'opti-behavior' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>
					<?php endif; ?>

					<div class="filter-group">
						<label for="filter-referrer"><?php esc_html_e( 'Referrer URL', 'opti-behavior' ); ?></label>
						<input type="text" id="filter-referrer" class="advanced-filter-input" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g., google.com', 'opti-behavior' ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
					</div>

					<div class="filter-group">
						<label for="filter-traffic-channel"><?php esc_html_e( 'Traffic Channel', 'opti-behavior' ); ?></label>
						<select id="filter-traffic-channel" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Channels', 'opti-behavior' ); ?></option>
							<option value="Direct"><?php esc_html_e( 'Direct', 'opti-behavior' ); ?></option>
							<option value="Organic Search"><?php esc_html_e( 'Organic Search', 'opti-behavior' ); ?></option>
							<option value="Paid Ads"><?php esc_html_e( 'Paid Ads', 'opti-behavior' ); ?></option>
							<option value="Social Media"><?php esc_html_e( 'Social Media', 'opti-behavior' ); ?></option>
							<option value="Email"><?php esc_html_e( 'Email', 'opti-behavior' ); ?></option>
							<option value="Referral"><?php esc_html_e( 'Referral', 'opti-behavior' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Column 4: UTM Parameters -->
				<div class="filter-column">
					<h3 class="filter-column-title"><?php esc_html_e( 'UTM Parameters', 'opti-behavior' ); ?></h3>

					<div class="filter-group">
						<label for="filter-utm-campaign"><?php esc_html_e( 'UTM Campaign', 'opti-behavior' ); ?></label>
						<select id="filter-utm-campaign" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Campaigns', 'opti-behavior' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="filter-utm-source"><?php esc_html_e( 'UTM Source', 'opti-behavior' ); ?></label>
						<select id="filter-utm-source" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Sources', 'opti-behavior' ); ?></option>
						</select>
					</div>

					<div class="filter-group">
						<label for="filter-utm-medium"><?php esc_html_e( 'UTM Medium', 'opti-behavior' ); ?></label>
						<select id="filter-utm-medium" class="advanced-filter-select"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal ' disabled'. ?>>
							<option value=""><?php esc_html_e( 'All Mediums', 'opti-behavior' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<?php if ( $locked ) : ?>
				<div class="advanced-filters-locked-overlay">
					<div class="advanced-filters-locked-card">
						<span class="advanced-filters-locked-icon"><i data-lucide="lock"></i></span>
						<h3><?php esc_html_e( 'Advanced funnel filters are a PRO feature', 'opti-behavior' ); ?></h3>
						<p><?php esc_html_e( 'Segment this funnel by browser, country, device, traffic channel, UTM parameters and more.', 'opti-behavior' ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( $upgrade_url ); ?>">
							<?php esc_html_e( 'Upgrade to PRO', 'opti-behavior' ); ?>
						</a>
					</div>
				</div>
			<?php else : ?>
				<div class="advanced-filters-actions">
					<div class="advanced-filters-profiles">
						<select id="filter-profile-select" class="advanced-filter-select">
							<option value=""><?php esc_html_e( 'Saved filters…', 'opti-behavior' ); ?></option>
						</select>
						<button id="filter-profile-save" class="button" type="button">
							<span class="dashicons dashicons-plus-alt"></span><?php esc_html_e( 'Save as…', 'opti-behavior' ); ?>
						</button>
						<button id="filter-profile-edit" class="button" type="button" disabled>
							<span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Edit', 'opti-behavior' ); ?>
						</button>
					</div>
					<button id="apply-advanced-filters" class="button button-primary" type="button">
						<span class="dashicons dashicons-yes"></span>
						<?php esc_html_e( 'Apply Filters', 'opti-behavior' ); ?>
					</button>
					<button id="reset-advanced-filters" class="button" type="button">
						<span class="dashicons dashicons-undo"></span>
						<?php esc_html_e( 'Reset', 'opti-behavior' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
