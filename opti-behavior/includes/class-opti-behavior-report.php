<?php
/**
 * Report engine: one A4 HTML document rendered on the server (OPT-1300 / OPT-1336).
 *
 * The engine knows nothing about what a report says. It cleans the request
 * (type, page, period, device), asks the providers for their sections through
 * the filter `opti_behavior_report_sections`, and prints each section in the
 * order of the list inside one stand-alone page: no wp-admin menu, no script,
 * no link. Opti-Behavior Pro provides `type=page` (Page X-Ray); `type=site`
 * comes later on the same engine.
 *
 * Shared, pure helpers for every report: svg_line(), svg_bar(), css(),
 * page_card().
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report engine.
 */
class Opti_Behavior_Report {

	/** Admin-post action of the report. */
	const ACTION = 'opti_behavior_report';

	/** Name planned on OPT-1300 before the common engine: kept as an alias that redirects. */
	const LEGACY_ACTION = 'opti_behavior_xray_report';

	/** Nonce action. */
	const NONCE = 'opti_behavior_report';

	/** Longest period a report covers, in days. */
	const MAX_DAYS = 365;

	/** Period when none is given, in days. */
	const DEFAULT_DAYS = 30;

	/** Report types (whitelist). */
	const TYPES = array( 'page', 'site' );

	/** Pages a site report prints in annex, at most (OPT-1341). */
	const MAX_ANNEX_PAGES = 10;

	/**
	 * "Made with Opti-Behavior" line at the end of the document (OPT-1348):
	 * '1' only when the user ticked it on the Reports screen. Absent = off
	 * (new install and update alike; WordPress.org guideline 10). Same rule
	 * with or without Pro.
	 */
	const CREDIT_OPTION = 'opti_behavior_report_credit';

	/** Address of the credit link, exactly: no site address, no identifier. */
	const CREDIT_URL = 'https://optiuser.com/?utm_source=report&utm_medium=pdf&utm_campaign=made_with';

	/** Our brand on every report (OPT-1557): home and contact page of OptiUser. */
	const BRAND_HOME    = 'https://optiuser.com/';
	const BRAND_CONTACT = 'https://optiuser.com/contact-support/';

	/** utm_campaign of each report: type => campaign (e-mails pass their own). */
	const BRAND_CAMPAIGNS = array(
		'site' => 'site-report',
		'page' => 'page-xray',
	);

	/**
	 * Built in the background (OPT-1862): the route answers at once with a
	 * waiting page, which asks admin-ajax to build the document and follows
	 * its steps, then opens the stored document (`&job=<token>`).
	 */
	const BUILD_ACTION  = 'opti_behavior_report_build';
	const STATUS_ACTION = 'opti_behavior_report_status';

	/** Life of a job and of its stored document, in seconds. */
	const JOB_TTL = 1800;

	// Second click on the same report: seconds before it is built again.
	const REUSE_PARTIAL = 60;  // Not complete: the missing parts are being calculated.
	const REUSE_DONE    = 600; // Complete: the life of the read cache of the site report.

	// OPT-2876: pages scored per preparation step, seconds a request spends on
	// them, and how many times one page is tried before the report says it
	// could not be measured.
	const PREPARE_CHUNK  = 2;
	const PREPARE_TRIES  = 2;
	const PREPARE_BUDGET = 10;

	// OPT-3054: a queued job nobody builds any more is an error after this many
	// seconds, so the waiting page never spins without an answer.
	const ORPHAN_SECS = 25;

	/**
	 * A provider left something out of the report being built.
	 *
	 * @var bool
	 */
	private static $incomplete = false;

	/**
	 * Page ids that could not be scored for the report being built (OPT-2876).
	 *
	 * @var array
	 */
	private static $prepare_failed = array();

	/** Transients of a job: state, document, "same request" key => token. */
	const JOB_PREFIX = 'opti_behavior_rjob_';
	const DOC_PREFIX = 'opti_behavior_rdoc_';
	const KEY_PREFIX = 'opti_behavior_rkey_';

	/** Period keys of the screens (same as the Page X-Ray period form). */
	const PERIODS = array(
		'last7days'  => 7,
		'last14days' => 14,
		'last30days' => 30,
		'last90days' => 90,
	);

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_' . self::LEGACY_ACTION, array( __CLASS__, 'handle_legacy_request' ) );
		add_action( 'wp_ajax_' . self::BUILD_ACTION, array( __CLASS__, 'ajax_build' ) );
		add_action( 'wp_ajax_' . self::STATUS_ACTION, array( __CLASS__, 'ajax_status' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'opti-behavior report', array( __CLASS__, 'cli_report' ) );
			WP_CLI::add_command( 'opti-behavior xray-report', array( __CLASS__, 'cli_xray_report' ) );
		}
	}

	/**
	 * Address of a report (nonce included). Administrators only can open it.
	 * Raw address (`&`, not `&amp;`): escape it with esc_url() when printed.
	 *
	 * @param array $args `type`, `page_id`, `period` or `from` / `to`, `exclude_spam`, `device`, `version` + `pages` (site report).
	 * @return string
	 */
	public static function url( $args = array() ) {
		$query = array( 'action' => self::ACTION );
		foreach ( self::query_args( $args ) as $key => $value ) {
			$query[ $key ] = is_int( $value ) ? $value : rawurlencode( $value );
		}
		$query['_wpnonce'] = wp_create_nonce( self::NONCE );

		return add_query_arg( $query, admin_url( 'admin-post.php' ) );
	}

	/**
	 * The arguments of a report as request fields (raw values, not encoded):
	 * what url() puts in the address and the waiting page sends back to
	 * admin-ajax. clean_args() of them gives the same arguments again.
	 *
	 * @param array $args Arguments.
	 * @return array Key => string, or int for a yes / no.
	 */
	public static function query_args( $args ) {
		$query = array();
		foreach ( array( 'pages', 'posts' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$args[ $key ] = implode( ',', self::clean_page_ids( $args[ $key ] ) );
			}
		}
		foreach ( array( 'type', 'page_id', 'period', 'from', 'to', 'exclude_spam', 'device', 'version', 'pages', 'posts' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_bool( $args[ $key ] ) ) {
				// A "no" is kept too (before OPT-1862 `exclude_spam` false fell out of the address = spam excluded).
				$query[ $key ] = (int) $args[ $key ];
			} elseif ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$query[ $key ] = (string) $args[ $key ];
			}
		}

		return $query;
	}

	/**
	 * Clean the arguments of a report.
	 *
	 * Period: a known `period` key wins (the screens send it, so a report asks
	 * the same cached data as the screen); else `from` / `to` in Y-m-d, swapped
	 * when reversed, at most MAX_DAYS long (the start moves); else the last
	 * DEFAULT_DAYS days of the site's time zone.
	 *
	 * @param array $raw Raw arguments (request or CLI), unslashed.
	 * @return array|WP_Error { type, page_id, period ('' = dates), from, to, days, exclude_spam, device, version, pages, posts }.
	 */
	public static function clean_args( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$type = isset( $raw['type'] ) ? strtolower( trim( (string) $raw['type'] ) ) : 'page';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'opti_behavior_report_type', __( 'Unknown report type.', 'opti-behavior' ), array( 'status' => 400 ) );
		}
		$page_id = isset( $raw['page_id'] ) ? absint( $raw['page_id'] ) : 0;
		if ( 'page' === $type && $page_id <= 0 ) {
			return new WP_Error( 'opti_behavior_report_page', __( 'Choose a page for this report.', 'opti-behavior' ), array( 'status' => 400 ) );
		}

		$period = isset( $raw['period'] ) ? sanitize_key( (string) $raw['period'] ) : '';
		$today  = current_time( 'Y-m-d' );
		if ( isset( self::PERIODS[ $period ] ) ) {
			$to   = $today;
			$from = self::shift_day( $today, 1 - self::PERIODS[ $period ] );
		} else {
			$period = '';
			$from   = isset( $raw['from'] ) ? self::clean_day( $raw['from'] ) : '';
			$to     = isset( $raw['to'] ) ? self::clean_day( $raw['to'] ) : '';
			if ( '' === $to ) {
				$to = $today;
			}
			if ( '' === $from ) {
				$from = self::shift_day( $to, 1 - self::DEFAULT_DAYS );
			}
			if ( $from > $to ) {
				$swap = $from;
				$from = $to;
				$to   = $swap;
			}
			if ( self::days_between( $from, $to ) > self::MAX_DAYS ) {
				$from = self::shift_day( $to, 1 - self::MAX_DAYS );
			}
		}

		$spam    = ! isset( $raw['exclude_spam'] ) || ! in_array( strtolower( (string) $raw['exclude_spam'] ), array( '0', 'false', 'no', '' ), true );
		$device  = isset( $raw['device'] ) ? strtolower( trim( (string) $raw['device'] ) ) : '';
		$version = isset( $raw['version'] ) ? strtolower( trim( (string) $raw['version'] ) ) : '';
		$pages   = 'site' === $type ? self::clean_page_ids( isset( $raw['pages'] ) ? $raw['pages'] : array() ) : array();
		// WordPress posts chosen in annex that Opti-Behavior never saw (OPT-1421):
		// together with `pages`, at most MAX_ANNEX_PAGES.
		$posts = 'site' === $type ? self::clean_page_ids( isset( $raw['posts'] ) ? $raw['posts'] : array() ) : array();
		$posts = array_slice( $posts, 0, max( 0, self::MAX_ANNEX_PAGES - count( $pages ) ) );

		return array(
			'type'         => $type,
			'page_id'      => 'page' === $type ? $page_id : 0,
			'period'       => $period,
			'from'         => $from,
			'to'           => $to,
			'days'         => self::days_between( $from, $to ),
			'exclude_spam' => $spam,
			'device'       => in_array( $device, array( 'desktop', 'mobile' ), true ) ? $device : '',
			// Site report only (OPT-1341): short / full ('' = the provider decides)
			// and the pages printed in annex.
			'version'      => 'site' === $type && in_array( $version, array( 'short', 'full' ), true ) ? $version : '',
			'pages'        => $pages,
			'posts'        => $posts,
		);
	}

	/**
	 * Page ids of a request: a list (`pages[]=3&pages[]=7`) or one comma text
	 * (`pages=3,7`); positive, without duplicates, in the order given, at most
	 * MAX_ANNEX_PAGES.
	 *
	 * @param mixed $raw Raw value.
	 * @return int[]
	 */
	public static function clean_page_ids( $raw ) {
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out  = array();
		foreach ( $list as $id ) {
			$id = is_scalar( $id ) && ctype_digit( trim( (string) $id ) ) ? (int) trim( (string) $id ) : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX_ANNEX_PAGES ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * A Y-m-d day that exists, or ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_day( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Day + N days (calendar arithmetic, no time zone involved).
	 *
	 * @param string $day  Y-m-d.
	 * @param int    $days Days to add (negative = back).
	 * @return string
	 */
	private static function shift_day( $day, $days ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $day, new DateTimeZone( 'UTC' ) );

		return $date ? $date->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' ) : $day;
	}

	/**
	 * Days of a period, both ends counted.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	private static function days_between( $from, $to ) {
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new DateTimeZone( 'UTC' ) );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, new DateTimeZone( 'UTC' ) );

		return ( $a && $b ) ? (int) $a->diff( $b )->days + 1 : 0;
	}

	/**
	 * The sections of a report, in the order of the filter.
	 *
	 * Each section = { id, title, tier: free|pro, render: callable( $data, $args ) → string, data }.
	 * An entry that is not a valid section is left out.
	 *
	 * @param string $type Report type.
	 * @param array  $args clean_args() result.
	 * @return array
	 */
	public static function get_sections( $type, $args ) {
		try {
			/**
			 * Sections of a report.
			 *
			 * @param array  $sections List of { id, title, tier, render, data }.
			 * @param string $type     `page` | `site`.
			 * @param array  $args     Cleaned arguments (type, page_id, period, from, to, days, exclude_spam, device).
			 */
			$list = apply_filters( 'opti_behavior_report_sections', array(), $type, $args );
		} catch ( Throwable $e ) {
			$list = array();
		}
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $section ) {
			if ( ! is_array( $section ) || empty( $section['id'] ) || ! isset( $section['render'] ) || ! is_callable( $section['render'] ) ) {
				continue;
			}
			$out[] = array(
				'id'     => sanitize_key( (string) $section['id'] ),
				'title'  => isset( $section['title'] ) ? (string) $section['title'] : '',
				'tier'   => isset( $section['tier'] ) && 'pro' === $section['tier'] ? 'pro' : 'free',
				'render' => $section['render'],
				'data'   => isset( $section['data'] ) ? $section['data'] : array(),
			);
		}

		return $out;
	}

	/**
	 * The whole document.
	 *
	 * @param array $args clean_args() result.
	 * @return string Complete HTML5 page.
	 */
	public static function render_document( $args ) {
		$type     = isset( $args['type'] ) ? (string) $args['type'] : 'page';
		$sections = self::get_sections( $type, $args );
		$body     = '';
		$total    = count( $sections );
		foreach ( $sections as $i => $section ) {
			$body .= self::render_section( $section, $args );
			/** This action is documented in includes/class-opti-behavior-report-site.php */
			do_action( 'opti_behavior_report_progress', 'section', $i + 1, $total );
		}
		/** This action is documented in includes/class-opti-behavior-report-site.php */
		do_action( 'opti_behavior_report_progress', 'finish', $total, $total );
		if ( '' === $body ) {
			$body = 'page' === $type
				? '<section class="obr-section obr-state"><h2>' . esc_html__( 'Nothing measured for this page yet', 'opti-behavior' ) . '</h2><p>' . esc_html__( 'This page has no visit with data in this period. Come back when it has been visited, or choose another period.', 'opti-behavior' ) . '</p></section>'
				: '<section class="obr-section obr-state"><p>' . esc_html__( 'No section yet.', 'opti-behavior' ) . '</p></section>';
		}
		// OPT-3054: no report is blocked; a report missing the Pro modules says so
		// at the top, with one button to the Pro trial.
		if ( class_exists( 'Opti_Behavior_Report_Pro_Notice' ) ) {
			$body = Opti_Behavior_Report_Pro_Notice::box_html() . $body;
		}
		/**
		 * Title of a report document.
		 *
		 * @param string $title Default title.
		 * @param string $type  Report type.
		 * @param array  $args  Cleaned arguments.
		 */
		$title = (string) apply_filters( 'opti_behavior_report_title', 'site' === $type ? __( 'Site report', 'opti-behavior' ) : __( 'Page report', 'opti-behavior' ), $type, $args );
		$site  = self::site_name();
		/**
		 * Words printed at the bottom left of every sheet (the page number is on the right).
		 *
		 * @param string $footer Default footer: "Opti-Behavior — <title>".
		 * @param string $type   Report type.
		 * @param array  $args   Cleaned arguments.
		 */
		$footer = (string) apply_filters( 'opti_behavior_report_footer', 'Opti-Behavior — ' . $title, $type, $args );
		$made   = date_i18n( 'j M Y, H:i', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Date of the site, shown as text.
		$brand  = self::brand( $type, $args );
		$logo   = null !== $brand['logo'] ? $brand['logo'] : self::logo_data_uri();
		$mark   = null !== $brand['name'] ? $brand['name'] : 'Opti-Behavior';

		$html  = "<!DOCTYPE html>\n";
		$html .= '<html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '">';
		$html .= '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		$html .= '<title>' . esc_html( $title . ' — ' . $site ) . '</title>';
		// Second footer line of every sheet = our brand as text (OPT-1557; a page margin box holds no link).
		$sheet = $brand['agency']
			? __( 'Made with Opti-Behavior', 'opti-behavior' ) . ' · optiuser.com'
			: __( 'Report made with Opti-Behavior by OptiUser', 'opti-behavior' ) . ' · optiuser.com · ' . __( 'Contact:', 'opti-behavior' ) . ' optiuser.com/contact-support/';
		$html .= '<style>' . self::css() . $brand['css'] . '@page{@bottom-left{content:' . self::css_string( $footer ) . ' "\A" ' . self::css_string( $sheet ) . '}}' . self::sheet_head_css( $logo, $mark, $site, $brand['agency'] ) . '</style></head>';
		$html .= '<body class="obr obr--' . esc_attr( $type ) . ( $brand['agency'] ? ' obr--agency' : '' ) . '">';
		$html .= '<main class="xr-doc">';
		// Page 1 header of the design (OPT-1301): band, logo, product, report name, generated date.
		$html .= '<div class="xr-cover-band"></div><header class="xr-brand">';
		if ( '' !== $logo ) {
			$html .= '<img src="' . esc_attr( $logo ) . '" alt="' . esc_attr( null !== $brand['logo'] ? $mark : '' ) . '">';
		}
		$campaign = isset( self::BRAND_CAMPAIGNS[ $type ] ) ? self::BRAND_CAMPAIGNS[ $type ] : 'site-report';
		// Our brand under the product name + optiuser.com under the date (OPT-1557); an agency cover stays as it is.
		$by    = $brand['agency'] ? '' : '<div class="xr-brand-by">' . esc_html__( 'by OptiUser', 'opti-behavior' ) . '</div>';
		$home  = $brand['agency'] ? '' : '<a class="xr-brand-url" href="' . esc_url( self::brand_url( $campaign ) ) . '">optiuser.com</a>';
		$html .= '<div>' . ( '' !== $mark ? '<div class="xr-brand-name">' . esc_html( $mark ) . '</div>' : '' ) . $by . '<div class="xr-brand-doc">' . esc_html( $title ) . '</div></div>';
		$html .= '<div class="xr-brand-date">' . esc_html__( 'Generated', 'opti-behavior' ) . '<b>' . esc_html( $made ) . '</b>' . $home . '</div></header>';
		$html .= $body . self::credit_html() . self::brand_footer_html( $campaign, $brand['agency'] ) . '</main>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * The user ticked "Made with Opti-Behavior" (OPT-1348). Off unless the
	 * option holds '1'.
	 *
	 * @return bool
	 */
	public static function credit_enabled() {
		return '1' === (string) get_option( self::CREDIT_OPTION, '0' );
	}

	/**
	 * Credit line printed at the end of the document, or '' (nothing at all)
	 * when the box is not ticked. No network call, nothing about the site.
	 * Look (design OPT-1349): the words of the page footer (7.5pt, slate,
	 * left edge) after a thin rule, so it reads as part of the footer.
	 * Inline: the class never appears in an unticked document.
	 *
	 * @return string
	 */
	public static function credit_html() {
		if ( ! self::credit_enabled() ) {
			return '';
		}

		return '<p class="obr-credit" style="margin:7mm 0 0;padding-top:2.5mm;border-top:.5pt solid #cbd5e1;text-align:left;font:600 7.5pt/1.4 \'Segoe UI\',system-ui,Arial,sans-serif;color:#475569;break-inside:avoid;page-break-inside:avoid"><a href="' . esc_url( self::CREDIT_URL ) . '" style="color:inherit;text-decoration:none">' . esc_html__( 'Made with Opti-Behavior', 'opti-behavior' ) . '</a></p>';
	}

	/**
	 * Address of our site or contact page with the report UTM (OPT-1557).
	 *
	 * @param string $campaign utm_campaign (site-report, page-xray, shop-report, email-report).
	 * @param bool   $contact  Contact page instead of the home page.
	 * @return string Raw address (esc_url when printed).
	 */
	public static function brand_url( $campaign, $contact = false ) {
		$campaign = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $campaign ) );

		return add_query_arg(
			array(
				'utm_source'   => 'opti-behavior',
				'utm_medium'   => 'report',
				'utm_campaign' => '' !== $campaign ? $campaign : 'site-report',
			),
			$contact ? self::BRAND_CONTACT : self::BRAND_HOME
		);
	}

	/**
	 * Brand line of the report as plain text (text e-mails, OPT-1557):
	 * "Report made with Opti-Behavior by OptiUser · <home> · Contact: <contact>".
	 *
	 * @param string $campaign utm_campaign.
	 * @return string
	 */
	public static function brand_footer_text( $campaign ) {
		return __( 'Report made with Opti-Behavior by OptiUser', 'opti-behavior' ) . ' · ' . self::brand_url( $campaign ) . ' · ' . __( 'Contact:', 'opti-behavior' ) . ' ' . self::brand_url( $campaign, true );
	}

	/**
	 * Brand line with working links at the end of the document (OPT-1557).
	 * Every sheet also prints the same words as text in its bottom margin
	 * (render_document(): a margin box holds no link, and Chrome misplaces the
	 * links of a position:fixed footer in the PDF). An agency brand (Pro)
	 * keeps one discreet "Made with Opti-Behavior · optiuser.com".
	 *
	 * @param string $campaign utm_campaign.
	 * @param bool   $agency   Agency brand on the cover.
	 * @return string
	 */
	public static function brand_footer_html( $campaign, $agency = false ) {
		$home = '<a href="' . esc_url( self::brand_url( $campaign ) ) . '">optiuser.com</a>';
		if ( $agency ) {
			return '<footer class="obr-foot obr-foot--small">' . esc_html__( 'Made with Opti-Behavior', 'opti-behavior' ) . ' · ' . $home . '</footer>';
		}

		return '<footer class="obr-foot">' . esc_html__( 'Report made with Opti-Behavior by OptiUser', 'opti-behavior' ) . ' · ' . $home . ' · ' . esc_html__( 'Contact:', 'opti-behavior' ) . ' <a href="' . esc_url( self::brand_url( $campaign, true ) ) . '">optiuser.com/contact-support/</a></footer>';
	}

	/**
	 * Mark of the cover asked by a provider (Pro agency options, OPT-1346).
	 *
	 * Filter `opti_behavior_report_brand( array(), $type, $args )` may answer
	 * `{ name, logo, css, agency }`: `name` replaces "Opti-Behavior" ('' = no
	 * name), `logo` = a data:image address ('' = no picture; anything else is
	 * refused), `css` is added after the sheet ("<" removed: it can never close
	 * the style tag), `agency` adds the body class `obr--agency`. No answer =
	 * the document exactly as before.
	 *
	 * @param string $type Report type.
	 * @param array  $args Cleaned arguments.
	 * @return array { name: string|null, logo: string|null, css: string, agency: bool }
	 */
	public static function brand( $type, $args ) {
		$out = array(
			'name'   => null,
			'logo'   => null,
			'css'    => '',
			'agency' => false,
		);
		try {
			$raw = apply_filters( 'opti_behavior_report_brand', array(), $type, $args );
		} catch ( Throwable $e ) {
			$raw = array();
		}
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		if ( isset( $raw['name'] ) && is_scalar( $raw['name'] ) ) {
			$out['name'] = trim( wp_strip_all_tags( (string) $raw['name'] ) );
		}
		if ( isset( $raw['logo'] ) && is_string( $raw['logo'] ) ) {
			$out['logo'] = preg_match( '#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$#', $raw['logo'] ) ? $raw['logo'] : '';
		}
		if ( isset( $raw['css'] ) && is_string( $raw['css'] ) ) {
			$out['css'] = str_replace( '<', '', $raw['css'] );
		}
		$out['agency'] = ! empty( $raw['agency'] );

		return $out;
	}

	/**
	 * The Opti-Behavior mark of the report as a data: address (the document
	 * loads no file). '' when the file is missing.
	 *
	 * @return string
	 */
	public static function logo_data_uri() {
		// The plugin icon (assets/images/256x256.png) at 128 px: sharp on paper, light in the document (OPT-1498).
		$file = dirname( __DIR__ ) . '/assets/images/report-icon.png';
		$png  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.

		return '' === $png ? '' : 'data:image/png;base64,' . base64_encode( $png ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline picture of the document.
	}

	/**
	 * Brand header printed at the top of every sheet after the cover (OPT-1498):
	 * logo + mark on the left, site name on the right, brand rule below. Paper
	 * only (a page margin box); sheet 1 keeps the cover header. The box holds
	 * one SVG picture so the logo and the words stay centred on one line.
	 *
	 * @param string $logo   data: address of the logo ('' = none).
	 * @param string $mark   Brand name ('' = none).
	 * @param string $site   Site name.
	 * @param bool   $agency Agency brand (plain rule in its colour).
	 * @return string CSS.
	 */
	public static function sheet_head_css( $logo, $mark, $site, $agency ) {
		$xml  = function ( $text ) {
			return htmlspecialchars( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		};
		$font = 'font-family="Segoe UI,system-ui,-apple-system,Helvetica Neue,Arial,sans-serif"';
		$svg  = '';
		$x    = 0;
		if ( '' !== $logo ) {
			$w = 24;
			$b = 0 === strpos( $logo, 'data:image/svg' ) ? '' : base64_decode( substr( $logo, strpos( $logo, ',' ) + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Size of the inline picture.
			$i = '' !== $b && function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $b ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unknown pictures give false.
			if ( is_array( $i ) && $i[1] > 0 ) {
				$w = (int) min( 120, max( 24, round( 24 * $i[0] / $i[1] ) ) );
			} elseif ( '' === $b ) {
				$w = 72;
			}
			$svg .= '<image href="' . $logo . '" x="0" y="2" width="' . $w . '" height="24" preserveAspectRatio="xMinYMid meet"/>';
			$x    = $w + 7;
		}
		if ( '' !== $mark ) {
			$svg .= '<text x="' . $x . '" y="18.5" ' . $font . ' font-size="12.5" font-weight="700" fill="#0f172a">' . $xml( $mark ) . '</text>';
		}
		$site = wp_html_excerpt( html_entity_decode( (string) $site, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 60, '…' );
		$svg .= '<text x="673" y="18" text-anchor="end" ' . $font . ' font-size="9.5" font-weight="600" fill="#475569">' . $xml( $site ) . '</text>';
		if ( $agency ) {
			// Agency colour lives in a CSS variable: plain rule of the margin box.
			$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="178mm" height="7.4mm" viewBox="0 0 673 28">' . $svg . '</svg>';
			$rule = 'padding-bottom:2.5mm;border-bottom:.6mm solid var(--xr-brand)';
		} else {
			// Our rule = the gradient of the cover band (a margin box ignores border-image).
			$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="178mm" height="10.1mm" viewBox="0 0 673 38"><defs><linearGradient id="b"><stop offset="0" stop-color="#0ea5e9"/><stop offset=".5" stop-color="#4f46e5"/><stop offset="1" stop-color="#9333ea"/></linearGradient></defs>' . $svg . '<rect x="0" y="35.6" width="673" height="2.4" rx="1.2" fill="url(#b)"/></svg>';
			$rule = '';
		}

		return '@page{margin-top:26mm;@top-left{content:url("data:image/svg+xml;base64,' . base64_encode( $svg ) . '");width:100%;vertical-align:bottom;margin-bottom:5mm;' . $rule . '}}' // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline picture of the document.
			. '@page:first{margin-top:16mm;@top-left{content:none;border:0}}';
	}

	/**
	 * A text as a CSS string ("…"): quotes, backslashes, line breaks and "<"
	 * written as CSS escapes, so it can never close the string or the style tag.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function css_string( $text ) {
		$text = preg_replace( '/[\x00-\x1f\x7f]+/', ' ', (string) $text );

		return '"' . strtr(
			$text,
			array(
				'\\' => '\\5C ',
				'"'  => '\\22 ',
				'<'  => '\\3C ',
				'>'  => '\\3E ',
			)
		) . '"';
	}

	/**
	 * One section: a provider that throws or prints nothing never hides the others.
	 *
	 * @param array $section Section.
	 * @param array $args    Arguments.
	 * @return string
	 */
	private static function render_section( $section, $args ) {
		$level = ob_get_level();
		ob_start();
		try {
			$ret = call_user_func( $section['render'], $section['data'], $args );
			$out = (string) ob_get_clean();
			if ( is_string( $ret ) ) {
				$out .= $ret;
			}
		} catch ( Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$out = '<p class="obr-state">' . esc_html__( 'This section could not be displayed.', 'opti-behavior' ) . '</p>';
		}
		if ( '' === trim( $out ) ) {
			return '';
		}
		$head = '' !== $section['title'] ? '<div class="xr-sec-head"><h2>' . esc_html( $section['title'] ) . '</h2></div>' : '';

		return '<section class="obr-section obr-section--' . esc_attr( $section['id'] ) . ' is-' . esc_attr( $section['tier'] ) . '" id="obr-' . esc_attr( $section['id'] ) . '">' . $head . $out . '</section>';
	}

	/**
	 * The site a report is about: its name, else its address without scheme.
	 *
	 * @return string
	 */
	public static function site_name() {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		return '' !== $name ? $name : (string) preg_replace( '#^https?://#i', '', untrailingslashit( home_url() ) );
	}

	/**
	 * "Last 30 days: 15 Sep → 14 Oct 2026" or "15 Sep → 14 Oct 2026".
	 *
	 * @param array $args clean_args() result.
	 * @return string
	 */
	public static function period_text( $args ) {
		$from = isset( $args['from'] ) ? strtotime( (string) $args['from'] ) : 0;
		$to   = isset( $args['to'] ) ? strtotime( (string) $args['to'] ) : 0;
		if ( ! $from || ! $to ) {
			return '';
		}
		$range = date_i18n( gmdate( 'Y', $from ) === gmdate( 'Y', $to ) ? 'j M' : 'j M Y', $from ) . ' → ' . date_i18n( 'j M Y', $to );
		$key   = isset( $args['period'] ) ? (string) $args['period'] : '';
		if ( ! isset( self::PERIODS[ $key ] ) ) {
			return $range;
		}

		/* translators: 1: number of days, 2: dates of the period. */
		return sprintf( __( 'Last %1$s days: %2$s', 'opti-behavior' ), number_format_i18n( self::PERIODS[ $key ] ), $range );
	}

	/**
	 * Request: `admin-post.php?action=opti_behavior_report`.
	 * Order of the checks: capability, nonce, then the arguments.
	 *
	 * Then (OPT-1862): `job=<token>` = the document that job stored (same user,
	 * same arguments); `sync=1` = built in this request, as before (link of the
	 * `<noscript>`); else the waiting page, at once, with nothing computed.
	 *
	 * @return void
	 */
	public static function handle_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to open this report.', 'opti-behavior' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'This report link has expired. Open it again from the plugin.', 'opti-behavior' ), '', array( 'response' => 403 ) );
		}
		$args = self::clean_args( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every key is whitelisted / sanitized by clean_args().
		if ( is_wp_error( $args ) ) {
			wp_die( esc_html( $args->get_error_message() ), '', array( 'response' => 400 ) );
		}
		$token = isset( $_GET['job'] ) ? self::clean_token( wp_unslash( $_GET['job'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_token() keeps 32 letters / digits or nothing.
		if ( '' !== $token ) {
			$html = self::stored_document( $token, $args );
			if ( null !== $html ) {
				self::send_headers();
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Document built by render_document(), every part escaped where it is built.
				exit;
			}
			// Job gone (JOB_TTL) or not this user's: the waiting page builds it again.
		}
		if ( empty( $_GET['sync'] ) ) {
			self::send_wait_headers();
			echo self::render_wait_page( $args, $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped where it is built.
			exit;
		}
		self::send_headers();
		echo self::render_document( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped where it is built.
		exit;
	}

	/**
	 * Waiting page of a report (OPT-1862, design OPT-1864): brand band, title,
	 * a progress bar, the four steps, an error box and a `<noscript>` link to the old route. Nothing computed.
	 * Everything assets/js/report-wait.js needs is in `data-ob-config`.
	 *
	 * @param array  $args  clean_args() result.
	 * @param string $nonce Nonce of the request (already checked).
	 * @return string Complete HTML5 page.
	 */
	public static function render_wait_page( $args, $nonce ) {
		$link   = self::url( $args );
		$config = array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'build'  => self::BUILD_ACTION,
			'status' => self::STATUS_ACTION,
			'nonce'  => $nonce,
			'args'   => self::query_args( $args ),
			'report' => $link,
			'texts'  => array(
				/* translators: 1: number of the section being written, 2: number of sections. */
				'section' => __( 'Section %1$s of %2$s', 'opti-behavior' ),
				/* translators: 1: number of the page being scored, 2: number of pages. OPT-2876. */
				'pages'   => __( 'Page %1$s of %2$s', 'opti-behavior' ),
				'expired' => __( 'This report link has expired. Open it again from the plugin.', 'opti-behavior' ),
			),
		);
		$steps  = array(
			__( 'Reading sessions…', 'opti-behavior' ),
			__( 'Counting clicks and scroll…', 'opti-behavior' ),
			__( 'Looking for problems…', 'opti-behavior' ),
			__( 'Preparing the action plan…', 'opti-behavior' ),
		);
		/**
		 * Work to do in this page before the report is built: a frame of this site
		 * that answers with a message `ob-xray-capture` (Pro: the heatmap picture).
		 *
		 * @param array|null $capture { url, timeout (ms), label } or null.
		 * @param array      $args    Report args.
		 */
		// OPT-3054: the report is written anyway; while it is, say what the Pro
		// modules do not measure, with one button to the Pro trial.
		$notice = class_exists( 'Opti_Behavior_Report_Pro_Notice' ) ? Opti_Behavior_Report_Pro_Notice::wait_notice() : null;
		$capture = apply_filters( 'opti_behavior_report_wait_capture', null, $args );
		if ( is_array( $capture ) && ! empty( $capture['url'] ) && ! empty( $capture['label'] ) ) {
			$config['capture'] = array(
				'url'     => (string) $capture['url'],
				'timeout' => isset( $capture['timeout'] ) ? absint( $capture['timeout'] ) : 45000,
			);
			array_unshift( $steps, (string) $capture['label'] );
		}
		$title  = __( 'Preparing your report', 'opti-behavior' );
		$assets = defined( 'OPTI_BEHAVIOR_HEATMAP_ASSETS_URL' ) ? OPTI_BEHAVIOR_HEATMAP_ASSETS_URL : plugins_url( 'assets/', dirname( __FILE__ ) );
		$ver    = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1';

		$html  = "<!DOCTYPE html>\n";
		$html .= '<html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '">';
		$html .= '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		$html .= '<title>' . esc_html( $title . ' — ' . self::site_name() ) . '</title>';
		$html .= '<link rel="stylesheet" href="' . esc_url( self::wait_asset( 'css/report-wait.css', $assets, $ver ) ) . '"></head>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone document sent by admin-post, no wp_head(); its CSP allows only this file.
		$html .= '<body class="obw"><main class="obw-box" data-ob-report-wait data-ob-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		$html .= '<header class="obw-head"><img class="obw-logo" src="' . esc_url( $assets . 'images/256x256.png' ) . '" width="40" height="40" alt=""><span class="obw-brand">Opti-Behavior</span></header>';
		$html .= '<div class="obw-body"><h1 id="obw-title">' . esc_html( $title ) . '</h1>';
		$html .= '<p class="obw-lead">' . esc_html__( 'Large sites can take a minute or two. Keep this tab open: the report opens here when it is ready.', 'opti-behavior' ) . '</p>';
		// OPT-3054: the Pro modules missing from this report, said while it is
		// written (the finished document repeats it in a box at the top).
		if ( is_array( $notice ) ) {
			// OPT-3055: same Pro pill as the upsell banner, so the three Pro
			// places of the plugin read as one family.
			$html .= '<aside class="obw-pro" role="note"><p class="obw-pro__t"><span class="obw-pro__badge">Pro</span>' . esc_html( $notice['headline'] ) . '</p>';
			$html .= '<p class="obw-pro__l">' . esc_html( $notice['line'] ) . '</p>';
			if ( '' !== $notice['url'] ) {
				$html .= '<p class="obw-pro__a"><a class="obw-pro__cta" href="' . esc_url( $notice['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $notice['cta'] ) . '</a></p>';
			}
			$html .= '</aside>';
		}
		// Width set by report-wait.js from the server step, never a timer.
		$html .= '<div class="obw-bar" role="progressbar" aria-labelledby="obw-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="obw-bar__fill"></span></div>';
		$html .= '<ol class="obw-steps">';
		foreach ( $steps as $i => $text ) {
			$html .= '<li data-ob-step="' . (int) $i . '"' . ( 0 === $i ? ' class="is-now"' : '' ) . '>' . esc_html( $text ) . '</li>';
		}
		$html .= '</ol><p class="obw-count" aria-live="polite"></p>';
		$html .= '<div class="obw-error" role="alert" hidden><p data-ob-error>' . esc_html__( 'The report took too long or failed.', 'opti-behavior' ) . '</p>';
		$html .= '<button type="button" data-ob-retry>' . esc_html__( 'Try again', 'opti-behavior' ) . '</button></div>';
		$html .= '<noscript><p class="obw-noscript">' . esc_html__( 'JavaScript is off in this browser.', 'opti-behavior' ) . ' <a href="' . esc_url( add_query_arg( 'sync', 1, $link ) ) . '">' . esc_html__( 'Open the report without this page', 'opti-behavior' ) . '</a></p></noscript>';
		$html .= '</div></main><script src="' . esc_url( self::wait_asset( 'js/report-wait.js', $assets, $ver ) ) . '"></script></body></html>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone document sent by admin-post, no wp_footer(); its CSP allows only this file.

		return $html;
	}

	/**
	 * Address of a file of the waiting page, versioned by its date.
	 *
	 * @param string $file   Path under assets/.
	 * @param string $assets Assets address (ends with /).
	 * @param string $ver    Plugin version.
	 * @return string
	 */
	private static function wait_asset( $file, $assets, $ver ) {
		$path = dirname( __DIR__ ) . '/assets/' . $file;

		return add_query_arg( 'ver', file_exists( $path ) ? $ver . '.' . filemtime( $path ) : $ver, $assets . $file );
	}

	/**
	 * Headers of the waiting page: like send_headers(), plus our own script,
	 * stylesheet and image (by address) and admin-ajax calls on this site.
	 *
	 * @return void
	 */
	public static function send_wait_headers() {
		if ( headers_sent() ) {
			return;
		}
		$assets = defined( 'OPTI_BEHAVIOR_HEATMAP_ASSETS_URL' ) ? OPTI_BEHAVIOR_HEATMAP_ASSETS_URL : plugins_url( 'assets/', dirname( __FILE__ ) );
		$script = strtok( $assets . 'js/report-wait.js', '?' );
		$style  = strtok( $assets . 'css/report-wait.css', '?' );
		$origin = self::origin_of( $assets );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; script-src " . $script . "; style-src 'self' " . $style . "; img-src 'self' data: " . $origin . "; connect-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'none'" );
	}

	/**
	 * "scheme://host[:port]" of an address, for the policy ('' when unreadable).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function origin_of( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return "'self'";
		}

		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * A job token: 32 letters / digits, else ''.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function clean_token( $raw ) {
		$raw = is_scalar( $raw ) ? (string) $raw : '';

		return preg_match( '/^[A-Za-z0-9]{32}$/', $raw ) ? $raw : '';
	}

	/**
	 * Key of "the same report for the same user": a second click, a second tab
	 * or a reload joins the job already running.
	 *
	 * @param int   $user User id.
	 * @param array $args clean_args() result.
	 * @return string
	 */
	public static function job_key( $user, $args ) {
		return md5( (int) $user . '|' . wp_json_encode( $args ) );
	}

	/**
	 * A job of the current user, or null.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	private static function get_job( $token ) {
		$job = get_transient( self::JOB_PREFIX . $token );

		return is_array( $job ) && isset( $job['user'] ) && (int) $job['user'] === get_current_user_id() ? $job : null;
	}

	/**
	 * Save a job (its life starts again).
	 *
	 * @param string $token Token.
	 * @param array  $job   Job.
	 * @return void
	 */
	private static function save_job( $token, $job ) {
		$job['updated'] = time();
		set_transient( self::JOB_PREFIX . $token, $job, self::JOB_TTL );
	}

	/**
	 * What the waiting page reads of a job. `stage` = the step shown (0 to 3).
	 *
	 * @param string $token Token.
	 * @param array  $job   Job.
	 * @return array { token, state queued|running|done|error, step, done, total, stage }
	 */
	public static function job_view( $token, $job ) {
		$step  = isset( $job['step'] ) ? (string) $job['step'] : '';
		$done  = isset( $job['done'] ) ? (int) $job['done'] : 0;
		$total = isset( $job['total'] ) ? (int) $job['total'] : 0;
		$state = isset( $job['state'] ) ? (string) $job['state'] : 'queued';

		$plan = isset( $job['plan'] ) && is_array( $job['plan'] ) ? $job['plan'] : array();

		return array(
			'token' => $token,
			'state' => $state,
			'step'  => $step,
			'done'  => $done,
			'total' => $total,
			'stage' => self::stage( $state, $step, $done, $total ),
			// OPT-2876: pages still to score. True = the waiting page asks for
			// another step, so the document is only written once every page is
			// ready and the user never clicks a second time.
			'more'  => 'pages' === $step && ! empty( $plan['todo'] ),
		);
	}

	/**
	 * Step shown for a progress point (pure): 0 sessions, 1 clicks and scroll,
	 * 2 problems (journeys, Pro blocks, sections), 3 action plan.
	 *
	 * @param string $state Job state.
	 * @param string $step  Last progress point.
	 * @param int    $done  Sections written.
	 * @param int    $total Sections.
	 * @return int
	 */
	public static function stage( $state, $step, $done, $total ) {
		if ( 'done' === $state || 'finish' === $step || ( 'section' === $step && $total > 0 && $done >= $total ) ) {
			return 3;
		}
		if ( in_array( $step, array( 'clicks', 'journeys', 'pro', 'section' ), true ) ) {
			return 2;
		}

		return 'sessions' === $step ? 1 : 0;
	}

	/**
	 * Admin-ajax `opti_behavior_report_build` (POST). Without `token`: the job
	 * of these arguments (the running / finished one of the same user, else a
	 * new one), answered at once. With `token`: builds that job in this
	 * request; a second request for a job already being built answers its
	 * state and builds nothing.
	 *
	 * The action rides in the address, not in the posted fields, so the
	 * plugin language of the admin screens is not applied: the document is
	 * written in the language of the admin-post route.
	 *
	 * @return void
	 */
	public static function ajax_build() {
		self::check_job_request();
		$token = isset( $_POST['token'] ) ? self::clean_token( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by check_job_request(); clean_token() keeps 32 letters / digits.
		if ( '' === $token ) {
			$args = self::clean_args( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by check_job_request(); every key is whitelisted / sanitized by clean_args().
			if ( is_wp_error( $args ) ) {
				wp_send_json_error( array( 'code' => 'args' ), 400 );
			}
			wp_send_json_success( self::start_job( $args ) );
		}
		$job = self::get_job( $token );
		if ( null === $job ) {
			wp_send_json_error( array( 'code' => 'gone' ), 404 );
		}
		wp_send_json_success( self::run_job( $token, $job ) );
	}

	/**
	 * Admin-ajax `opti_behavior_report_status` (GET): job_view() of a job of
	 * the current user. A job "running" whose builder holds no lock any more
	 * (request killed, fatal error) is an error.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		self::check_job_request();
		$token = isset( $_GET['token'] ) ? self::clean_token( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by check_job_request(); clean_token() keeps 32 letters / digits.
		$job   = '' !== $token ? self::get_job( $token ) : null;
		if ( null === $job ) {
			wp_send_json_error( array( 'code' => 'gone' ), 404 );
		}
		if ( 'running' === $job['state'] && self::builder_gone( $token ) ) {
			$job['state'] = 'error';
			self::save_job( $token, $job );
		}
		// OPT-3054: a job left `queued` with no builder (the build request died
		// between two preparation steps) used to keep the waiting page spinning
		// until the browser gave up after five minutes. Nobody building and
		// nothing moving for ORPHAN_SECS = the error message, in under 30 s.
		if ( 'queued' === $job['state']
			&& time() - ( isset( $job['updated'] ) ? (int) $job['updated'] : 0 ) >= self::ORPHAN_SECS
			&& self::builder_gone( $token )
		) {
			$job['state'] = 'error';
			self::save_job( $token, $job );
		}
		wp_send_json_success( self::job_view( $token, $job ) );
	}

	/**
	 * Capability + nonce of both job endpoints (403 `forbidden` / `expired`).
	 *
	 * @return void
	 */
	private static function check_job_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array( 'code' => 'expired' ), 403 );
		}
	}

	/**
	 * The job of these arguments for the current user: the queued / running /
	 * finished one, else a new queued job.
	 *
	 * @param array $args clean_args() result.
	 * @return array job_view().
	 */
	public static function start_job( $args ) {
		$user = get_current_user_id();
		$key  = self::job_key( $user, $args );
		$lock = 'ob_rkey_' . $key;
		$held = class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) ? Opti_Behavior_Heatmap_DB_Lock::acquire( $lock, 5 ) : '';
		try {
			$token = self::clean_token( get_transient( self::KEY_PREFIX . $key ) );
			$job   = '' !== $token ? self::get_job( $token ) : null;
			$alive = self::job_reusable( $job, time() );
			if ( $alive && 'done' === $job['state'] && false === get_transient( self::DOC_PREFIX . $token ) ) {
				$alive = false; // Document evicted (object cache): build it again.
			}
			if ( $alive ) {
				return self::job_view( $token, $job );
			}
			$token = wp_generate_password( 32, false, false );
			$job   = array(
				'user'  => $user,
				'key'   => $key,
				'args'  => $args,
				'state' => 'queued',
				'step'  => '',
				'done'  => 0,
				'total' => 0,
				'at'    => time(),
			);
			self::save_job( $token, $job );
			set_transient( self::KEY_PREFIX . $key, $token, self::JOB_TTL );

			return self::job_view( $token, $job );
		} finally {
			if ( class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) ) {
				Opti_Behavior_Heatmap_DB_Lock::release( $lock, $held );
			}
		}
	}

	/**
	 * A provider says the report being built is not complete.
	 *
	 * @param bool $yes False = reset.
	 * @return void
	 */
	public static function mark_incomplete( $yes = true ) {
		self::$incomplete = (bool) $yes;
	}

	/**
	 * Whether the report being built was marked not complete.
	 *
	 * @return bool
	 */
	public static function is_incomplete() {
		return self::$incomplete;
	}

	/**
	 * One preparation step of a job (OPT-2876): the pages the document needs are
	 * scored a few at a time, each in its own request.
	 *
	 * A page that fails is tried again (`PREPARE_TRIES` times in all); after that
	 * it is named in `failed_pages()` and the rest of the report is delivered.
	 *
	 * @param string $token Token.
	 * @param array  $job   Job (updated in place).
	 * @return array|null job_view() while there is more to do, null when the
	 *                    document can be written in this request.
	 */
	private static function prepare_tick( $token, &$job ) {
		if ( ! isset( $job['plan'] ) || ! is_array( $job['plan'] ) ) {
			/**
			 * Pages the document needs before it can be written (OPT-2876).
			 *
			 * A provider (Pro Page X-Ray) answers the page ids whose score is not
			 * ready; an empty answer = nothing to prepare, the old one-request
			 * build.
			 *
			 * @param array $ids  Page ids.
			 * @param array $args clean_args() result.
			 */
			$ids = apply_filters( 'opti_behavior_report_prepare_plan', array(), $job['args'] );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

			$job['plan'] = array(
				'todo'   => $ids,
				'total'  => count( $ids ),
				'done'   => 0,
				'failed' => array(),
				'tries'  => array(),
			);
			self::save_job( $token, $job );
		}
		$plan = $job['plan'];
		if ( empty( $plan['todo'] ) ) {
			self::$prepare_failed = isset( $plan['failed'] ) ? (array) $plan['failed'] : array();

			return null;
		}

		/**
		 * How many pages one preparation step scores (OPT-2876).
		 *
		 * @param int   $chunk Pages per step.
		 * @param array $args  clean_args() result.
		 */
		$chunk = max( 1, absint( apply_filters( 'opti_behavior_report_prepare_chunk', self::PREPARE_CHUNK, $job['args'] ) ) );
		$batch = array_slice( array_values( $plan['todo'] ), 0, $chunk );

		/**
		 * Score these pages now (OPT-2876). The provider answers the ids it got
		 * ready; an id it does not answer is tried again, then named as failed.
		 *
		 * @param array $done  Page ids made ready.
		 * @param array $batch Page ids to prepare.
		 * @param array $args  clean_args() result.
		 */
		$ready = apply_filters( 'opti_behavior_report_prepare_step', array(), $batch, $job['args'] );
		$ready = array_map( 'absint', (array) $ready );

		foreach ( $batch as $id ) {
			if ( in_array( $id, $ready, true ) ) {
				$plan['todo'] = array_values( array_diff( $plan['todo'], array( $id ) ) );
				++$plan['done'];
				continue;
			}
			$tries              = isset( $plan['tries'][ $id ] ) ? (int) $plan['tries'][ $id ] + 1 : 1;
			$plan['tries'][ $id ] = $tries;
			if ( $tries >= self::PREPARE_TRIES ) {
				$plan['todo']     = array_values( array_diff( $plan['todo'], array( $id ) ) );
				$plan['failed'][] = $id;
				++$plan['done'];
				continue;
			}
			// Tried again at the end of the list: the ready pages come first.
			$plan['todo'] = array_values( array_diff( $plan['todo'], array( $id ) ) );
			$plan['todo'][] = $id;
		}

		$job['plan']  = $plan;
		$job['step']  = 'pages';
		$job['done']  = (int) $plan['done'];
		$job['total'] = (int) $plan['total'];

		if ( empty( $plan['todo'] ) ) {
			self::$prepare_failed = (array) $plan['failed'];
			self::save_job( $token, $job );

			return null;
		}
		// Between two steps nobody is building: `queued` again, so the status
		// endpoint does not read a running job without a builder as an error,
		// and a reader who leaves and comes back resumes this plan.
		$job['state'] = 'queued';
		self::save_job( $token, $job );

		return self::job_view( $token, $job );
	}

	/**
	 * Pages that could not be scored for the report being written (OPT-2876):
	 * page ids, already tried `PREPARE_TRIES` times.
	 *
	 * @return array
	 */
	public static function failed_pages() {
		return self::$prepare_failed;
	}

	/**
	 * Whether a new click on the same report gets this job (pure): one being
	 * built always; a done one for REUSE_PARTIAL / REUSE_DONE seconds, so a double
	 * click builds once and "generate it again" really does.
	 *
	 * @param array|null $job Job.
	 * @param int        $now Timestamp.
	 * @return bool
	 */
	public static function job_reusable( $job, $now ) {
		$state = is_array( $job ) && isset( $job['state'] ) ? $job['state'] : '';
		if ( in_array( $state, array( 'queued', 'running' ), true ) ) {
			return true;
		}
		if ( 'done' !== $state ) {
			return false;
		}
		$at = isset( $job['done_at'] ) ? (int) $job['done_at'] : ( isset( $job['at'] ) ? (int) $job['at'] : 0 );

		return (int) $now - $at <= ( ! empty( $job['partial'] ) ? self::REUSE_PARTIAL : self::REUSE_DONE );
	}

	/**
	 * Build a job in this request, unless another request already builds it
	 * (database lock of the job, held until the end of the build).
	 *
	 * @param string $token Token.
	 * @param array  $job   Job of the current user.
	 * @return array job_view().
	 */
	public static function run_job( $token, $job ) {
		if ( in_array( $job['state'], array( 'done', 'error' ), true ) ) {
			return self::job_view( $token, $job );
		}
		$lock = 'ob_rjob_' . $token;
		$held = class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) ? Opti_Behavior_Heatmap_DB_Lock::acquire( $lock, 0 ) : '';
		if ( class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) && Opti_Behavior_Heatmap_DB_Lock::BUSY === $held ) {
			$fresh = self::get_job( $token );

			return self::job_view( $token, null !== $fresh ? $fresh : $job );
		}
		try {
			$fresh = self::get_job( $token );
			$job   = null !== $fresh ? $fresh : $job;
			if ( 'done' === $job['state'] ) {
				return self::job_view( $token, $job );
			}
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- One report built on the administrator's request.
				@set_time_limit( 300 );
			}
			wp_raise_memory_limit( 'admin' );
			$job['state'] = 'running';
			$job['step']  = '';
			$job['done']  = 0;
			$job['total'] = 0;
			$job['runs']  = ( isset( $job['runs'] ) ? (int) $job['runs'] : 0 ) + 1;
			self::save_job( $token, $job );

			// OPT-2876: the pages the document needs are scored in small steps,
			// one request each, before anything is written. The waiting page comes
			// back for the next step (`more`), so a slow host never has to answer
			// one long request and the reader never sees a report to generate again.
			/**
			 * Seconds one request spends preparing pages before it answers the
			 * waiting page (OPT-2876): a fast site prepares everything at once,
			 * a slow one answers and is asked for the next step.
			 *
			 * @param float $budget Seconds.
			 * @param array $args   clean_args() result.
			 */
			$budget   = (float) apply_filters( 'opti_behavior_report_prepare_budget', self::PREPARE_BUDGET, $job['args'] );
			$deadline = microtime( true ) + max( 1.0, $budget );
			do {
				$view = self::prepare_tick( $token, $job );
			} while ( null !== $view && microtime( true ) < $deadline );
			if ( null !== $view ) {
				return $view;
			}
			// Pages ready: this request writes the document.
			$job['state'] = 'running';
			self::save_job( $token, $job );

			$listener = function ( $step, $done = 0, $total = 0 ) use ( $token ) {
				self::progress( $token, $step, $done, $total );
			};
			add_action( 'opti_behavior_report_progress', $listener, 10, 3 );
			$level = ob_get_level();
			ob_start();
			self::mark_incomplete( false );
			try {
				$html = self::render_document( $job['args'] );
			} catch ( Throwable $e ) {
				$html = '';
			}
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			remove_action( 'opti_behavior_report_progress', $listener, 10 );

			$stored = '' !== $html && set_transient( self::DOC_PREFIX . $token, self::pack( $html ), self::JOB_TTL );
			$fresh  = self::get_job( $token );
			$job    = null !== $fresh ? $fresh : $job;

			$job['state']   = $stored ? 'done' : 'error';
			$job['done_at'] = time();
			$job['partial'] = self::is_incomplete();
			self::save_job( $token, $job );

			return self::job_view( $token, $job );
		} finally {
			if ( class_exists( 'Opti_Behavior_Heatmap_DB_Lock' ) ) {
				Opti_Behavior_Heatmap_DB_Lock::release( $lock, $held );
			}
		}
	}

	/**
	 * Listener of `opti_behavior_report_progress` during a build: the step
	 * reached, written to the job.
	 *
	 * @param string $token Token.
	 * @param string $step  Progress point.
	 * @param int    $done  Sections written.
	 * @param int    $total Sections.
	 * @return void
	 */
	private static function progress( $token, $step, $done, $total ) {
		$job = self::get_job( $token );
		if ( null === $job ) {
			return;
		}
		$job['step']  = sanitize_key( (string) $step );
		$job['done']  = (int) $done;
		$job['total'] = (int) $total;
		self::save_job( $token, $job );
	}

	/**
	 * The builder of a "running" job is gone: its lock is free (MySQL only;
	 * without lock support, never).
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function builder_gone( $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock state, nothing to cache.
		$free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', 'ob_rjob_' . $token ) );

		return '1' === (string) $free;
	}

	/**
	 * The stored document of a finished job of the current user, for exactly
	 * these arguments; null otherwise.
	 *
	 * @param string $token Token.
	 * @param array  $args  clean_args() result of the request.
	 * @return string|null
	 */
	public static function stored_document( $token, $args ) {
		$job = self::get_job( $token );
		if ( null === $job || 'done' !== $job['state'] || ! isset( $job['key'] ) || self::job_key( get_current_user_id(), $args ) !== $job['key'] ) {
			return null;
		}

		return self::unpack( get_transient( self::DOC_PREFIX . $token ) );
	}

	/**
	 * Stored form of a document: deflated + base64 when zlib is there
	 * (a full report with annexes is a few hundred KB of HTML).
	 *
	 * @param string $html Document.
	 * @return string
	 */
	public static function pack( $html ) {
		if ( function_exists( 'gzdeflate' ) ) {
			$z = gzdeflate( $html, 6 );
			if ( false !== $z ) {
				return 'z:' . base64_encode( $z ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary deflate stored as text in a transient.
			}
		}

		return 'r:' . $html;
	}

	/**
	 * Document of pack(), or null.
	 *
	 * @param mixed $stored Stored value.
	 * @return string|null
	 */
	public static function unpack( $stored ) {
		if ( ! is_string( $stored ) || strlen( $stored ) < 2 ) {
			return null;
		}
		$body = substr( $stored, 2 );
		if ( 'r:' === substr( $stored, 0, 2 ) ) {
			return $body;
		}
		if ( 'z:' !== substr( $stored, 0, 2 ) || ! function_exists( 'gzinflate' ) ) {
			return null;
		}
		$z    = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverse of pack().
		$html = false !== $z ? gzinflate( $z ) : false;

		return false !== $html ? $html : null;
	}

	/**
	 * Old name of the route (OPT-1300): same checks, then the common route.
	 *
	 * @return void
	 */
	public static function handle_legacy_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to open this report.', 'opti-behavior' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die( esc_html__( 'This report link has expired. Open it again from the plugin.', 'opti-behavior' ), '', array( 'response' => 403 ) );
		}
		$args         = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Re-cleaned by url() / clean_args() on the common route.
		$args['type'] = 'page';
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Headers of the document: never cached, never indexed, no referrer, and a
	 * policy that refuses every script and every outside resource.
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'" );
	}

	/**
	 * WP-CLI: `wp opti-behavior report --type=page|site [--page=<id>] [--period=<key>] [--from=<Y-m-d> --to=<Y-m-d>] [--device=desktop|mobile] [--out=<file.html>]`.
	 *
	 * @param array $positional Positional arguments (unused).
	 * @param array $assoc      Options.
	 * @return void
	 */
	public static function cli_report( $positional, $assoc ) {
		$assoc = is_array( $assoc ) ? $assoc : array();
		if ( isset( $assoc['page'] ) && ! isset( $assoc['page_id'] ) ) {
			$assoc['page_id'] = $assoc['page'];
		}
		$out    = isset( $assoc['out'] ) ? (string) $assoc['out'] : ( isset( $assoc['output'] ) ? (string) $assoc['output'] : '' );
		$result = self::run_cli( $assoc, $out );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( '' === $out ) {
			WP_CLI::line( $result );
			return;
		}
		WP_CLI::success( $out );
	}

	/**
	 * WP-CLI: `wp opti-behavior xray-report <page_id> [--period=…] [--device=…] [--output=<file.html>]` (name of OPT-1300).
	 *
	 * @param array $positional `<page_id>`.
	 * @param array $assoc      Options.
	 * @return void
	 */
	public static function cli_xray_report( $positional, $assoc ) {
		$assoc            = is_array( $assoc ) ? $assoc : array();
		$assoc['type']    = 'page';
		$assoc['page_id'] = isset( $positional[0] ) ? $positional[0] : ( isset( $assoc['page'] ) ? $assoc['page'] : 0 );
		self::cli_report( array(), $assoc );
	}

	/**
	 * Shared body of the CLI command (also used by `tools/report-html.php`
	 * where WP-CLI is not installed). Runs as the first administrator so the
	 * report reads what an administrator sees.
	 *
	 * @param array  $assoc Options.
	 * @param string $out   File to write ('' = return the HTML).
	 * @return string|WP_Error HTML, or the written path.
	 */
	public static function run_cli( $assoc, $out = '' ) {
		$args = self::clean_args( $assoc );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			if ( $admins ) {
				wp_set_current_user( (int) $admins[0] );
			}
		}
		$html = self::render_document( $args );
		if ( '' === $out ) {
			return $html;
		}
		if ( false === file_put_contents( $out, $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI output file chosen by the operator.
			return new WP_Error( 'opti_behavior_report_write', 'Cannot write ' . $out );
		}

		return $out;
	}

	/**
	 * A line chart as a static SVG (pure). 1 unit = 1 mm.
	 *
	 * Gaps: a null value breaks the line. Axis: 4-5 round graduations with a
	 * light grid on the left (`axis_ticks()`, the curve uses the same bounds),
	 * three dates (first, middle, last) at the bottom. Marks: dotted verticals with a
	 * numbered badge (WordPress changes). Every text is escaped. Without one
	 * numeric value: a sentence, never an empty chart.
	 *
	 * @param array $days   Y-m-d per point.
	 * @param array $values Number or null per point.
	 * @param array $marks  List of { i: index of the point, n: number shown, label }.
	 * @param float $w      Width (mm).
	 * @param float $h      Height (mm).
	 * @param array $opts   axis (bool, true), area (bool, false), area_color ('' = light brand tint; a #rrggbb = that colour, opaque: print-safe grey), dot (bool, false: dot on the last point), peak (bool, false: dot + value on the highest point), unit (string after min / max), title (accessible name), empty (sentence).
	 * @return string SVG markup, or a `<p>` sentence.
	 */
	public static function svg_line( array $days, array $values, array $marks = array(), $w = 178, $h = 36, $opts = array() ) {
		$opts   = array_merge(
			array(
				'axis'       => true,
				'area'       => false,
				'area_color' => '',
				'peak'       => false,
				'dot'        => false,
				'unit'  => '',
				'title' => '',
				'empty' => __( 'No data for this period.', 'opti-behavior' ),
			),
			is_array( $opts ) ? $opts : array()
		);
		$values = array_values( $values );
		$days   = array_values( $days );
		$nums   = array();
		foreach ( $values as $v ) {
			if ( null !== $v && is_numeric( $v ) ) {
				$nums[] = (float) $v;
			}
		}
		if ( empty( $nums ) ) {
			return '<p class="obr-empty">' . esc_html( (string) $opts['empty'] ) . '</p>';
		}
		$w   = max( 10.0, (float) $w );
		$h   = max( 6.0, (float) $h );
		$n     = count( $values );
		$min   = min( $nums );
		$max   = max( $nums );
		$ticks = array();
		if ( $opts['axis'] ) {
			// Round graduations (design OPT-1609): the curve is drawn on the
			// axis bounds, so every point sits on its label's scale.
			$ticks = self::axis_ticks( $nums, '%' === trim( (string) $opts['unit'] ) );
			$min   = $ticks[0];
			$max   = end( $ticks );
		}
		$gl = $opts['axis'] ? 11.0 : 0.5;
		$gb = $opts['axis'] ? 4.5 : 0.5;
		$gt = $marks ? 4.5 : ( $opts['axis'] ? 2.0 : 1.5 ); // Room above the plot for the badges / the top label.
		$pw = $w - $gl - 1.0;
		$ph = $h - $gb - $gt;
		$x  = function ( $i ) use ( $gl, $pw, $n ) {
			return $gl + ( $n > 1 ? $i / ( $n - 1 ) * $pw : $pw / 2 );
		};
		$y  = function ( $v ) use ( $gt, $ph, $min, $max ) {
			$v = min( $max, max( $min, (float) $v ) );
			return $gt + $ph - ( $max > $min ? ( $v - $min ) / ( $max - $min ) * $ph : $ph / 2 );
		};
		$f   = function ( $v ) {
			return sprintf( '%.2F', (float) $v );
		};

		$svg  = '<svg class="obr-chart" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $f( $w ) . ' ' . $f( $h ) . '" width="100%" role="img"';
		$svg .= '' !== (string) $opts['title'] ? ' aria-label="' . esc_attr( (string) $opts['title'] ) . '">' : ' aria-hidden="true">';
		if ( $opts['axis'] ) {
			// One light grid line + one label per graduation; the 0 line is the
			// darker base line (design OPT-1609).
			foreach ( $ticks as $t ) {
				$ty   = $y( $t );
				$svg .= 0.0 === (float) $t
					? '<line x1="' . $f( $gl ) . '" y1="' . $f( $ty ) . '" x2="' . $f( $gl + $pw ) . '" y2="' . $f( $ty ) . '" stroke="#94a3b8" stroke-width="0.3"/>'
					: '<line x1="' . $f( $gl ) . '" y1="' . $f( $ty ) . '" x2="' . $f( $gl + $pw ) . '" y2="' . $f( $ty ) . '" stroke="#e2e8f0" stroke-width="0.2"/>';
				$svg .= '<text x="' . $f( $gl - 1.5 ) . '" y="' . $f( $ty + 0.85 ) . '" font-size="2.4" text-anchor="end" fill="#475569">' . esc_html( self::tick_label( $t, (string) $opts['unit'] ) ) . '</text>';
			}
			$picks = array_unique( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) );
			foreach ( $picks as $k => $i ) {
				if ( ! isset( $days[ $i ] ) || ! strtotime( (string) $days[ $i ] ) ) {
					continue;
				}
				$anchor = 0 === $k ? 'start' : ( $i === $n - 1 ? 'end' : 'middle' );
				$svg   .= '<text x="' . $f( $x( $i ) ) . '" y="' . $f( $h - 0.6 ) . '" font-size="2.3" text-anchor="' . $anchor . '" fill="#475569">' . esc_html( date_i18n( 'j M', strtotime( (string) $days[ $i ] ) ) ) . '</text>';
			}
		}
		// One path per run of numeric values (a gap = no line).
		$runs = array();
		$run  = array();
		foreach ( $values as $i => $v ) {
			if ( null === $v || ! is_numeric( $v ) ) {
				if ( $run ) {
					$runs[] = $run;
				}
				$run = array();
				continue;
			}
			$run[] = array( $x( $i ), $y( $v ) );
		}
		if ( $run ) {
			$runs[] = $run;
		}
		foreach ( $runs as $points ) {
			if ( 1 === count( $points ) ) {
				$svg .= '<circle cx="' . $f( $points[0][0] ) . '" cy="' . $f( $points[0][1] ) . '" r="0.7" fill="#1e293b"/>';
				continue;
			}
			$d = '';
			foreach ( $points as $k => $p ) {
				$d .= ( 0 === $k ? 'M' : 'L' ) . $f( $p[0] ) . ' ' . $f( $p[1] );
			}
			if ( $opts['area'] ) {
				$base = $opts['axis'] ? $y( 0 ) : $gt + $ph;
				$fill = preg_match( '/^#[0-9a-f]{6}$/i', (string) $opts['area_color'] ) ? ' fill="' . $opts['area_color'] . '"' : ' fill="#4f46e5" fill-opacity="0.1"';
				$svg .= '<path d="' . $d . 'L' . $f( end( $points )[0] ) . ' ' . $f( $base ) . 'L' . $f( $points[0][0] ) . ' ' . $f( $base ) . 'Z"' . $fill . ' stroke="none"/>';
			}
			$svg .= '<path d="' . $d . '" fill="none" stroke="#1e293b" stroke-width="0.45" stroke-linejoin="round" stroke-linecap="round"/>';
		}
		if ( $opts['dot'] && $runs ) {
			$last = end( $runs );
			$p    = end( $last );
			$svg .= '<circle cx="' . $f( $p[0] ) . '" cy="' . $f( $p[1] ) . '" r="0.7" fill="#1e293b"/>';
		}
		if ( $opts['peak'] ) {
			$top = null;
			foreach ( $values as $i => $v ) {
				if ( null !== $v && is_numeric( $v ) && ( null === $top || (float) $v > (float) $values[ $top ] ) ) {
					$top = $i;
				}
			}
			$px   = $x( $top );
			$py   = $y( $values[ $top ] );
			$svg .= '<circle cx="' . $f( $px ) . '" cy="' . $f( $py ) . '" r="0.9" fill="#0f172a"/>';
			$svg .= '<text x="' . $f( min( max( $px, $gl + 3.0 ), $gl + $pw - 3.0 ) ) . '" y="' . $f( max( 2.2, $py - 1.6 ) ) . '" font-size="2.4" font-weight="700" text-anchor="middle" fill="#0f172a">' . esc_html( self::tick_label( (float) $values[ $top ], (string) $opts['unit'] ) ) . '</text>';
		}
		// Badges never overlap (design OPT-1301): at least 4 mm apart, pushed
		// left from the right edge when needed, each joined to its day by a
		// short leader.
		$placed = array();
		foreach ( $marks as $mark ) {
			if ( is_array( $mark ) && isset( $mark['i'] ) && (int) $mark['i'] >= 0 && (int) $mark['i'] < $n ) {
				$placed[] = array( $x( (int) $mark['i'] ), $mark );
			}
		}
		for ( $k = count( $placed ) - 1; $k >= 0; $k-- ) {
			$limit          = $k === count( $placed ) - 1 ? $gl + $pw - 1.8 : $placed[ $k + 1 ][0] - 4.0;
			$placed[ $k ][0] = min( $placed[ $k ][0], $limit );
		}
		// Then left to right: never over the axis labels (design OPT-1609).
		for ( $k = 0; $k < count( $placed ); $k++ ) {
			$floor          = 0 === $k ? $gl + 2.0 : $placed[ $k - 1 ][0] + 4.0;
			$placed[ $k ][0] = max( $placed[ $k ][0], $floor );
		}
		foreach ( $placed as $item ) {
			list( $bx, $mark ) = $item;
			$mx    = $x( (int) $mark['i'] );
			$label = isset( $mark['label'] ) ? (string) $mark['label'] : '';
			$svg  .= '<g>' . ( '' !== $label ? '<title>' . esc_html( $label ) . '</title>' : '' );
			$svg  .= '<line x1="' . $f( $mx ) . '" y1="' . $f( $gt ) . '" x2="' . $f( $mx ) . '" y2="' . $f( $gt + $ph ) . '" stroke="#c2410c" stroke-width="0.3" stroke-dasharray="0.6 0.7"/>';
			if ( isset( $mark['n'] ) && '' !== (string) $mark['n'] ) {
				if ( abs( $bx - $mx ) > 0.3 ) {
					$svg .= '<line x1="' . $f( $bx ) . '" y1="' . $f( $gt - 0.2 ) . '" x2="' . $f( $mx ) . '" y2="' . $f( $gt + 1.2 ) . '" stroke="#c2410c" stroke-width="0.25"/>';
				}
				$svg .= '<circle cx="' . $f( $bx ) . '" cy="' . $f( $gt - 0.6 ) . '" r="1.7" fill="#fff" stroke="#c2410c" stroke-width="0.3"/>';
				$svg .= '<text x="' . $f( $bx ) . '" y="' . $f( $gt + 0.15 ) . '" font-size="2.1" font-weight="700" text-anchor="middle" fill="#c2410c">' . esc_html( (string) $mark['n'] ) . '</text>';
			}
			$svg .= '</g>';
		}
		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * Mini curve of a tile (pure; design OPT-1301): 38 × 9 mm, line 0.35 mm,
	 * base line, dot on the last point, no axis (min / max are written under
	 * the tile). A null value is a gap. Nothing to draw = ''.
	 *
	 * @param array $values Number or null per day.
	 * @param float $w      Width (mm).
	 * @param float $h      Height (mm).
	 * @return string
	 */
	public static function svg_spark( array $values, $w = 38, $h = 9 ) {
		$values = array_values( $values );
		$nums   = array();
		foreach ( $values as $v ) {
			if ( null !== $v && is_numeric( $v ) ) {
				$nums[] = (float) $v;
			}
		}
		if ( empty( $nums ) ) {
			return '';
		}
		$w   = max( 4.0, (float) $w );
		$h   = max( 3.0, (float) $h );
		$n   = count( $values );
		$min = min( $nums );
		$max = max( $nums );
		$f   = function ( $v ) {
			return sprintf( '%.2F', (float) $v );
		};
		$x   = function ( $i ) use ( $w, $n ) {
			return 1.0 + ( $n > 1 ? ( $w - 2.0 ) * $i / ( $n - 1 ) : ( $w - 2.0 ) / 2 );
		};
		$y   = function ( $v ) use ( $h, $min, $max ) {
			return 1.0 + ( $h - 2.0 ) * ( 1 - ( $max > $min ? ( (float) $v - $min ) / ( $max - $min ) : 0.5 ) );
		};
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $f( $w ) . ' ' . $f( $h ) . '" aria-hidden="true">';
		$svg .= '<path d="M0 ' . $f( $h - 0.2 ) . 'H' . $f( $w ) . '" stroke="#94a3b8" stroke-width="0.2"/>';
		$seg  = array();
		$last = null;
		foreach ( array_merge( $values, array( null ) ) as $i => $v ) {
			if ( null === $v || ! is_numeric( $v ) ) {
				if ( count( $seg ) > 1 ) {
					$svg .= '<path d="M' . implode( ' ', $seg ) . '" fill="none" stroke="#1e293b" stroke-width="0.35" stroke-linejoin="round"/>';
				}
				$seg = array();
				continue;
			}
			$last  = array( $x( $i ), $y( $v ) );
			$seg[] = $f( $last[0] ) . ' ' . $f( $last[1] );
		}
		if ( $last ) {
			$svg .= '<circle cx="' . $f( $last[0] ) . '" cy="' . $f( $last[1] ) . '" r="0.7" fill="#1e293b"/>';
		}

		return $svg . '</svg>';
	}

	/**
	 * Score ring (pure; design OPT-1301): 30 mm, grey track, arc from the top in
	 * the colour of the health (fix / watch / good). The number is written next
	 * to it by the caller: the colour only repeats it.
	 *
	 * @param int    $score  0-100.
	 * @param string $health fix | watch | good.
	 * @return string
	 */
	public static function svg_ring( $score, $health ) {
		$colours = array(
			'fix'   => '#b91c1c',
			'watch' => '#d97706',
			'good'  => '#15803d',
		);
		$colour  = isset( $colours[ $health ] ) ? $colours[ $health ] : '#1e293b';
		$c       = 2 * M_PI * 13;
		$on      = $c * max( 0, min( 100, (int) $score ) ) / 100;

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30" aria-hidden="true">'
			. '<circle cx="15" cy="15" r="13" fill="none" stroke="#e2e8f0" stroke-width="2.6"/>'
			. '<circle cx="15" cy="15" r="13" fill="none" stroke="' . $colour . '" stroke-width="2.6" stroke-dasharray="' . sprintf( '%.2F %.2F', $on, $c ) . '" transform="rotate(-90 15 15)"/></svg>';
	}

	/**
	 * Horizontal bars with their share written (pure; black-and-white safe).
	 *
	 * @param array $rows List of { label, value, pct (0-100) }.
	 * @param float $w    Width (mm).
	 * @param array $opts row (mm per row, 6), title (accessible name), empty (sentence).
	 * @return string SVG markup, or a `<p>` sentence.
	 */
	public static function svg_bar( array $rows, $w = 120, $opts = array() ) {
		$opts = array_merge(
			array(
				'row'   => 6.0,
				'title' => '',
				'empty' => __( 'No data for this period.', 'opti-behavior' ),
			),
			is_array( $opts ) ? $opts : array()
		);
		$rows = array_values(
			array_filter(
				$rows,
				function ( $r ) {
					return is_array( $r ) && isset( $r['label'] );
				}
			)
		);
		if ( empty( $rows ) ) {
			return '<p class="obr-empty">' . esc_html( (string) $opts['empty'] ) . '</p>';
		}
		$f   = function ( $v ) {
			return sprintf( '%.2F', (float) $v );
		};
		$w   = max( 40.0, (float) $w );
		$rh  = max( 4.0, (float) $opts['row'] );
		$lw  = $w * 0.42;
		$bw  = $w - $lw - 12.0;
		$h   = $rh * count( $rows );
		$svg = '<svg class="obr-bars" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $f( $w ) . ' ' . $f( $h ) . '" width="100%" role="img"';
		$svg .= '' !== (string) $opts['title'] ? ' aria-label="' . esc_attr( (string) $opts['title'] ) . '">' : ' aria-hidden="true">';
		foreach ( $rows as $k => $r ) {
			$pct   = isset( $r['pct'] ) ? max( 0, min( 100, (float) $r['pct'] ) ) : 0;
			$top   = $k * $rh;
			$label = (string) $r['label'];
			if ( mb_strlen( $label ) > 40 ) {
				$label = mb_substr( $label, 0, 39 ) . '…';
			}
			$svg .= '<text x="0" y="' . $f( $top + $rh * 0.62 ) . '" font-size="2.6" fill="#1e293b">' . esc_html( $label ) . '</text>';
			$svg .= '<rect x="' . $f( $lw ) . '" y="' . $f( $top + $rh * 0.25 ) . '" width="' . $f( $bw ) . '" height="' . $f( $rh * 0.5 ) . '" fill="#e2e8f0"/>';
			$svg .= '<rect x="' . $f( $lw ) . '" y="' . $f( $top + $rh * 0.25 ) . '" width="' . $f( $bw * $pct / 100 ) . '" height="' . $f( $rh * 0.5 ) . '" fill="#4f46e5"/>';
			$svg .= '<text x="' . $f( $w ) . '" y="' . $f( $top + $rh * 0.62 ) . '" font-size="2.6" text-anchor="end" fill="#1e293b">' . esc_html( number_format_i18n( round( $pct ) ) . ' %' ) . '</text>';
		}
		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * Round graduations of a Y axis (pure; design OPT-1609).
	 *
	 * Step 1, 2, 2.5 or 5 × 10^n: the smallest giving at most 5 intervals.
	 * The axis always holds 0 (counts start at 0), a step is never a decimal
	 * when every value is whole (max 3 = 0, 1, 2, 3), a % axis stops at 100,
	 * nothing above 0 = 0 and 1.
	 *
	 * @param float[] $nums Numeric values.
	 * @param bool    $pct  Unit is %.
	 * @return float[] Graduations, lowest first.
	 */
	public static function axis_ticks( array $nums, $pct = false ) {
		$hi    = $nums ? max( 0.0, (float) max( $nums ) ) : 0.0;
		$lo    = $nums ? min( 0.0, (float) min( $nums ) ) : 0.0;
		$whole = true;
		foreach ( $nums as $v ) {
			if ( abs( (float) $v - round( (float) $v ) ) > 1e-9 ) {
				$whole = false;
				break;
			}
		}
		if ( $pct ) {
			$hi = min( $hi, 100.0 );
			$lo = max( $lo, -100.0 );
		}
		if ( $hi <= 0 && $lo >= 0 ) {
			return array( 0.0, 1.0 );
		}
		for ( $e = $whole ? 0 : -3; $e <= 12; $e++ ) {
			foreach ( array( 1, 2, 2.5, 5 ) as $m ) {
				$s = $m * pow( 10, $e );
				if ( $whole && abs( $s - round( $s ) ) > 1e-9 ) {
					continue;
				}
				$top = (int) ceil( $hi / $s - 1e-9 );
				$bot = (int) floor( $lo / $s + 1e-9 );
				if ( $top - $bot <= 5 ) {
					$ticks = array();
					for ( $i = $bot; $i <= $top; $i++ ) {
						$ticks[] = round( $i * $s, 6 );
					}
					return $ticks;
				}
			}
		}

		return array( $lo, $hi );
	}

	/**
	 * Label of a graduation (pure): 25, 2.5, 1.25k, 3M, + unit ("40%").
	 *
	 * @param float  $v    Value.
	 * @param string $unit Text after the number.
	 * @return string
	 */
	public static function tick_label( $v, $unit = '' ) {
		$v      = (float) $v;
		$suffix = '';
		if ( abs( $v ) >= 1000000 ) {
			$v      = $v / 1000000;
			$suffix = 'M';
		} elseif ( abs( $v ) >= 1000 ) {
			$v      = $v / 1000;
			$suffix = 'k';
		}
		$dec = 0;
		while ( $dec < 2 && abs( $v * pow( 10, $dec ) - round( $v * pow( 10, $dec ) ) ) > 1e-6 ) {
			++$dec;
		}

		return number_format_i18n( $v, $dec ) . $suffix . $unit;
	}

	/**
	 * "Page card" block, reusable by every report (pure): title, path without
	 * domain nor parameter, visits, one key figure, problem #1, picture when given.
	 *
	 * Optional (site report, OPT-1341): `rank` (number printed on the left),
	 * `figures` (more texts after the visits), `health` { points, key
	 * fix|watch|good, label, note } (box on the right + class
	 * `obr-card--<key>`), `problem_label` (small label before the problem),
	 * `problem_none` (the problem text is a "nothing found" line). The caller
	 * gives every text already translated.
	 *
	 * @param array $page { title, url, visits, figure: { label, value }, problem, thumb (data: address or '') }.
	 * @return string
	 */
	public static function page_card( $page ) {
		$page   = is_array( $page ) ? $page : array();
		$path   = isset( $page['url'] ) ? self::path_of( (string) $page['url'] ) : '';
		$title  = isset( $page['title'] ) && '' !== trim( (string) $page['title'] ) ? (string) $page['title'] : $path;
		$thumb  = isset( $page['thumb'] ) ? (string) $page['thumb'] : '';
		$health = isset( $page['health'] ) && is_array( $page['health'] ) ? $page['health'] : null;
		$key    = $health && isset( $health['key'] ) && in_array( $health['key'], array( 'fix', 'watch', 'good' ), true ) ? $health['key'] : '';
		$html   = '<div class="obr-card' . ( '' !== $key ? ' obr-card--' . $key : '' ) . '">';
		if ( isset( $page['rank'] ) && (int) $page['rank'] > 0 ) {
			$html .= '<span class="obr-card-rank">' . esc_html( number_format_i18n( (int) $page['rank'] ) ) . '</span>';
		}
		if ( '' !== $thumb && 0 === strpos( $thumb, 'data:image/' ) ) {
			$html .= '<img class="obr-card-thumb" src="' . esc_attr( $thumb ) . '" alt="">';
		}
		$html .= '<div class="obr-card-body"><strong class="obr-card-title">' . esc_html( $title ) . '</strong>';
		if ( '' !== $path && $path !== $title ) {
			$html .= '<span class="obr-card-path">' . esc_html( $path ) . '</span>';
		}
		$html .= '<ul class="obr-card-figures">';
		if ( isset( $page['visits'] ) ) {
			/* translators: %s: number of visits. */
			$html .= '<li>' . esc_html( sprintf( _n( '%s visit', '%s visits', (int) $page['visits'], 'opti-behavior' ), number_format_i18n( (int) $page['visits'] ) ) ) . '</li>';
		}
		if ( ! empty( $page['figure']['label'] ) ) {
			$html .= '<li><b>' . esc_html( isset( $page['figure']['value'] ) ? (string) $page['figure']['value'] : '' ) . '</b> ' . esc_html( (string) $page['figure']['label'] ) . '</li>';
		}
		foreach ( isset( $page['figures'] ) && is_array( $page['figures'] ) ? $page['figures'] : array() as $text ) {
			if ( is_scalar( $text ) && '' !== trim( (string) $text ) ) {
				$html .= '<li>' . esc_html( (string) $text ) . '</li>';
			}
		}
		$html .= '</ul>';
		if ( ! empty( $page['problem'] ) ) {
			$none  = ! empty( $page['problem_none'] );
			$label = ! $none && ! empty( $page['problem_label'] ) ? '<span class="xr-label">' . esc_html( (string) $page['problem_label'] ) . '</span>' : '';
			$html .= '<p class="obr-card-problem' . ( $none ? ' obr-card-problem--none' : '' ) . '">' . $label . esc_html( (string) $page['problem'] ) . '</p>';
		}
		$html .= '</div>';
		if ( $health && isset( $health['points'] ) && is_numeric( $health['points'] ) ) {
			$tags  = array(
				'fix'   => 'crit',
				'watch' => 'warn',
				'good'  => 'ok',
			);
			$html .= '<div class="obr-card-health"><b>' . esc_html( number_format_i18n( (int) round( (float) $health['points'] ) ) ) . '</b>';
			if ( ! empty( $health['note'] ) ) {
				$html .= '<small>' . esc_html( (string) $health['note'] ) . '</small>';
			}
			if ( ! empty( $health['label'] ) ) {
				$html .= '<span class="xr-tag' . ( '' !== $key ? ' xr-tag--' . $tags[ $key ] : '' ) . '">' . esc_html( (string) $health['label'] ) . '</span>';
			}
			$html .= '</div>';
		}

		return $html . '</div>';
	}

	/**
	 * Path of an address: no scheme, no domain, no parameter, no fragment.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function path_of( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		return '' !== $path ? $path : '/';
	}

	/**
	 * The A4 sheet shared by every report (inline in the document): the
	 * stylesheet of the validated design OPT-1301 (`qa/design-opt-1299/
	 * xray-report.css` of Pro), unchanged but for the footer words, which
	 * render_document() writes in the language of the report. Then the few
	 * classes of the engine itself (section wrapper, empty line, page card,
	 * chart). No web font, no remote file. Readable in black and white.
	 *
	 * @return string
	 */
	public static function css() {
		return <<<'CSS'
:root{--xr-ink:#0f172a;--xr-ink-2:#334155;--xr-muted:#475569;--xr-faint:#64748b;--xr-line:#cbd5e1;--xr-line-soft:#e2e8f0;--xr-paper:#fff;--xr-tint:#f8fafc;--xr-brand:#4f46e5;--xr-brand-2:#0ea5e9;--xr-brand-3:#9333ea;--xr-brand-soft:#eef2ff;--xr-accent:#c2410c;--xr-crit:#b91c1c;--xr-crit-soft:#fef2f2;--xr-warn:#92400e;--xr-warn-soft:#fffbeb;--xr-ok:#166534;--xr-ok-soft:#f0fdf4;--xr-font:"Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif}
@page{size:A4 portrait;margin:16mm 16mm 20mm;@bottom-left{font:600 7.5pt/1 "Segoe UI",system-ui,Arial,sans-serif;color:#475569;vertical-align:top;padding-top:5mm}@bottom-right{content:counter(page) " / " counter(pages);font:700 7.5pt/1 "Segoe UI",system-ui,Arial,sans-serif;color:#0f172a;vertical-align:top;padding-top:5mm}}
*{box-sizing:border-box}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{margin:0;background:#e2e8f0;color:var(--xr-ink);font:9.5pt/1.45 var(--xr-font);font-variant-numeric:tabular-nums}
h1,h2,h3,h4,p,ul,ol,dl,dd,figure{margin:0}
ul,ol{padding:0;list-style:none}
.xr-doc{width:210mm;margin:8mm auto;padding:16mm 16mm 20mm;background:var(--xr-paper);box-shadow:0 2px 14px rgba(15,23,42,.16)}
@media print{body{background:none}.xr-doc{width:auto;margin:0;padding:0;box-shadow:none}.xr-screen-only{display:none}}
@media screen and (max-width:840px){.xr-doc{zoom:.45;margin:4mm auto}}
p,li,dd{orphans:3;widows:3}
h2,h3,h4,.xr-sec-head{break-after:avoid;page-break-after:avoid}
.xr-keep,.xr-problem,.xr-figure,.xr-short,.xr-score,.xr-state,tr{break-inside:avoid;page-break-inside:avoid}
thead{display:table-header-group}
.xr-section{margin-top:7mm}
.xr-section--new-page{break-before:page;page-break-before:always;margin-top:0}
.xr-cover-band{height:2.2mm;margin:0 0 5mm;border-radius:1mm;background:linear-gradient(90deg,var(--xr-brand-2),var(--xr-brand) 50%,var(--xr-brand-3))}
.xr-brand{display:flex;align-items:center;gap:3mm}
.xr-brand img{width:11mm;height:11mm;display:block}
.xr-brand-name{font-size:12pt;font-weight:700;letter-spacing:.01em;line-height:1.1}
.xr-brand-doc{font-size:8pt;color:var(--xr-muted)}
.xr-brand-date{margin-left:auto;text-align:right;font-size:8pt;color:var(--xr-muted)}
.xr-brand-date b{display:block;color:var(--xr-ink);font-size:9pt}
.xr-brand-by{font-size:7.5pt;font-weight:600;color:var(--xr-brand);line-height:1.2;margin:0 0 .4mm}
.xr-brand-url{display:block;margin-top:.6mm;font-size:7.5pt;font-weight:600;color:var(--xr-brand);text-decoration:none}
.obr-foot{margin:7mm 0 0;padding-top:2mm;border-top:.5pt solid var(--xr-line-soft);font:600 7pt/1.3 "Segoe UI",system-ui,Arial,sans-serif;color:#64748b}
.obr-foot a{color:#4f46e5;text-decoration:none}
.obr-foot--small{font-weight:400;font-size:6.5pt;color:#94a3b8}
.obr-foot--small a{color:#64748b}
@page{@bottom-left{white-space:pre-wrap;line-height:1.55}}
.xr-title{margin-top:6mm}
.xr-kicker{font-size:7.5pt;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--xr-brand)}
.xr-title h1{margin-top:1mm;font-size:24pt;line-height:1.08;letter-spacing:-.015em}
.xr-title .xr-address{margin-top:1mm;font-size:9.5pt;color:var(--xr-muted)}
.xr-meta{display:grid;grid-template-columns:1.25fr 1.6fr 1fr 1fr;margin-top:4mm;border-top:1.5pt solid var(--xr-ink);border-bottom:.75pt solid var(--xr-line)}
.xr-meta>div{padding:2mm 2.5mm 2mm 0}
.xr-meta>div+div{padding-left:2.5mm;border-left:.5pt solid var(--xr-line)}
.xr-meta dt{font-size:6.5pt;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--xr-muted)}
.xr-meta dd{font-size:9pt;font-weight:600;margin-top:.5mm}
.xr-meta dd span{display:block;font-weight:400;font-size:7.5pt;color:var(--xr-muted)}
.xr-label{font-size:6.5pt;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--xr-muted)}
.xr-small{font-size:7.5pt;color:var(--xr-muted)}
.xr-tag{display:inline-block;padding:.2mm 1.6mm;border:.75pt solid currentColor;border-radius:.8mm;font-size:6.5pt;font-weight:700;letter-spacing:.06em;line-height:1.5;text-transform:uppercase;white-space:nowrap;vertical-align:.3mm}
.xr-tag--crit{color:var(--xr-crit);background:var(--xr-crit-soft)}
.xr-tag--warn{color:var(--xr-warn);background:var(--xr-warn-soft)}
.xr-tag--ok{color:var(--xr-ok);background:var(--xr-ok-soft)}
.xr-tag--info{color:var(--xr-brand);background:var(--xr-brand-soft)}
.xr-top{display:grid;grid-template-columns:52mm 1fr;gap:5mm;margin-top:5mm;align-items:stretch}
.xr-score{border:.75pt solid var(--xr-line);border-radius:2mm;padding:3.5mm 4mm;display:flex;flex-direction:column;gap:2.5mm}
.xr-ring{position:relative;width:30mm;height:30mm;margin:0 auto}
.xr-ring svg{display:block;width:100%;height:100%}
.xr-ring-val{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.xr-ring-val b{font-size:22pt;line-height:1;letter-spacing:-.02em}
.xr-ring-val span{font-size:6.5pt;color:var(--xr-muted);margin-top:.6mm}
.xr-score-verdict{text-align:center;font-size:9pt;font-weight:700}
.xr-score-lost{border-top:.5pt solid var(--xr-line);padding-top:2mm}
.xr-score-lost li{display:flex;justify-content:space-between;gap:2mm;font-size:8pt;padding:.4mm 0}
.xr-score-lost li b{white-space:nowrap}
.xr-score-pillars li{align-items:baseline;padding:.9mm 0 .9mm 1.8mm;border-left:.9mm solid transparent;border-bottom:.4pt solid var(--xr-line-soft)}
.xr-score-pillars li:last-child{border-bottom:0}
.xr-score-pillars li span{flex:none;max-width:58%}
.xr-score-pillars li.is-lost{border-left-color:var(--xr-crit);background:var(--xr-crit-soft)}
.xr-score-pillars li.is-lost span,.xr-score-pillars li.is-lost b{font-weight:700;color:var(--xr-crit)}
.xr-score-pillars li.is-lost b{padding-right:1mm;font-size:9pt}
.xr-score-pillars li.is-clear b{color:var(--xr-ok)}
.xr-score-pillars li.is-clear b::before{content:"\2713\00a0"}
.xr-score-pillars li.is-thin,.xr-score-pillars li.is-off{color:var(--xr-faint)}
.xr-score-pillars li.is-thin b,.xr-score-pillars li.is-off b{font-weight:400;font-style:italic;font-size:7pt;white-space:normal;text-align:right}
.xr-score-pillars li i{display:block;font-size:6.5pt;font-style:normal;color:var(--xr-muted)}
.xr-score .xr-ring{width:36mm;height:36mm;margin-top:auto}
.xr-score>:last-child{margin-bottom:auto}
.xr-score .xr-ring-val b{font-size:26pt}
.xr-areas{margin-top:4mm;break-inside:avoid;page-break-inside:avoid}
.xr-areas>.xr-label{margin-bottom:1.6mm}
.xr-areas ul{display:grid;gap:1.6mm}
.xr-areas li{min-width:0;display:flex;flex-direction:column;justify-content:space-between;gap:.8mm;padding:1.6mm 2.2mm 1.4mm;border:.5pt solid var(--xr-line);border-left:.9mm solid var(--xr-line);border-radius:1.2mm}
.xr-areas li span{font-size:7.5pt;font-weight:600;line-height:1.25;color:var(--xr-ink-2)}
.xr-areas li i{display:block;font-size:6.5pt;font-style:normal;font-weight:400;color:var(--xr-muted)}
.xr-areas li b{font-size:10pt;line-height:1.15;white-space:nowrap}
.xr-areas li.is-lost{border-color:var(--xr-crit);background:var(--xr-crit-soft)}
.xr-areas li.is-lost span,.xr-areas li.is-lost b{color:var(--xr-crit)}
.xr-areas li.is-lost b{font-size:13pt;letter-spacing:-.01em}
.xr-areas li.is-clear{border-left-color:var(--xr-ok)}
.xr-areas li.is-clear b{color:var(--xr-ok)}
.xr-areas li.is-clear b::before{content:"\2713\00a0"}
.xr-areas li.is-thin,.xr-areas li.is-off{background:var(--xr-tint);border-left-color:var(--xr-faint)}
.xr-areas li.is-thin span,.xr-areas li.is-off span,.xr-areas li.is-thin b,.xr-areas li.is-off b{color:var(--xr-faint)}
.xr-areas li.is-thin b,.xr-areas li.is-off b{font-size:7pt;font-weight:400;font-style:italic;line-height:1.25;white-space:normal}
.xr-areas li.xr-area-wide{grid-column:span 2}
.xr-short{border:1.25pt solid var(--xr-ink);border-radius:2mm;overflow:hidden;display:flex;flex-direction:column}
.xr-short-hd{padding:1.6mm 4mm;background:var(--xr-ink);color:#fff;font-size:7pt;font-weight:700;letter-spacing:.14em;text-transform:uppercase}
.xr-figures{flex:none;display:grid;grid-template-columns:repeat(3,1fr)}
.xr-figures:last-child{flex:1}
.xr-figures li{padding:3mm 4mm}
.xr-figures li+li{border-left:.5pt solid var(--xr-line)}
.xr-figures b{display:block;font-size:20pt;line-height:1.05;letter-spacing:-.02em}
.xr-figures span{display:block;font-size:8pt;color:var(--xr-ink-2);margin-top:1mm}
.xr-first{flex:1;display:flex;flex-direction:column;justify-content:center;padding:3mm 4mm;border-top:.5pt solid var(--xr-line);background:var(--xr-tint);border-left:1.6mm solid var(--xr-brand)}
.xr-first h3{font-size:11pt;margin-top:.8mm}
.xr-first p{font-size:8.5pt;color:var(--xr-ink-2);margin-top:1mm}
.xr-then{padding:2.5mm 4mm 2mm;border-top:.5pt solid var(--xr-line)}
.xr-then ol{list-style:none;margin:.6mm 0 0;padding:0}
.xr-then li{display:flex;align-items:flex-start;gap:2.5mm;padding:1.8mm 0;font-size:9pt}
.xr-then li p{font-size:8pt;color:var(--xr-ink-2);margin-top:.5mm}
.xr-then li+li{border-top:.4pt solid var(--xr-line-soft)}
.xr-then-no{flex:none;width:5.5mm;height:5.5mm;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;border:.75pt solid var(--xr-brand);color:var(--xr-brand);font-size:8pt;font-weight:700}
.xr-then li b{display:block;line-height:1.25}
.xr-then li span:not(.xr-then-no){display:block;font-size:7.5pt;color:var(--xr-muted);margin-top:.4mm}
.xr-history{margin-top:6mm}
.xr-sec-head{display:flex;align-items:baseline;gap:2.5mm;padding-bottom:1.5mm;border-bottom:1.5pt solid var(--xr-ink);margin-bottom:3mm}
.xr-sec-no{flex:none;min-width:6mm;height:6mm;padding:0 1mm;border-radius:1.2mm;display:inline-flex;align-items:center;justify-content:center;align-self:center;background:var(--xr-ink);color:#fff;font-size:8.5pt;font-weight:700}
.xr-sec-head h2{font-size:14pt;line-height:1.15;letter-spacing:-.01em}
.xr-sec-head .xr-aside{margin-left:auto;font-size:7.5pt;color:var(--xr-muted);text-align:right}
.xr-figure{border:.75pt solid var(--xr-line);border-radius:2mm;padding:2.5mm 3.5mm 2mm}
.xr-figure-top{display:flex;align-items:baseline;gap:3mm;margin-bottom:1mm}
.xr-figure-top h3{font-size:9.5pt}
.xr-legend{margin-left:auto;display:flex;gap:4mm;font-size:7pt;color:var(--xr-ink-2)}
.xr-legend span{display:inline-flex;align-items:center;gap:1.2mm;white-space:nowrap}
.xr-legend svg{width:6mm;height:2mm}
.xr-figure>svg{display:block;width:100%;height:auto}
.xr-figure figcaption{font-size:7pt;color:var(--xr-muted);margin-top:1mm}
.xr-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:2.5mm;margin-top:3mm;align-items:start}
.xr-tile{border:.75pt solid var(--xr-line);border-radius:1.6mm;padding:2mm 2.5mm}
.xr-tile b{display:block;font-size:14pt;line-height:1.1;margin-top:.6mm}
.xr-tile svg{display:block;width:100%;height:9mm;margin-top:1mm}
.xr-tile .xr-vs{font-size:7pt;color:var(--xr-muted);margin-top:.6mm}
.xr-tile .xr-vs b{display:inline;font-size:7pt;color:var(--xr-ink);margin:0}
.xr-problems{display:flex;flex-direction:column;gap:3.5mm}
.xr-problem{border:.75pt solid var(--xr-line);border-left:1.6mm solid var(--xr-ink-2);border-radius:1.6mm;padding:3mm 4mm 3mm 3.5mm}
.xr-problem--crit{border-left-color:var(--xr-crit)}
.xr-problem--warn{border-left-color:#d97706}
.xr-problem--watch{border-left-color:var(--xr-line)}
.xr-problem-hd{display:grid;grid-template-columns:8mm 1fr auto;gap:2mm;align-items:start}
.xr-problem-rank{font-size:15pt;font-weight:800;line-height:1;color:var(--xr-ink)}
.xr-problem-hd h3{font-size:10.5pt;line-height:1.25}
.xr-problem-hd p{font-size:8.5pt;color:var(--xr-ink-2);margin-top:.8mm}
.xr-problem-nums{display:flex;gap:4mm;text-align:right}
.xr-problem-nums b{display:block;font-size:13pt;line-height:1.05}
.xr-problem-nums span{font-size:6.5pt;color:var(--xr-muted);white-space:nowrap}
.xr-share{height:1.6mm;margin:2.5mm 0 0 10mm;background:var(--xr-line-soft);border-radius:.8mm;overflow:hidden}
.xr-share i{display:block;height:100%;background:var(--xr-ink-2)}
.xr-problem-body{display:grid;grid-template-columns:1fr 1fr;gap:4mm;margin:2.5mm 0 0 10mm}
.xr-problem-body dt{font-size:6.5pt;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--xr-muted)}
.xr-problem-body dd{font-size:8.5pt;margin-top:.6mm}
.xr-watch{margin:2.5mm 0 0 10mm;padding-top:2mm;border-top:.5pt dashed var(--xr-line);font-size:8.5pt}
.xr-watch .xr-label{margin-right:2mm}
.xr-watch .xr-none{color:var(--xr-muted)}
.xr-table{width:100%;border-collapse:collapse}
.xr-table th{text-align:left;font-size:6.5pt;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--xr-muted);padding:1.2mm 2mm 1.2mm 0;border-bottom:1pt solid var(--xr-ink)}
.xr-table td{padding:1.6mm 2mm 1.6mm 0;border-bottom:.5pt solid var(--xr-line-soft);vertical-align:top;font-size:8.5pt}
.xr-table tr:last-child td{border-bottom:.75pt solid var(--xr-line)}
.xr-table .xr-n{text-align:right;white-space:nowrap}
.xr-table .xr-nw{white-space:nowrap}
.xr-table td.xr-rank{font-weight:800;font-size:10pt;width:7mm}
.xr-table td p{font-size:7.5pt;color:var(--xr-muted);margin-top:.5mm}
.xr-effort{display:inline-flex;gap:.7mm;vertical-align:middle}
.xr-effort i{width:2.2mm;height:2.2mm;border:.75pt solid var(--xr-ink);border-radius:.3mm}
.xr-effort i.on{background:var(--xr-ink)}
.xr-bar{display:flex;align-items:center;gap:2mm}
.xr-bar span{flex:1;height:1.8mm;background:var(--xr-line-soft);border-radius:.9mm;overflow:hidden;min-width:18mm}
.xr-bar i{display:block;height:100%;background:var(--xr-ink-2)}
.xr-bar b{width:9mm;text-align:right}
.xr-note{margin-top:2mm;font-size:8pt;color:var(--xr-ink-2);padding:2mm 3mm;background:var(--xr-tint);border-left:.75mm solid var(--xr-line)}
.xr-two{display:grid;grid-template-columns:1fr 1fr;gap:6mm;align-items:start}
.xr-two h3{font-size:9.5pt;margin-bottom:1mm}
.xr-two h3 span{font-weight:400;color:var(--xr-muted);font-size:8pt}
.xr-heat{display:grid;grid-template-columns:1fr 30mm;gap:3mm;align-items:start}
.xr-heat svg{display:block;width:100%;height:auto;border:.5pt solid var(--xr-line)}
.xr-heat .xr-label{margin-bottom:1mm}
.xr-scale{display:flex;align-items:center;gap:2mm;margin-top:1.5mm;font-size:7pt;color:var(--xr-muted)}
.xr-scale i{width:32mm;height:2mm;border:.5pt solid var(--xr-line);background:linear-gradient(90deg,#bfdbfe,#facc15,#f97316,#dc2626,#7f1d1d)}
.xr-audit{display:grid;grid-template-columns:1fr 1fr;gap:4mm 6mm;align-items:start}
.xr-audit-group h3{display:flex;align-items:baseline;gap:2mm;font-size:10pt;padding-bottom:1mm;border-bottom:1pt solid var(--xr-ink)}
.xr-audit-group h3 .xr-score-n{margin-left:auto;font-size:10pt}
.xr-audit-group h3 .xr-small{font-weight:400}
.xr-checks li{break-inside:avoid;display:grid;grid-template-columns:16mm 1fr;gap:2mm;align-items:start;padding:1.4mm 0;border-bottom:.5pt solid var(--xr-line-soft);font-size:8pt}
.xr-checks li b{display:block;font-size:8.5pt}
.xr-checks li p{color:var(--xr-ink-2)}
.xr-checks li .xr-fix{margin-top:.6mm;color:var(--xr-ink)}
.xr-checks li .xr-fix::before{content:"→ ";font-weight:700}
.xr-checks .xr-tag{justify-self:start}
.xr-state{display:grid;grid-template-columns:6mm 1fr;gap:2mm;padding:2.5mm 3mm;border:.75pt dashed var(--xr-line);border-radius:1.6mm;font-size:8.5pt;color:var(--xr-ink-2)}
.xr-state::before{content:"i";width:4.5mm;height:4.5mm;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;border:.75pt solid var(--xr-muted);color:var(--xr-muted);font-weight:700;font-size:7pt;font-style:italic}
.xr-state b{color:var(--xr-ink)}
.xr-state--warn{border-style:solid;border-color:#d97706;background:var(--xr-warn-soft)}
.xr-state--warn::before{content:"!";font-style:normal;border-color:var(--xr-warn);color:var(--xr-warn)}
.xr-method{columns:2;column-gap:6mm;font-size:7.5pt;color:var(--xr-ink-2)}
.xr-method p{margin-bottom:1.5mm;break-inside:avoid}
.xr-method b{color:var(--xr-ink)}
.xr-heat img{display:block;max-width:100%;max-height:170mm;width:auto;height:auto;margin:0 auto;border:.5pt solid var(--xr-line)}
.obr-section{margin:0}
.obr-section>.xr-sec-head{margin-top:7mm}
.obr-empty,.obr-state{color:var(--xr-muted);font-size:8.5pt}
.obr-chart,.obr-bars{display:block;max-width:100%;height:auto}
.obr-card{display:flex;gap:3mm;border:.75pt solid var(--xr-line);border-radius:1.6mm;padding:2mm;break-inside:avoid}
.obr-card-thumb{width:30mm;height:20mm;object-fit:cover}
.obr-card-path{display:block;color:var(--xr-muted);font-size:8pt}
.obr-card-figures{margin:1mm 0;display:flex;gap:3mm}
.obr-toc{margin-top:5mm;border:.75pt solid var(--xr-line);border-radius:2mm;padding:2.5mm 4mm 2mm;break-inside:avoid}
.obr-toc ol{counter-reset:none}
.obr-toc li{display:grid;grid-template-columns:7mm auto 1fr auto;gap:2mm;align-items:baseline;padding:1.2mm 0;font-size:9pt}
.obr-toc li+li{border-top:.5pt solid var(--xr-line-soft)}
.obr-toc li>b:first-child{font-weight:800}
.obr-toc li>i{align-self:end;height:0;margin-bottom:1.2mm;border-bottom:.75pt dotted var(--xr-line)}
.obr-toc li>span{font-size:7.5pt;color:var(--xr-muted);white-space:nowrap}
.obr-toc .obr-toc-annex{padding-left:9mm;grid-template-columns:auto 1fr auto;font-size:8.5pt}
.obr-traffic .xr-tile>b{font-size:16pt}
.obr-traffic .xr-vs b.obr-down{color:var(--xr-crit)}
.obr-traffic .xr-vs b.obr-up{color:var(--xr-ok)}
.obr-traffic .xr-note{margin-top:2.5mm}
.obr-shares{margin-top:4mm}
.obr-shares .xr-table td{font-size:8.5pt}
.obr-shares .xr-bar span{min-width:14mm}
.obr-shares .xr-bar b{width:10mm;font-weight:600}
.obr-split{display:flex;height:12mm;margin-top:3mm;border:.75pt solid var(--xr-ink);border-radius:1.2mm;overflow:hidden}
.obr-split i{display:flex;flex-direction:column;justify-content:center;min-width:0;padding:0 1.5mm;font-style:normal;font-size:6.5pt;line-height:1.15;white-space:nowrap;overflow:hidden}
.obr-split i b{font-size:7pt}
.obr-split i+i{border-left:.75pt solid var(--xr-ink)}
.obr-split-1{background:var(--xr-ink-2);color:#fff}
.obr-split-2{background:var(--xr-line);color:var(--xr-ink)}
.obr-split-3{background:var(--xr-paper);color:var(--xr-ink)}
.obr-top-pages{margin-top:4mm}
.obr-top-pages h3{font-size:9.5pt;margin-bottom:1mm}
.obr-top-pages .xr-table td:nth-child(2){width:45%}
.obr-issues .xr-problem-nums b.obr-money{color:var(--xr-crit)}
.obr-issues .xr-problem-hd h3{font-size:10pt}
.obr-issues-more{margin-top:2.5mm}
.obr-more{margin-top:7mm;padding-top:2.5mm;border-top:.5pt solid var(--xr-line);font-size:8pt;color:var(--xr-muted);break-inside:avoid}
.obr-more b{color:var(--xr-ink-2);font-weight:600}
.obr-cards{display:grid;grid-template-columns:1fr 1fr;gap:2.5mm;align-items:stretch}
.obr-cards .obr-card{display:grid;grid-template-columns:6mm 1fr auto;gap:2mm;align-items:start;padding:2.2mm 2.5mm 2.2mm 2mm;border-left:1.2mm solid var(--xr-line)}
.obr-cards .obr-card--fix{border-left-color:var(--xr-crit)}
.obr-cards .obr-card--watch{border-left-color:#d97706}
.obr-cards .obr-card--good{border-left-color:#15803d}
.obr-card-rank{font-size:11pt;font-weight:800;line-height:1.1;text-align:center}
.obr-card-body{min-width:0}
.obr-card-title{display:block;font-size:9pt;line-height:1.25}
.obr-card-path{overflow-wrap:anywhere}
.obr-cards .obr-card-figures{gap:1mm;font-size:7.5pt;color:var(--xr-ink-2)}
.obr-card-figures li+li::before{content:"· ";color:var(--xr-muted)}
.obr-card-problem{font-size:7.5pt;line-height:1.35;color:var(--xr-ink-2)}
.obr-card-problem .xr-label{display:block;font-size:6pt}
.obr-card-problem--none{color:var(--xr-muted)}
.obr-card-health{text-align:center;min-width:12mm}
.obr-card-health b{display:block;font-size:14pt;line-height:1;letter-spacing:-.02em}
.obr-card-health small{display:block;font-size:6pt;color:var(--xr-muted);margin:.4mm 0 .8mm}
.obr-cards-note{margin-top:2.5mm}
.obr-annex{break-before:page;page-break-before:always}
.obr-annex-head{display:flex;align-items:center;gap:2.5mm;padding:1.6mm 3mm;border-radius:1.2mm;background:var(--xr-tint);border:.75pt solid var(--xr-line);font-size:8pt;color:var(--xr-ink-2)}
.obr-annex-head .xr-sec-no{background:var(--xr-brand)}
.obr-annex-head b{color:var(--xr-ink)}
.obr-annex-head span:last-child{margin-left:auto;color:var(--xr-muted)}
.obr-annex .xr-title{margin-top:4mm}
.obr-section--site-toc~.obr-section--site-issues{break-before:page;page-break-before:always}
.obr-annex-missing{margin-top:4mm}
/* New blocks of the site report (OPT-1680, design OPT-1666). */
.obr-summary .xr-tile>b{font-size:16pt}
.obr-summary .xr-vs b.obr-down{color:var(--xr-crit)}
.obr-summary .xr-vs b.obr-up{color:var(--xr-ok)}
.obr-summary .xr-note{margin-top:2.5mm}
.obr-summary .xr-first{margin-top:3mm;border:.75pt solid var(--xr-line);border-left:1.6mm solid var(--xr-brand);border-radius:1.6mm}
.obr-summary .xr-first h3{font-size:10pt}
.obr-do{display:grid;grid-template-columns:auto 1fr;gap:2mm;align-items:baseline;margin-top:2.5mm;padding:1.6mm 3mm;border-left:.9mm solid var(--xr-brand);background:var(--xr-brand-soft);border-radius:0 1.2mm 1.2mm 0;font-size:8.5pt;color:var(--xr-ink);break-inside:avoid}
.obr-do>b{font-size:6.5pt;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#4338ca;white-space:nowrap}
/* OPT-3054: Pro modules missing from this report, at the top of page 1. */
.obr-prowarn{padding:0;border:0;background:none}
.obr-prowarn__in{padding:3mm 3.5mm;border:.6pt solid #f0c070;border-left:1.2mm solid #e09b2d;border-radius:1mm;background:#fdf6e7;break-inside:avoid}
.obr-prowarn__t{display:flex;align-items:baseline;gap:2mm;margin:0 0 1mm;font-size:9pt;font-weight:700;color:#6b4306}
/* OPT-3055: PRO pill, same shape as .obr-pro-line, ink of the box. */
.obr-prowarn__badge{flex:none;padding:0 1.2mm;border:.6pt solid #d89b3c;border-radius:.8mm;background:#f7e3bd;font-size:6pt;font-weight:700;letter-spacing:.08em;line-height:1.7;color:#6b4306}
.obr-prowarn__l{margin:0;font-size:8.5pt;color:#6b4306}
.obr-prowarn__a{margin:2mm 0 0;font-size:8.5pt}
.obr-prowarn__a a{font-weight:600;color:#6b4306}
.obr-pro-line{display:flex;align-items:baseline;gap:2mm;margin-top:2.5mm;font-size:8pt;color:var(--xr-faint);break-inside:avoid}
.obr-pro-line>span:first-child{flex:none;padding:0 1.2mm;border:.6pt solid var(--xr-line);border-radius:.8mm;font-size:6pt;font-weight:700;letter-spacing:.08em;line-height:1.6}
.obr-pro-line b{font-weight:600;color:var(--xr-muted)}
.obr-pro-line--sec{margin-top:6mm;padding:2mm 0;border-top:.5pt solid var(--xr-line-soft);border-bottom:.5pt solid var(--xr-line-soft)}
.obr-signals{break-inside:avoid;page-break-inside:avoid}
.obr-signals a{color:var(--xr-brand);text-decoration:none}
.obr-signals .obr-pro-line--sec{margin-top:0;border-top:0;padding:1.6mm 0}
.obr-signals-table+.obr-pro-line--sec{border-top:0}
.obr-signals-table td:first-child{width:36mm;white-space:nowrap}
.obr-signals-table td:first-child .obr-tag{margin-right:1.5mm}
.obr-tag{display:inline-block;padding:0 1.2mm;border:.6pt solid var(--xr-line);border-radius:.8mm;font-size:6pt;font-weight:700;letter-spacing:.08em;line-height:1.6;color:var(--xr-faint);vertical-align:.3mm}
.obr-block{margin-top:4mm;break-inside:avoid}
.xr-sec-head+.obr-block{margin-top:0}
.xr-table span.xr-rank{font-weight:800;font-size:10pt}
.obr-facts .obr-split{margin-top:0;height:8mm}
.xr-table+.obr-facts{margin-top:0;border-top:0}
.obr-new .xr-table th.xr-n+th:not(.xr-n),.obr-new .xr-table td.xr-n+td:not(.xr-n){padding-left:5mm}
.obr-block>h3{display:flex;align-items:baseline;gap:2mm;font-size:9.5pt;margin-bottom:1mm}
.obr-block>h3 span{font-weight:400;font-size:8pt;color:var(--xr-muted)}
.obr-tiles-5{grid-template-columns:repeat(5,1fr)}
.obr-facts{border-top:.75pt solid var(--xr-line)}
.obr-facts li{display:grid;grid-template-columns:34mm 1fr;gap:3mm;align-items:baseline;padding:1.6mm 0;border-bottom:.5pt solid var(--xr-line-soft);font-size:8.5pt;break-inside:avoid}
.obr-facts li>b:first-child{font-size:6.5pt;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--xr-muted)}
.obr-facts li strong{font-size:10pt}
.obr-every.xr-section--new-page{break-before:auto;page-break-before:auto;margin-top:7mm}
.obr-src-type{font-size:7.5pt;color:var(--xr-muted)}
.obr-ref{display:inline-block;margin-left:1mm;padding:0 1.2mm;border:.6pt solid var(--xr-brand);border-radius:.8mm;font-size:6.5pt;font-weight:700;color:var(--xr-brand);white-space:nowrap;vertical-align:.2mm}
.obr-path{display:flex;flex-wrap:wrap;gap:.6mm 1mm;align-items:baseline}
.obr-path i{font-style:normal;color:var(--xr-faint)}
.obr-curve svg{display:block;width:100%;height:auto}
.obr-curve .xr-legend svg{width:6mm;height:2mm}
.obr-hours{margin-bottom:0}
.obr-hours svg{display:block;width:100%;height:auto}
.obr-clicks .xr-table td .obr-path{margin-top:.4mm;font-size:7.5pt;color:var(--xr-muted)}
.obr-err{font-family:Consolas,"Courier New",monospace;font-size:7.5pt;overflow-wrap:anywhere}
.obr-win{color:var(--xr-ok);font-weight:700}
.obr-traffic .obr-shares .xr-table td:first-child{width:38%}
.obr-split--low{overflow:visible;margin-bottom:4.5mm}
.obr-facts .obr-split--low{margin-bottom:4.5mm}
.obr-split--low i:first-child{border-radius:1.1mm 0 0 1.1mm}
.obr-split--low i:last-child{border-radius:0 1.1mm 1.1mm 0}
.obr-split i.is-narrow{position:relative;padding:0;overflow:visible}
.obr-split i.is-narrow>span{position:absolute;top:100%;right:0;margin-top:.9mm;color:var(--xr-ink);font-size:6.5pt;white-space:nowrap}
CSS;
	}
}
