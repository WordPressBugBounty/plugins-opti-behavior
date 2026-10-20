<?php
/**
 * "Reports" screen (OPT-1342, design OPT-1338 `qa/design-opt-1338/reports-screen.html`).
 *
 * One form sent by GET to the report engine (`admin-post.php?action=
 * opti_behavior_report`, nonce of Opti_Behavior_Report) in a new tab: report of
 * the whole site or of one page, period (7 / 30 / 90 days or two dates),
 * version short / full and the pages printed in annex. Nothing is computed
 * here: the engine and its providers build the document.
 *
 * Pro answers the filter `opti_behavior_reports_screen_pro( null, $args )` with
 * `{ pages: [ { page_id, title, url, sessions } ] }` (the Page X-Ray pages of
 * the period, most visited first). No answer = « One page » and « Full » are
 * locked with one sober line, no notice, no banner (wordpress.org).
 *
 * Also prints the « Site report » link of the Traffic and Smart Insights
 * headers (`render_header_link()`), which carries the period of the screen.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports screen (Free).
 */
class Opti_Behavior_Reports_Screen {

	/** Admin page slug. */
	const SLUG = 'opti-behavior-reports';

	/** Quick periods of the screen => days. */
	const QUICK_PERIODS = array(
		'last7days'  => 7,
		'last30days' => 30,
		'last90days' => 90,
	);

	/** Pages offered as annex check rows, at most. */
	const ANNEX_ROWS = 12;

	/** Annex pages checked when the screen opens. */
	const ANNEX_DEFAULT = 3;

	/** Pages of the page select, at most. */
	const PAGE_OPTIONS = 50;

	/** admin-post action + nonce of the "Made with Opti-Behavior" box (OPT-1348). */
	const CREDIT_ACTION = 'opti_behavior_report_credit';

	/** AJAX action + nonce of the annex page search (OPT-1421). */
	const SEARCH_ACTION = 'opti_behavior_reports_search';

	/** Results of one annex search, at most. */
	const SEARCH_RESULTS = 12;

	/** Visited pages given to the annex search script, at most. */
	const SEARCH_PAGES = 500;

	/**
	 * Hooks registered once.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Register the hooks (admin only). Called once from Opti-Behavior.php.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$hooked || ! is_admin() ) {
			return;
		}
		self::$hooked = true;
		// After the dashboard menu (10) and before the Pro pages (13).
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_action( 'admin_post_' . self::CREDIT_ACTION, array( __CLASS__, 'handle_credit_save' ) );
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, array( __CLASS__, 'ajax_search' ) );
	}

	/**
	 * Annex search (OPT-1421): published pages / posts / products of the site
	 * whose title or address matches, visited or not. manage_options + nonce.
	 * Visits are added by the screen script from the pages Pro gave.
	 *
	 * @return void
	 */
	public static function ajax_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ), 403 );
		}
		check_ajax_referer( self::SEARCH_ACTION, 'nonce' );
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		wp_send_json_success( array( 'posts' => self::search_posts( $q ) ) );
	}

	/**
	 * Published posts of every public type (attachments left out) for a typed
	 * text: an address of this site first (url_to_postid, the front page for
	 * "/"), then the WordPress search on titles and content.
	 *
	 * @param string $q Typed text.
	 * @return array List of { post_id, title, path, kind }.
	 */
	public static function search_posts( $q ) {
		$q   = trim( (string) $q );
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
		if ( $len < 2 && '/' !== $q ) {
			return array();
		}
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$ids   = array();
		if ( '/' === $q[0] || preg_match( '#^https?://#i', $q ) ) {
			$path = (string) wp_parse_url( $q, PHP_URL_PATH );
			// A path of a site in a folder (/wordpress/…) is resolved as it is.
			$id = '' === trim( $path, '/' ) ? (int) get_option( 'page_on_front' ) : (int) url_to_postid( $path );
			if ( $id <= 0 && '' !== trim( $path, '/' ) ) {
				$id = (int) url_to_postid( home_url( $path ) );
			}
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			// The last part of the address is searched as words too.
			$slug = sanitize_title( basename( $path ) );
			$q    = '' !== $slug ? str_replace( '-', ' ', $slug ) : '';
		}
		if ( '' !== $q && $types ) {
			$found = get_posts(
				array(
					's'                => $q,
					'post_type'        => $types,
					'post_status'      => 'publish',
					'posts_per_page'   => self::SEARCH_RESULTS,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', (array) $found ) );
		}
		$out = array();
		foreach ( array_slice( array_values( array_unique( $ids ) ), 0, self::SEARCH_RESULTS ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, $types, true ) || post_password_required( $post ) ) {
				continue;
			}
			$type  = get_post_type_object( $post->post_type );
			$link  = (string) get_permalink( $post );
			$path  = (string) wp_parse_url( $link, PHP_URL_PATH );
			$title = trim( wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ) );
			$out[] = array(
				'post_id' => (int) $post->ID,
				'title'   => '' !== $title ? $title : ( '' !== $path ? $path : '/' ),
				'path'    => '' !== $path ? $path : '/',
				'kind'    => $type && isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : (string) $post->post_type,
			);
		}

		return $out;
	}

	/**
	 * Save of the "Made with Opti-Behavior" box (OPT-1348): manage_options +
	 * nonce, '1' only when the box came ticked, else '0'. Not autoloaded.
	 *
	 * @return void
	 */
	public static function handle_credit_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CREDIT_ACTION );
		$on = isset( $_POST['credit'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['credit'] ) );
		self::save_credit( $on );
		wp_safe_redirect( add_query_arg( 'credit_saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) . '#ob-report-credit' );
		exit;
	}

	/**
	 * Store the choice ('1' / '0', never autoloaded).
	 *
	 * @param bool $on Box ticked.
	 * @return void
	 */
	public static function save_credit( $on ) {
		$value = $on ? '1' : '0';
		if ( false === get_option( Opti_Behavior_Report::CREDIT_OPTION, false ) ) {
			add_option( Opti_Behavior_Report::CREDIT_OPTION, $value, '', false );
			return;
		}
		update_option( Opti_Behavior_Report::CREDIT_OPTION, $value, false );
	}

	/**
	 * The box under the report form: its own POST form (the report form is a
	 * GET that opens a new tab). Never ticked unless the user ticked it.
	 *
	 * @return void
	 */
	public static function render_credit_setting() {
		$saved = isset( $_GET['credit_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only.
		// Pro shows it only in "Agency mode" (OPT-1594); printed hidden so its switch can show it.
		$shown = (bool) apply_filters( 'opti_behavior_reports_show_credit', true );
		?>
		<form class="ob-reports__credit" id="ob-report-credit" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CREDIT_ACTION ); ?>">
			<?php wp_nonce_field( self::CREDIT_ACTION ); ?>
			<label><input type="checkbox" name="credit" value="1" <?php checked( Opti_Behavior_Report::credit_enabled() ); ?>> <?php esc_html_e( 'Add “Made with Opti-Behavior” at the bottom of my reports', 'opti-behavior' ); ?></label>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save', 'opti-behavior' ); ?></button>
			<?php if ( $saved ) : ?>
				<span class="ob-reports__credit-saved" role="status"><?php esc_html_e( 'Saved.', 'opti-behavior' ); ?></span>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * "For a client" under the report form (OPT-1346, design OPT-1345). Pro
	 * prints its agency options through the action
	 * `opti_behavior_reports_screen_agency( $state )` when it answered the
	 * screen (`$pro` not null); otherwise one locked tile with the sober line,
	 * no link, no banner.
	 *
	 * @param array      $state Screen state.
	 * @param array|null $pro   Answer of the Pro filter.
	 * @return void
	 */
	public static function render_agency( $state, $pro ) {
		if ( null !== $pro && has_action( 'opti_behavior_reports_screen_agency' ) ) {
			do_action( 'opti_behavior_reports_screen_agency', $state );
			return;
		}
		$lock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
		$name = '<span class="ob-reports__name">Opti-Behavior Pro</span>';
		?>
		<div class="ob-reports ob-reports--agency" id="ob-report-agency">
			<div class="ob-reports__form">
				<div class="ob-reports__row">
					<div class="ob-reports__q"><b><?php esc_html_e( 'For a client', 'opti-behavior' ); ?></b><span><?php esc_html_e( 'Your client’s name, your notes and your brand on the report.', 'opti-behavior' ); ?></span></div>
					<div class="ob-reports__locked">
						<span class="ob-reports__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
						<div><strong><?php esc_html_e( 'Agency options', 'opti-behavior' ); ?></strong>
							<?php /* translators: %s: product name "Opti-Behavior Pro". */ ?>
							<span class="ob-reports__lock"><?php echo $lock . sprintf( esc_html__( 'Client name, your notes, your logo and colour, hidden sections: %s', 'opti-behavior' ), $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG + escaped sentence + static product name. ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Menu row under "What to fix" (placed by the dashboard's group_submenu_items()).
	 *
	 * @return void
	 */
	public static function add_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Lucide "file-text" icon (20x20), like the other rows.
		$icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></span>';
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Reports', 'opti-behavior' ),
			$icon . __( 'Reports', 'opti-behavior' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Styles + script of the screen; the small header-link script on Traffic
	 * and Smart Insights. Version = file time.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$hook_suffix = (string) $hook_suffix;
		$dir         = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/';
		$ver         = function ( $rel ) use ( $dir ) {
			return OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . (int) @filemtime( $dir . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file only drops the cache buster.
		};
		if ( false !== strpos( $hook_suffix, self::SLUG ) ) {
			wp_enqueue_style( 'opti-behavior-dashboard-styles', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/dashboard_styles.css', array(), $ver( 'css/dashboard_styles.css' ) );
			wp_enqueue_style( 'opti-behavior-reports', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/reports.css', array( 'opti-behavior-dashboard-styles' ), $ver( 'css/reports.css' ) );
			wp_enqueue_script( 'opti-behavior-reports', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/reports.js', array(), $ver( 'js/reports.js' ), true );
			wp_localize_script( 'opti-behavior-reports', 'optiBehaviorReports', self::client_config() );
			return;
		}
		if ( false !== strpos( $hook_suffix, 'opti-behavior-analytics' ) || false !== strpos( $hook_suffix, 'opti-behavior-smart-insights' ) ) {
			wp_enqueue_script( 'opti-behavior-reports-link', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/reports-link.js', array(), $ver( 'js/reports-link.js' ), true );
		}
	}

	/**
	 * Data of the screen script: site day, texts.
	 *
	 * @return array
	 */
	public static function client_config() {
		return array(
			'today'      => current_time( 'Y-m-d' ),
			'maxAnnex'   => Opti_Behavior_Report::MAX_ANNEX_PAGES,
			/* translators: 1: first day of the period, 2: last day, 3: first day of the period before, 4: its last day. */
			'hint'       => __( '%1$s → %2$s, compared with %3$s → %4$s.', 'opti-behavior' ),
			'spamOut'    => __( 'Spam visits are left out.', 'opti-behavior' ),
			'spamIn'     => __( 'Spam visits are counted.', 'opti-behavior' ),
			/* translators: 1: number of pages chosen, 2: most pages allowed. */
			'count'      => __( '%1$d of %2$d chosen', 'opti-behavior' ),
			'generating' => __( 'Generating…', 'opti-behavior' ),
			'buildNote'  => __( 'Building the report in a new tab. A full report with page reports takes up to 30 seconds.', 'opti-behavior' ),
			// Annex search (OPT-1421).
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'search'     => array(
				'action'    => self::SEARCH_ACTION,
				'nonce'     => wp_create_nonce( self::SEARCH_ACTION ),
				/* translators: %s: number of visits. */
				'visit1'    => _n( '%s visit', '%s visits', 1, 'opti-behavior' ),
				/* translators: %s: number of visits. */
				'visitN'    => _n( '%s visit', '%s visits', 2, 'opti-behavior' ),
				'noVisits'  => __( 'No visits in this period', 'opti-behavior' ),
				'searching' => __( 'Searching…', 'opti-behavior' ),
				'none'      => __( 'No published page matches.', 'opti-behavior' ),
				'failed'    => __( 'The search did not answer. Try again.', 'opti-behavior' ),
				'chosen'    => __( 'Chosen', 'opti-behavior' ),
				/* translators: %d: most pages in annex (10). */
				'full'      => __( '%d pages at most: remove one to add another.', 'opti-behavior' ),
				/* translators: %s: page name. */
				'remove'    => __( 'Remove %s', 'opti-behavior' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------- */

	/**
	 * Period, spam choice, type and page of the request (links of the Traffic,
	 * Smart Insights and Page X-Ray screens), cleaned by the report engine.
	 *
	 * @param array $raw Raw query arguments, unslashed.
	 * @return array { period ('' = dates), from, to, exclude_spam, type, page_id }.
	 */
	public static function request_state( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$query = array(
			'type'         => 'site',
			'exclude_spam' => isset( $raw['exclude_spam'] ) ? (string) $raw['exclude_spam'] : '1',
		);
		foreach ( array( 'period', 'from', 'to' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$query[ $key ] = (string) $raw[ $key ];
			}
		}
		if ( ! isset( $query['period'] ) && ! isset( $query['from'] ) ) {
			$query['period'] = 'last30days';
		}
		$args = Opti_Behavior_Report::clean_args( $query );
		if ( is_wp_error( $args ) ) {
			$args = Opti_Behavior_Report::clean_args( array( 'type' => 'site' ) );
		}
		$period = isset( self::QUICK_PERIODS[ $args['period'] ] ) ? $args['period'] : '';

		return array(
			'period'       => $period,
			'from'         => $args['from'],
			'to'           => $args['to'],
			'exclude_spam' => (bool) $args['exclude_spam'],
			'type'         => isset( $raw['type'] ) && 'page' === $raw['type'] ? 'page' : 'site',
			'page_id'      => isset( $raw['page_id'] ) && is_scalar( $raw['page_id'] ) ? max( 0, (int) $raw['page_id'] ) : 0,
		);
	}

	/**
	 * What Pro offers on this screen, or null (Free alone, Pro locked or old).
	 *
	 * @param array $state request_state().
	 * @return array|null { pages: [ { page_id, title, path, sessions } ] } most visited first.
	 */
	public static function pro_data( $state ) {
		$args = array(
			'type'         => 'site',
			'period'       => $state['period'],
			'from'         => $state['from'],
			'to'           => $state['to'],
			'exclude_spam' => $state['exclude_spam'],
		);
		try {
			/**
			 * Pro part of the Reports screen.
			 *
			 * @param null|array $pro  Null = locked (Free alone).
			 * @param array      $args Period of the screen (`period` or `from` / `to`, `exclude_spam`).
			 */
			$pro = apply_filters( 'opti_behavior_reports_screen_pro', null, $args );
		} catch ( Throwable $e ) {
			$pro = null;
		}
		if ( ! is_array( $pro ) ) {
			return null;
		}

		return array( 'pages' => self::clean_pages( isset( $pro['pages'] ) ? $pro['pages'] : array() ) );
	}

	/**
	 * Page rows given by Pro: page id, title without " – site name", path
	 * without domain, visits; most visited first, then page id.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array
	 */
	public static function clean_pages( $rows ) {
		$out  = array();
		$seen = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['page_id'] ) ) {
				continue;
			}
			$id = absint( $row['page_id'] );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$url         = isset( $row['url'] ) ? (string) $row['url'] : '';
			$path        = (string) wp_parse_url( $url, PHP_URL_PATH );
			$title       = isset( $row['title'] ) ? wp_strip_all_tags( (string) $row['title'] ) : '';
			if ( class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) ) {
				$title = Opti_Behavior_Smart_Insights_Recommendations::strip_site_suffix( $title );
			}
			$out[] = array(
				'page_id'  => $id,
				'title'    => '' !== trim( $title ) ? trim( $title ) : ( '' !== $path ? $path : '/' ),
				'path'     => '' !== $path ? $path : '/',
				'sessions' => isset( $row['sessions'] ) ? max( 0, (int) $row['sessions'] ) : 0,
				'post_id'  => isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['sessions'] === $a['sessions'] ? $a['page_id'] <=> $b['page_id'] : $b['sessions'] <=> $a['sessions'];
			}
		);

		return $out;
	}

	/**
	 * Visited pages of the period for the annex search script (OPT-1421):
	 * short keys, most visited first, at most SEARCH_PAGES.
	 *
	 * @param array $pages clean_pages() rows.
	 * @return array List of { id, post, t, p, v }.
	 */
	public static function search_pages( $pages ) {
		$out = array();
		foreach ( array_slice( is_array( $pages ) ? $pages : array(), 0, self::SEARCH_PAGES ) as $row ) {
			$out[] = array(
				'id'   => (int) $row['page_id'],
				'post' => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
				't'    => (string) $row['title'],
				'p'    => (string) $row['path'],
				'v'    => (int) $row['sessions'],
			);
		}

		return $out;
	}

	/**
	 * Whether the site recorded at least one visit (one indexed read).
	 *
	 * @return bool
	 */
	public static function has_visits() {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from the prefix; one row, no user input.
		$found = $wpdb->get_var( "SELECT 1 FROM `{$table}` LIMIT 1" );

		return null !== $found;
	}

	/**
	 * Address of the Reports screen for a period of another screen. A named
	 * period the report engine knows is kept; any other one travels as dates.
	 *
	 * @param string $period       Period key of the screen.
	 * @param string $from         First day (Y-m-d) of a custom period.
	 * @param string $to           Last day (Y-m-d) of a custom period.
	 * @param bool   $exclude_spam Spam visits left out.
	 * @return string Raw address (escape with esc_url()).
	 */
	public static function screen_url( $period = '', $from = '', $to = '', $exclude_spam = true ) {
		$query  = array( 'page' => self::SLUG );
		$period = sanitize_key( (string) $period );
		if ( isset( Opti_Behavior_Report::PERIODS[ $period ] ) ) {
			$query['period'] = $period;
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
			$query['from'] = $from;
			$query['to']   = $to;
		}
		if ( ! $exclude_spam ) {
			$query['exclude_spam'] = '0';
		}

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Header link of Traffic and Smart Insights
	 * ------------------------------------------------------------------- */

	/**
	 * « Site report » link of a screen header. The script reports-link.js
	 * replaces the period of the address with the one shown on the screen at
	 * the moment of the click.
	 *
	 * @param string $screen       traffic | insights.
	 * @param string $period       Period key shown.
	 * @param string $from         First day shown (Y-m-d) or ''.
	 * @param string $to           Last day shown (Y-m-d) or ''.
	 * @param bool   $exclude_spam Spam visits left out.
	 * @return void
	 */
	public static function render_header_link( $screen, $period = '', $from = '', $to = '', $exclude_spam = true ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<a class="refresh-btn ob-site-report-link" href="<?php echo esc_url( self::screen_url( $period, $from, $to, $exclude_spam ) ); ?>" data-ob-site-report="<?php echo esc_attr( 'insights' === $screen ? 'insights' : 'traffic' ); ?>" title="<?php esc_attr_e( 'Printable report of the whole site for this period', 'opti-behavior' ); ?>">
			<i data-lucide="file-text" aria-hidden="true"></i>
			<span><?php esc_html_e( 'Site report', 'opti-behavior' ); ?></span>
		</a>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	/**
	 * Print the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state  = self::request_state( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state, every key cleaned by request_state().
		$pro    = self::pro_data( $state );
		$pages  = null !== $pro ? $pro['pages'] : array();
		$nodata = ! self::has_visits();
		$has_pg = ! empty( $pages );
		$type   = 'page' === $state['type'] && null !== $pro && $has_pg ? 'page' : 'site';
		$lock   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
		$name   = '<span class="ob-reports__name">Opti-Behavior Pro</span>';
		?>
		<div class="wrap opti-behavior-dashboard-page ob-reports-page">
			<div class="dashboard-header">
				<div class="dashboard-title-section">
					<?php Opti_Behavior_Admin_Brand::render_mark(); ?>
					<div class="dashboard-title-text">
						<?php Opti_Behavior_Admin_Brand::render_name(); ?>
						<h1 class="dashboard-title"><?php esc_html_e( 'Reports', 'opti-behavior' ); ?></h1>
						<p class="dashboard-subtitle"><?php esc_html_e( 'A printable A4 report of your site or of one page: open it, print it or save it as PDF.', 'opti-behavior' ); ?></p>
					</div>
				</div>
			</div>

			<form class="ob-reports" method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" data-ob-reports data-pro="<?php echo null !== $pro ? '1' : '0'; ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Opti_Behavior_Report::ACTION ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Opti_Behavior_Report::NONCE ) ); ?>">
				<input type="hidden" name="exclude_spam" value="<?php echo $state['exclude_spam'] ? '1' : '0'; ?>">
				<div class="ob-reports__form">
					<?php if ( $nodata ) : ?>
						<p class="ob-reports__empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span><b><?php esc_html_e( 'No visit recorded yet.', 'opti-behavior' ); ?></b> <?php esc_html_e( 'A report needs at least one day of visits. Come back tomorrow: tracking is on.', 'opti-behavior' ); ?></span></p>
					<?php endif; ?>

					<fieldset class="ob-reports__row">
						<legend class="screen-reader-text"><?php esc_html_e( 'Report of', 'opti-behavior' ); ?></legend>
						<div class="ob-reports__q" aria-hidden="true"><b><span class="ob-reports__step">1</span><?php esc_html_e( 'Report of', 'opti-behavior' ); ?></b><span><?php esc_html_e( 'The whole site, or one page in depth.', 'opti-behavior' ); ?></span></div>
						<div class="ob-reports__choices">
							<label class="ob-reports__choice">
								<input type="radio" name="type" value="site" data-ob-type <?php checked( 'site', $type ); ?>>
								<span class="ob-reports__card">
									<span class="ob-reports__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span>
									<span><strong><?php esc_html_e( 'The whole site', 'opti-behavior' ); ?></strong><p><?php esc_html_e( 'Traffic, sources, devices, and the issues that cost the most visits.', 'opti-behavior' ); ?></p></span>
									<span class="ob-reports__dot" aria-hidden="true"></span>
								</span>
							</label>
							<label class="ob-reports__choice<?php echo null === $pro || ! $has_pg ? ' is-locked' : ''; ?>">
								<input type="radio" name="type" value="page" data-ob-type <?php checked( 'page', $type ); ?> <?php disabled( null === $pro || ! $has_pg ); ?>>
								<span class="ob-reports__card">
									<span class="ob-reports__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><circle cx="11" cy="14" r="2.5"/><path d="m13 16 2 2"/></svg></span>
									<span><strong><?php esc_html_e( 'One page', 'opti-behavior' ); ?></strong><p><?php esc_html_e( 'The Page X-Ray of one page: score, problems, action plan, click map.', 'opti-behavior' ); ?></p>
										<?php if ( null === $pro ) : ?>
											<?php /* translators: %s: product name "Opti-Behavior Pro". */ ?>
											<span class="ob-reports__lock"><?php echo $lock . sprintf( esc_html__( 'Report of one page: %s', 'opti-behavior' ), $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG + escaped sentence + static product name. ?></span>
										<?php elseif ( ! $has_pg ) : ?>
											<span class="ob-reports__lock"><?php esc_html_e( 'No page has visits in this period yet.', 'opti-behavior' ); ?></span>
										<?php endif; ?>
									</span>
									<span class="ob-reports__dot" aria-hidden="true"></span>
								</span>
							</label>
						</div>
					</fieldset>

					<?php if ( null !== $pro && $has_pg ) : ?>
						<div class="ob-reports__row" data-ob-show="page" <?php echo 'page' === $type ? '' : 'hidden'; ?>>
							<div class="ob-reports__q"><b><span class="ob-reports__step" aria-hidden="true">2</span><label for="ob-reports-page"><?php esc_html_e( 'Page', 'opti-behavior' ); ?></label></b><span><?php esc_html_e( 'The pages of Page X-Ray, the most visited first.', 'opti-behavior' ); ?></span></div>
							<div>
								<select id="ob-reports-page" name="page_id">
									<?php foreach ( array_slice( $pages, 0, self::PAGE_OPTIONS ) as $row ) : ?>
										<?php /* translators: 1: page title, 2: page path, 3: number of visits. */ ?>
										<option value="<?php echo esc_attr( $row['page_id'] ); ?>" <?php selected( $state['page_id'], $row['page_id'] ); ?>><?php echo esc_html( sprintf( _n( '%1$s — %2$s · %3$s visit', '%1$s — %2$s · %3$s visits', $row['sessions'], 'opti-behavior' ), $row['title'], $row['path'], number_format_i18n( $row['sessions'] ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					<?php endif; ?>

					<fieldset class="ob-reports__row">
						<legend class="screen-reader-text"><?php esc_html_e( 'Period', 'opti-behavior' ); ?></legend>
						<div class="ob-reports__q" aria-hidden="true"><b><span class="ob-reports__step">2</span><?php esc_html_e( 'Period', 'opti-behavior' ); ?></b><span><?php esc_html_e( 'Compared with the same number of days just before.', 'opti-behavior' ); ?></span></div>
						<div>
							<div class="ob-reports__period">
								<div class="ob-reports__pills" role="radiogroup" aria-label="<?php esc_attr_e( 'Quick period', 'opti-behavior' ); ?>">
									<?php
									$pills = array(
										'last7days'  => __( '7 days', 'opti-behavior' ),
										'last30days' => __( '30 days', 'opti-behavior' ),
										'last90days' => __( '90 days', 'opti-behavior' ),
									);
									foreach ( $pills as $key => $label ) :
										?>
										<label><input type="radio" name="period" value="<?php echo esc_attr( $key ); ?>" data-days="<?php echo esc_attr( self::QUICK_PERIODS[ $key ] ); ?>" <?php checked( $state['period'], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label>
									<?php endforeach; ?>
								</div>
								<div class="ob-reports__dates">
									<label><?php esc_html_e( 'From', 'opti-behavior' ); ?><input type="date" name="from" value="<?php echo esc_attr( $state['from'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" data-ob-from></label>
									<label><?php esc_html_e( 'To', 'opti-behavior' ); ?><input type="date" name="to" value="<?php echo esc_attr( $state['to'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" data-ob-to></label>
								</div>
							</div>
							<p class="ob-reports__hint" data-ob-hint><?php echo esc_html( self::hint( $state['from'], $state['to'], $state['exclude_spam'] ) ); ?></p>
						</div>
					</fieldset>

					<fieldset class="ob-reports__row" data-ob-show="site" <?php echo 'site' === $type ? '' : 'hidden'; ?>>
						<legend class="screen-reader-text"><?php esc_html_e( 'Version', 'opti-behavior' ); ?></legend>
						<div class="ob-reports__q" aria-hidden="true"><b><span class="ob-reports__step">3</span><?php esc_html_e( 'Version', 'opti-behavior' ); ?></b><span><?php esc_html_e( 'Short for a quick look, full to share with a client.', 'opti-behavior' ); ?></span></div>
						<div class="ob-reports__choices">
							<label class="ob-reports__choice">
								<input type="radio" name="version" value="short" data-ob-version <?php checked( null === $pro ); ?>>
								<span class="ob-reports__card">
									<span class="ob-reports__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg></span>
									<span><strong><?php esc_html_e( 'Short', 'opti-behavior' ); ?></strong><p><?php esc_html_e( 'Traffic and the 3 most costly issues. About 2 pages.', 'opti-behavior' ); ?></p></span>
									<span class="ob-reports__dot" aria-hidden="true"></span>
								</span>
							</label>
							<label class="ob-reports__choice<?php echo null === $pro ? ' is-locked' : ''; ?>">
								<input type="radio" name="version" value="full" data-ob-version <?php checked( null !== $pro ); ?> <?php disabled( null === $pro ); ?>>
								<span class="ob-reports__card">
									<span class="ob-reports__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/></svg></span>
									<span><strong><?php esc_html_e( 'Full', 'opti-behavior' ); ?></strong><p><?php esc_html_e( 'Plus a card for every page and the page reports you choose, in annex.', 'opti-behavior' ); ?></p>
										<?php if ( null === $pro ) : ?>
											<?php /* translators: %s: product name "Opti-Behavior Pro". */ ?>
											<span class="ob-reports__lock"><?php echo $lock . sprintf( esc_html__( 'Full site report with a card for every page: %s', 'opti-behavior' ), $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG + escaped sentence + static product name. ?></span>
										<?php endif; ?>
									</span>
									<span class="ob-reports__dot" aria-hidden="true"></span>
								</span>
							</label>
						</div>
					</fieldset>

					<?php if ( null !== $pro ) : ?>
						<?php $annex = array_slice( $pages, 0, self::ANNEX_ROWS ); ?>
						<fieldset class="ob-reports__row" data-ob-show="full" <?php echo 'site' === $type ? '' : 'hidden'; ?>>
							<legend class="screen-reader-text"><?php esc_html_e( 'Pages in annex', 'opti-behavior' ); ?></legend>
							<?php /* translators: %d: most pages in annex (10). */ ?>
							<div class="ob-reports__q" aria-hidden="true"><b><span class="ob-reports__step">4</span><?php esc_html_e( 'Pages in annex', 'opti-behavior' ); ?></b><span><?php echo esc_html( sprintf( __( 'Each one adds its full page report at the end. Up to %d.', 'opti-behavior' ), Opti_Behavior_Report::MAX_ANNEX_PAGES ) ); ?></span></div>
							<div>
								<?php $chosen = min( self::ANNEX_DEFAULT, count( $annex ) ); ?>
								<?php /* translators: 1: number of pages chosen, 2: most pages allowed. */ ?>
								<div class="ob-reports__annex-top"><span class="ob-reports__count" data-ob-count aria-live="polite"><?php echo esc_html( sprintf( __( '%1$d of %2$d chosen', 'opti-behavior' ), $chosen, Opti_Behavior_Report::MAX_ANNEX_PAGES ) ); ?></span><span><?php esc_html_e( 'The 3 most visited pages and posts (archives excluded) are chosen for you.', 'opti-behavior' ); ?></span></div>
								<?php /* Chosen pages as removable chips, filled by reports.js (OPT-1421); without script the boxes below still work. */ ?>
								<ul class="ob-reports__chips" data-ob-chips aria-label="<?php esc_attr_e( 'Pages chosen for the annex', 'opti-behavior' ); ?>" hidden></ul>
								<div class="ob-reports__search" data-ob-search-box hidden>
									<label class="screen-reader-text" for="ob-reports-search"><?php esc_html_e( 'Search a page of your site', 'opti-behavior' ); ?></label>
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
									<input type="search" id="ob-reports-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search a page of your site…', 'opti-behavior' ); ?>" data-ob-search aria-controls="ob-reports-results" aria-describedby="ob-reports-search-hint">
									<ul class="ob-reports__results" id="ob-reports-results" data-ob-results aria-live="polite" hidden></ul>
									<p class="ob-reports__hint" id="ob-reports-search-hint"><?php esc_html_e( 'Any published page, post or product, even one without visits.', 'opti-behavior' ); ?></p>
								</div>
								<?php if ( $annex ) : ?>
									<p class="ob-reports__sub"><?php esc_html_e( 'Most visited in this period', 'opti-behavior' ); ?></p>
									<ul class="ob-reports__pages ob-reports__pages--compact">
										<?php foreach ( $annex as $i => $row ) : ?>
											<?php $on = $i < self::ANNEX_DEFAULT; ?>
											<?php /* translators: %s: number of visits. */ ?>
											<li><label class="ob-reports__page<?php echo $on ? ' is-checked' : ''; ?>" title="<?php echo esc_attr( $row['path'] ); ?>"><input type="checkbox" name="pages[]" value="<?php echo esc_attr( $row['page_id'] ); ?>" data-ob-annex data-title="<?php echo esc_attr( $row['title'] ); ?>" <?php checked( $on ); ?>><b><?php echo esc_html( $row['title'] ); ?></b><span><?php echo esc_html( sprintf( _n( '%s visit', '%s visits', $row['sessions'], 'opti-behavior' ), number_format_i18n( $row['sessions'] ) ) ); ?></span></label></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<script type="application/json" data-ob-visited-pages><?php echo wp_json_encode( self::search_pages( $pages ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON data block, "<" and "&" hex-escaped. ?></script>
							</div>
						</fieldset>
					<?php endif; ?>

					<div class="ob-reports__actions">
						<button type="submit" class="ob-reports__go" data-ob-go <?php disabled( $nodata ); ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M12 18v-6M9 15l3 3 3-3"/></svg><span><?php esc_html_e( 'Generate the report', 'opti-behavior' ); ?></span></button>
						<p class="ob-reports__go-note" data-ob-note aria-live="polite"><?php echo $nodata ? esc_html__( 'Available after the first day of visits.', 'opti-behavior' ) : esc_html__( 'Opens in a new tab as an A4 page: print it, or save it as PDF.', 'opti-behavior' ); ?></p>
					</div>
				</div>
			</form>
			<?php self::render_agency( $state, $pro ); ?>
			<?php self::render_credit_setting(); ?>
		</div>
		<?php
	}

	/**
	 * Line under the period: both periods and the spam rule.
	 *
	 * @param string $from         First day (Y-m-d).
	 * @param string $to           Last day (Y-m-d).
	 * @param bool   $exclude_spam Spam visits left out.
	 * @return string
	 */
	public static function hint( $from, $to, $exclude_spam ) {
		$utc  = new DateTimeZone( 'UTC' );
		$a    = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, $utc );
		$b    = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, $utc );
		$spam = $exclude_spam ? __( 'Spam visits are left out.', 'opti-behavior' ) : __( 'Spam visits are counted.', 'opti-behavior' );
		if ( ! $a || ! $b ) {
			return $spam;
		}
		$days  = (int) $a->diff( $b )->days + 1;
		$pb    = $a->modify( '-1 day' );
		$pa    = $a->modify( '-' . $days . ' days' );
		$short = function ( $d, $with_year ) use ( $utc ) {
			return wp_date( $with_year ? 'j M Y' : 'j M', $d->getTimestamp(), $utc );
		};

		/* translators: 1: first day of the period, 2: last day, 3: first day of the period before, 4: its last day. */
		return sprintf( __( '%1$s → %2$s, compared with %3$s → %4$s.', 'opti-behavior' ), $short( $a, $a->format( 'Y' ) !== $b->format( 'Y' ) ), $short( $b, true ), $short( $pa, $pa->format( 'Y' ) !== $pb->format( 'Y' ) ), $short( $pb, false ) ) . ' ' . $spam;
	}
}
