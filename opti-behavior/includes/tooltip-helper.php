<?php
/**
 * Tooltip Helper Functions
 *
 * Provides helper functions to generate user-friendly tooltips
 * that explain features in simple terms for users of all ages.
 *
 * @package OptiBehavior
 * @version 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate a tooltip HTML element
 *
 * @param string $title       The tooltip title
 * @param string $content     The main tooltip content
 * @param string $simple      Optional simple explanation for younger users
 * @param string $example     Optional example
 * @param array  $options     Additional options (position, size, theme, icon)
 * @return string             HTML for the tooltip
 */
function opti_behavior_tooltip( $title, $content, $simple = '', $example = '', $options = array() ) {
	$defaults = array(
		'position' => 'top',      // top, bottom, left, right
		'size'     => 'default',  // sm, default, lg
		'theme'    => 'default',  // default, info, success, warning, danger
		'icon'     => '?',        // Default question mark, can be 'info', 'help', etc.
		'pro'      => false,      // Show PRO badge
		'align'    => 'center',   // center, left, right (for edge alignment)
	);

	$options = wp_parse_args( $options, $defaults );

	// Build CSS classes
	$classes = array( 'ob-tooltip' );

	if ( $options['position'] !== 'top' ) {
		$classes[] = 'ob-tooltip-' . esc_attr( $options['position'] );
	}

	if ( $options['size'] !== 'default' ) {
		$classes[] = 'ob-tooltip-' . esc_attr( $options['size'] );
	}

	if ( $options['theme'] !== 'default' ) {
		$classes[] = 'ob-tooltip-' . esc_attr( $options['theme'] );
	}

	// Add edge alignment class (for tooltips near viewport edges)
	if ( $options['align'] !== 'center' ) {
		$classes[] = 'ob-tooltip-align-' . esc_attr( $options['align'] );
	}

	$class_string = implode( ' ', $classes );

	// Build tooltip content
	ob_start();
	?><span class="<?php echo esc_attr( $class_string ); ?>" tabindex="0" role="button" aria-label="<?php echo esc_attr( $title ); ?>"><span class="ob-tooltip-icon" aria-hidden="true"><?php if ( $options['icon'] === 'info' ) : ?><i data-lucide="info"></i><?php elseif ( $options['icon'] === 'help' ) : ?><i data-lucide="help-circle"></i><?php else : ?>?<?php endif; ?></span><span class="ob-tooltip-content" role="tooltip"><span class="ob-tooltip-title"><?php echo esc_html( $title ); ?><?php if ( $options['pro'] ) : ?><span class="ob-tooltip-pro">PRO</span><?php endif; ?></span><span class="ob-tooltip-text"><?php echo wp_kses_post( $content ); ?></span><?php if ( ! empty( $simple ) ) : ?><span class="ob-tooltip-simple"><?php echo esc_html( $simple ); ?></span><?php endif; ?><?php if ( ! empty( $example ) ) : ?><span class="ob-tooltip-example"><?php echo esc_html( $example ); ?></span><?php endif; ?></span></span><?php
	return ob_get_clean();
}

/**
 * Echo a tooltip HTML element
 *
 * @param string $title       The tooltip title
 * @param string $content     The main tooltip content
 * @param string $simple      Optional simple explanation for younger users
 * @param string $example     Optional example
 * @param array  $options     Additional options
 */
function opti_behavior_tooltip_e( $title, $content, $simple = '', $example = '', $options = array() ) {
	echo opti_behavior_tooltip( $title, $content, $simple, $example, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in opti_behavior_tooltip function
}

/**
 * Get all dashboard widget tooltips
 *
 * Returns an array of tooltips for dashboard widgets with simple explanations
 *
 * @return array
 */
function opti_behavior_get_dashboard_tooltips() {
	return array(
		// Dashboard Overview
		'dashboard_overview' => array(
			'title'   => __( 'What is Traffic Overview?', 'opti-behavior' ),
			'content' => __( 'Traffic Overview gives you a complete overview of your website visitor behavior. Track visits, page views, session durations, scroll depth, and more — all in real time.', 'opti-behavior' ),
			'simple'  => __( 'Your central hub for understanding how visitors interact with your website.', 'opti-behavior' ),
		),
		// Stats Cards
		'visitors' => array(
			'title'   => __( 'What are Visitors?', 'opti-behavior' ),
			'content' => __( 'Visitor-number basis: counts unique, non-empty visitor IDs with at least one session in the selected date range. The same spam-exclusion setting used by the dashboard is applied, so this is the reconciliation total for visitor-based widgets.', 'opti-behavior' ),
			'simple'  => __( 'Unique visitors with sessions in the selected range.', 'opti-behavior' ),
			'example' => __( 'If 50 unique visitor IDs had sessions today, this shows "50".', 'opti-behavior' ),
		),
		'sessions' => array(
			'title'   => __( 'What are Sessions?', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts session rows/visits in the selected date range. One visitor can have multiple sessions, so this can be higher than Visitors.', 'opti-behavior' ),
			'simple'  => __( 'Total visits/sessions, including repeat visits by the same visitor.', 'opti-behavior' ),
			'example' => __( 'If you visit a site in the morning and again at night, that\'s 2 sessions.', 'opti-behavior' ),
		),
		'pageviews' => array(
			'title'   => __( 'What are Page Views?', 'opti-behavior' ),
			'content' => __( 'Pageview-record basis: counts tracked pageview records in the selected date range. Multiple page views can belong to the same session or visitor.', 'opti-behavior' ),
			'simple'  => __( 'Tracked pageview records in the selected range.', 'opti-behavior' ),
			'example' => __( 'Reading your homepage, then the about page = 2 page views.', 'opti-behavior' ),
		),
		'avg_session_time' => array(
			'title'   => __( 'What is Average Session Time?', 'opti-behavior' ),
			'content' => __( 'Session-number basis: averages session duration across matching sessions in the selected date range. Longer times usually mean your content is interesting!', 'opti-behavior' ),
			'simple'  => __( 'How long people usually stay on your website.', 'opti-behavior' ),
			'example' => __( '3m 45s means most people stay about 3 minutes and 45 seconds.', 'opti-behavior' ),
		),
		'avg_scroll_depth' => array(
			'title'   => __( 'What is Scroll Depth?', 'opti-behavior' ),
			'content' => __( 'Pageview/session activity basis: averages tracked scroll-depth records for matching activity in the selected date range. 100% means visitors scrolled to the bottom.', 'opti-behavior' ),
			'simple'  => __( 'How far people scroll down your pages (0% = top, 100% = bottom).', 'opti-behavior' ),
			'example' => __( '75% means most visitors read about three-quarters of your pages.', 'opti-behavior' ),
		),
		'bounce_rate' => array(
			'title'   => __( 'What is Bounce Rate?', 'opti-behavior' ),
			'content' => __( 'Session-number basis: the percentage of matching sessions that viewed only one page. A lower bounce rate usually means people are exploring more of your site.', 'opti-behavior' ),
			'simple'  => __( 'Share of sessions that leave after one page. Lower is better!', 'opti-behavior' ),
			'example' => __( '40% bounce rate means 4 out of 10 visitors only view one page.', 'opti-behavior' ),
		),

		// Dashboard Widgets
		'realtime_visitors' => array(
			'title'   => __( 'Real-time Visitors', 'opti-behavior' ),
			'content' => __( 'Live visitor-number basis: lists unique active visitors with recent session/page activity. This real-time count is separate from the selected date-range Visitors KPI.', 'opti-behavior' ),
			'simple'  => __( 'Unique active visitors right now.', 'opti-behavior' ),
			'example' => __( 'Shows their country, which page they\'re viewing, and when they arrived.', 'opti-behavior' ),
		),
		'realtime_map' => array(
			'title'   => __( 'Visitor Map', 'opti-behavior' ),
			'content' => __( 'Live visitor-number basis: maps unique active visitors by location. This real-time count is separate from the selected date-range Visitors KPI.', 'opti-behavior' ),
			'simple'  => __( 'A world map showing where your visitors are from.', 'opti-behavior' ),
		),
		'traffic_overview' => array(
			'title'   => __( 'Traffic Overview', 'opti-behavior' ),
			'content' => __( 'Session-number basis by default: shows traffic over time using session/visit counts, with companion visitor and pageview series where available. Compare the session series to the Sessions KPI.', 'opti-behavior' ),
			'simple'  => __( 'A graph showing visits/sessions over time.', 'opti-behavior' ),
			'example' => __( 'Peaks in the graph show your busiest days.', 'opti-behavior' ),
		),
		'top_engaged_users' => array(
			'title'   => __( 'Top Engaged Users', 'opti-behavior' ),
			'content' => __( 'Visitor-number basis: lists up to 10 unique visitors from the current Visitors cohort, capped so the row count never exceeds the Visitors KPI. Visitors are ranked by engagement signals such as total session time, sessions, and pages per session.', 'opti-behavior' ),
			'simple'  => __( 'Unique visitors from the Visitors total, ranked by engagement.', 'opti-behavior' ),
			'example' => __( 'Someone visiting daily for 10 minutes would be highly engaged.', 'opti-behavior' ),
		),
		'top_pages' => array(
			'title'   => __( 'Top Pages', 'opti-behavior' ),
			'content' => __( 'Pageview/session basis: ranks pages by tracked views and related session activity in the selected range, not by unique visitor count. The clock badge shows the average time visitors spend on each page (time on page, falling back to time until the session ended), computed with the same date range and filters as the other metrics.', 'opti-behavior' ),
			'simple'  => __( 'The pages on your website that people visit the most, with the average time spent on each.', 'opti-behavior' ),
		),
		'visitor_heatmap' => array(
			'title'   => __( 'Visitor Activity Heatmap', 'opti-behavior' ),
			'content' => __( 'Session-number basis: shows when sessions are most active in the selected range. Darker colors mean more session activity, not more unique visitors.', 'opti-behavior' ),
			'simple'  => __( 'Shows busiest days and hours by session activity.', 'opti-behavior' ),
			'example' => __( 'Dark squares = lots of visitors, light squares = fewer visitors.', 'opti-behavior' ),
		),
		'new_vs_returning' => array(
			'title'   => __( 'New vs Returning Visitors', 'opti-behavior' ),
			'content' => __( 'Visitor-number basis: compares unique visitors classified by stored visit count in the selected range, not total sessions. Counts should reconcile to the Visitors basis when the same filters are applied.', 'opti-behavior' ),
			'simple'  => __( 'Unique visitors split by new or returning classification.', 'opti-behavior' ),
			'example' => __( '60% new means most visitors are discovering your site for the first time.', 'opti-behavior' ),
		),
		'visited_directories' => array(
			'title'   => __( 'Visited Directories', 'opti-behavior' ),
			'content' => __( 'Pageview/session basis: groups tracked page views and related sessions by directory so section totals reconcile with page activity, not unique Visitors.', 'opti-behavior' ),
			'simple'  => __( 'Which directories receive the most page/session activity.', 'opti-behavior' ),
			'example' => __( '/blog/ being popular means people love your blog posts!', 'opti-behavior' ),
		),
		'visitor_auth' => array(
			'title'   => __( 'Visitor Authentication', 'opti-behavior' ),
			'content' => __( 'Visitor-number basis: counts unique visitors by login/authentication state for the selected date range, not the number of sessions.', 'opti-behavior' ),
			'simple'  => __( 'Unique visitors split by logged-in or guest status.', 'opti-behavior' ),
		),
		'traffic_classification' => array(
			'title'   => __( 'Traffic Classification', 'opti-behavior' ),
			'content' => __( 'Session-number basis: categorizes matching sessions by traffic source, so totals reconcile with Sessions when the same date range and spam setting are applied.', 'opti-behavior' ),
			'simple'  => __( 'How people found your website.', 'opti-behavior' ),
			'example' => __( '"Organic" means they found you through Google or another search engine.', 'opti-behavior' ),
		),
		'bot_traffic' => array(
			'title'   => __( 'Bot Traffic', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts sessions classified as bot, automated, spam, or human depending on your traffic settings. Totals reconcile with Sessions when the same filters are applied.', 'opti-behavior' ),
			'simple'  => __( 'Session counts split by human or bot/spam classification.', 'opti-behavior' ),
			'example' => __( 'Googlebot visits to add your pages to Google search results.', 'opti-behavior' ),
		),
		'user_intent' => array(
			'title'   => __( 'User Intent', 'opti-behavior' ),
			'content' => __( 'Session-number basis: classifies sessions by engagement level using time spent, pages viewed, and interactions. One visitor can contribute more than one classified session.', 'opti-behavior' ),
			'simple'  => __( 'How interested visitors are in your content.', 'opti-behavior' ),
			'example' => __( 'High intent visitors are more likely to buy or subscribe!', 'opti-behavior' ),
		),
		'referrers' => array(
			'title'   => __( 'Referrers', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts sessions by referrer or source. Repeat visits from the same visitor are counted as separate sessions.', 'opti-behavior' ),
			'simple'  => __( 'Other websites that link people to your site.', 'opti-behavior' ),
			'example' => __( 'If Facebook is listed, people clicked a link on Facebook to reach you.', 'opti-behavior' ),
		),
		'countries' => array(
			'title'   => __( 'Countries', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts matching sessions by visitor country within the selected range, so repeat visits can contribute multiple country counts.', 'opti-behavior' ),
			'simple'  => __( 'Which countries your visitors live in.', 'opti-behavior' ),
		),
		'browsers' => array(
			'title'   => __( 'Browsers', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts sessions by browser in the selected range. A returning visitor using the same browser can contribute multiple sessions.', 'opti-behavior' ),
			'simple'  => __( 'What programs people use to visit your website.', 'opti-behavior' ),
			'example' => __( 'If 60% use Chrome, make sure your site looks great in Chrome!', 'opti-behavior' ),
		),
		'device_types' => array(
			'title'   => __( 'Device Types', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts sessions by device type in the selected range, not unique visitors.', 'opti-behavior' ),
			'simple'  => __( 'Whether people visit from phones, tablets, or computers.', 'opti-behavior' ),
		),
		'operating_systems' => array(
			'title'   => __( 'Operating Systems', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts sessions by operating system in the selected range, not unique visitors.', 'opti-behavior' ),
			'simple'  => __( 'The type of computer or phone system your visitors have.', 'opti-behavior' ),
		),
		'screen_resolution' => array(
			'title'   => __( 'Screen Resolution', 'opti-behavior' ),
			'content' => __( 'Session-number basis: counts sessions by screen resolution in the selected range, not unique visitors.', 'opti-behavior' ),
			'simple'  => __( 'How big or small your visitors\' screens are.', 'opti-behavior' ),
			'example' => __( '1920x1080 is a common computer screen size.', 'opti-behavior' ),
		),
	);
}

/**
 * Get all settings tooltips
 *
 * Returns an array of tooltips for settings with simple explanations
 *
 * @return array
 */
function opti_behavior_get_settings_tooltips() {
	return array(
		// Heatmap Settings
		'tracking_accuracy' => array(
			'title'   => __( 'Tracking Accuracy', 'opti-behavior' ),
			'content' => __( 'Choose how precisely clicks are recorded. High accuracy captures clicks only from common screen sizes. Standard captures from a wider range but may be less precise.', 'opti-behavior' ),
			'simple'  => __( 'How exact the click tracking is. High = more precise, Standard = works on more screens.', 'opti-behavior' ),
		),
		'non_singular' => array(
			'title'   => __( 'Non-Singular Pages', 'opti-behavior' ),
			'content' => __( 'Choose whether heatmaps are also recorded on archive pages (categories, tags, authors, dates, search results and /page/2 listings). Each archive URL becomes its own heatmap, which can add thousands of low-value heatmaps. Visits, sessions and traffic stats are counted on every page whatever you choose.', 'opti-behavior' ),
			'simple'  => __( 'Should listing pages like categories and tags get their own heatmap? Most sites only need posts, pages and products.', 'opti-behavior' ),
		),
		'ajax_delay' => array(
			'title'   => __( 'Ajax Delay Time', 'opti-behavior' ),
			'content' => __( 'How long to wait before sending tracking data to the server. Higher values reduce server load but may miss data if visitors leave quickly.', 'opti-behavior' ),
			'simple'  => __( 'Wait time before saving click data. Higher = less server work, but might miss quick visitors.', 'opti-behavior' ),
			'example' => __( '3000ms = 3 seconds wait time.', 'opti-behavior' ),
		),
		'drawing_points' => array(
			'title'   => __( 'Drawing Points Limit', 'opti-behavior' ),
			'content' => __( 'Maximum number of data points to show on heatmaps. More points give more detail but may slow down loading. Fewer points load faster.', 'opti-behavior' ),
			'simple'  => __( 'How many clicks to show on heatmaps. More = detailed but slower.', 'opti-behavior' ),
		),
		'count_bar' => array(
			'title'   => __( 'Count Bar Display', 'opti-behavior' ),
			'content' => __( 'Show a bar with click counts at the edge of heatmaps. This helps you see the total number of interactions at each scroll depth.', 'opti-behavior' ),
			'simple'  => __( 'Show numbers along the side of heatmaps telling you how many clicks happened there.', 'opti-behavior' ),
		),
		'url_hash' => array(
			'title'   => __( 'URL Hash Handling', 'opti-behavior' ),
			'content' => __( 'URLs can have #sections (like #about or #contact). Integrated combines all hash variations together. Individual keeps them separate for more detailed analysis.', 'opti-behavior' ),
			'simple'  => __( 'How to handle page links with # symbols. Integrated = combine them, Individual = keep separate.', 'opti-behavior' ),
		),

		// Dashboard Settings
		'date_format' => array(
			'title'   => __( 'Date Format', 'opti-behavior' ),
			'content' => __( 'Choose how dates are displayed throughout the dashboard. Select the format that\'s most familiar to you.', 'opti-behavior' ),
			'simple'  => __( 'How dates appear (month/day/year or day/month/year, etc.).', 'opti-behavior' ),
		),
		'spam_detection' => array(
			'title'   => __( 'Spam Detection', 'opti-behavior' ),
			'content' => __( 'Automatically identify and filter out fake or bot traffic. This helps ensure your analytics only show real human visitors.', 'opti-behavior' ),
			'simple'  => __( 'Automatically hide fake visitors and bots from your stats.', 'opti-behavior' ),
		),
		'data_retention' => array(
			'title'   => __( 'Data Retention', 'opti-behavior' ),
			'content' => __( 'How long to keep analytics data. Longer retention means more historical data but uses more storage space.', 'opti-behavior' ),
			'simple'  => __( 'How long we save your visitor data before deleting it.', 'opti-behavior' ),
			'example' => __( '30 days keeps the last month of data.', 'opti-behavior' ),
		),

		// Intent Rules
		'low_intent' => array(
			'title'   => __( 'Low Intent Visitors', 'opti-behavior' ),
			'content' => __( 'Visitors who quickly glance at your site without much interaction. Short visit time, few clicks, minimal scrolling.', 'opti-behavior' ),
			'simple'  => __( 'People who look at your site briefly and leave quickly.', 'opti-behavior' ),
		),
		'medium_intent' => array(
			'title'   => __( 'Medium Intent Visitors', 'opti-behavior' ),
			'content' => __( 'Visitors showing moderate interest. They spend some time reading, click a few things, and scroll through content.', 'opti-behavior' ),
			'simple'  => __( 'People who are somewhat interested and look around a bit.', 'opti-behavior' ),
		),
		'high_intent' => array(
			'title'   => __( 'High Intent Visitors', 'opti-behavior' ),
			'content' => __( 'Your most engaged visitors! They spend significant time, interact with multiple elements, and scroll through most of your content.', 'opti-behavior' ),
			'simple'  => __( 'People who are very interested and explore your site thoroughly.', 'opti-behavior' ),
		),

		// Storage & Maintenance
		'storage_stats' => array(
			'title'   => __( 'Storage Statistics', 'opti-behavior' ),
			'content' => __( 'See how much disk space your analytics data is using in the database. Large websites may need more storage.', 'opti-behavior' ),
			'simple'  => __( 'How much computer space your visitor data takes up.', 'opti-behavior' ),
		),
		'file_storage' => array(
			'title'   => __( 'File Storage', 'opti-behavior' ),
			'content' => __( 'Shows where heatmap screenshots and recordings are stored, and how much space they\'re using.', 'opti-behavior' ),
			'simple'  => __( 'Where pictures and recordings of your heatmaps are saved.', 'opti-behavior' ),
		),

		// Traffic Classification Settings
		'bot_detection' => array(
			'title'   => __( 'Bot Detection', 'opti-behavior' ),
			'content' => __( 'Automatically identify search engine crawlers, social media bots, and SEO tools visiting your site. These are separated from human traffic in your analytics.', 'opti-behavior' ),
			'simple'  => __( 'Find and separate robot visitors from real people.', 'opti-behavior' ),
		),
		'custom_bot_patterns' => array(
			'title'   => __( 'Custom Bot Patterns', 'opti-behavior' ),
			'content' => __( 'Add your own patterns to identify bots that aren\'t automatically detected. Enter the user agent string that identifies the bot (one per line).', 'opti-behavior' ),
			'simple'  => __( 'Add names of bots we should watch for.', 'opti-behavior' ),
			'example' => __( 'MyCustomBot or CompanyScanner', 'opti-behavior' ),
		),
		'automated_traffic' => array(
			'title'   => __( 'Automated Traffic Detection', 'opti-behavior' ),
			'content' => __( 'Detect visits from automated tools like Selenium, Puppeteer, or curl scripts. These are often used for testing or scraping and aren\'t real user visits.', 'opti-behavior' ),
			'simple'  => __( 'Find traffic from computer programs pretending to be browsers.', 'opti-behavior' ),
		),
		'spam_detection_setting' => array(
			'title'   => __( 'Spam Traffic Detection', 'opti-behavior' ),
			'content' => __( 'Spam traffic means suspicious sessions with too little real engagement, such as visits that are extremely short or have no scrolls or clicks. Modern search engines and LLM/AI tools can use crawlers or browser-like fetchers that create fake traffic signals, distorting browser, device, source, and session stats. Filtering helps you see clean analytics from real humans.', 'opti-behavior' ),
			'simple'  => __( 'Find suspicious low-engagement visits so your reports focus on real people.', 'opti-behavior' ),
		),
		'spam_duration' => array(
			'title'   => __( 'Spam Duration Threshold', 'opti-behavior' ),
			'content' => __( 'Sessions shorter than this time may be flagged as spam. Real visitors typically stay at least a few seconds to read content.', 'opti-behavior' ),
			'simple'  => __( 'Minimum time a real visitor would spend on your site.', 'opti-behavior' ),
			'example' => __( '10 seconds means visits under 10 seconds might be spam.', 'opti-behavior' ),
		),
		'spam_min_scrolls' => array(
			'title'   => __( 'Minimum Scrolls for Legitimate Traffic', 'opti-behavior' ),
			'content' => __( 'Minimum number of scroll events required to consider a session as legitimate. Real visitors typically scroll through page content.', 'opti-behavior' ),
			'simple'  => __( 'How many times a real visitor would scroll on your page.', 'opti-behavior' ),
		),
		'spam_min_clicks' => array(
			'title'   => __( 'Minimum Clicks for Legitimate Traffic', 'opti-behavior' ),
			'content' => __( 'Minimum number of click events required to consider a session as legitimate. Real visitors usually click on links, buttons, or other elements.', 'opti-behavior' ),
			'simple'  => __( 'How many times a real visitor would click on your page.', 'opti-behavior' ),
		),

		// Excluded IPs
		'excluded_ips' => array(
			'title'   => __( 'Excluded IP Addresses', 'opti-behavior' ),
			'content' => __( 'Visitors from these IP addresses are completely ignored: no tracking scripts are loaded for them (heatmaps, behavior, A/B tests, funnels, session recordings, form analytics, error tracking). Supports exact IPv4/IPv6 addresses, CIDR ranges and IPv4 wildcards, one entry per line.', 'opti-behavior' ),
			'simple'  => __( 'Block tracking for specific IPs, like your own or your team\'s, so they never appear in analytics.', 'opti-behavior' ),
			'example' => __( '192.168.1.10, 203.0.113.0/24 or 10.0.1.*', 'opti-behavior' ),
		),

		// Admin Tracking
		'track_admin' => array(
			'title'   => __( 'Track Admin Users', 'opti-behavior' ),
			'content' => __( 'When checked, admin users are tracked by heatmaps, session recordings, error tracking, and form analytics. Uncheck to exclude admin visits from your analytics so only real visitor data is collected.', 'opti-behavior' ),
			'simple'  => __( 'Track admin visits or exclude them from analytics.', 'opti-behavior' ),
		),

		// User Intent Rules
		'time_spent' => array(
			'title'   => __( 'Time Spent Threshold', 'opti-behavior' ),
			'content' => __( 'Minimum seconds a visitor spends on your site. Longer times indicate more interest and engagement with your content.', 'opti-behavior' ),
			'simple'  => __( 'How long someone stays before showing this level of interest.', 'opti-behavior' ),
		),
		'clicks_threshold' => array(
			'title'   => __( 'Clicks Threshold', 'opti-behavior' ),
			'content' => __( 'Minimum number of clicks during the session. More clicks usually mean the visitor is actively exploring your site.', 'opti-behavior' ),
			'simple'  => __( 'How many times someone clicks to show this level of interest.', 'opti-behavior' ),
		),
		'scroll_depth_threshold' => array(
			'title'   => __( 'Scroll Depth Threshold', 'opti-behavior' ),
			'content' => __( 'Minimum percentage of page scrolled. Higher scroll depths mean visitors are reading more of your content.', 'opti-behavior' ),
			'simple'  => __( 'How far down the page someone scrolls to show interest.', 'opti-behavior' ),
		),

		// Language Settings
		'admin_language' => array(
			'title'   => __( 'Admin Language', 'opti-behavior' ),
			'content' => __( 'Choose the language for the Opti-Behavior plugin interface. This only affects this plugin\'s text, not your WordPress admin or other plugins.', 'opti-behavior' ),
			'simple'  => __( 'What language to show plugin menus and messages in.', 'opti-behavior' ),
		),

		// Debug & Logging Settings
		'php_debug' => array(
			'title'   => __( 'PHP Debug Logging', 'opti-behavior' ),
			'content' => __( 'Record PHP errors and messages to help diagnose server-side issues. Enable only when troubleshooting as it can affect performance.', 'opti-behavior' ),
			'simple'  => __( 'Save technical error messages to help fix problems.', 'opti-behavior' ),
		),
		'log_level' => array(
			'title'   => __( 'Log Level', 'opti-behavior' ),
			'content' => __( 'Choose how much detail to record. Error = critical issues only. Debug = everything including minor messages. Higher levels create larger log files.', 'opti-behavior' ),
			'simple'  => __( 'How much detail to save in the log file.', 'opti-behavior' ),
		),
		'log_to_file' => array(
			'title'   => __( 'Log to File', 'opti-behavior' ),
			'content' => __( 'Save log messages to a file on your server. You can download and review these logs to diagnose issues.', 'opti-behavior' ),
			'simple'  => __( 'Save messages to a file you can read later.', 'opti-behavior' ),
		),
		'log_to_console' => array(
			'title'   => __( 'Log to Console', 'opti-behavior' ),
			'content' => __( 'Output debug messages to your browser\'s developer console. Useful for real-time debugging during AJAX requests.', 'opti-behavior' ),
			'simple'  => __( 'Show messages in your browser\'s developer tools.', 'opti-behavior' ),
		),
		'js_debug' => array(
			'title'   => __( 'JavaScript Debug Logging', 'opti-behavior' ),
			'content' => __( 'Output JavaScript debug messages to your browser console. Helps diagnose issues with tracking, heatmaps, or recordings.', 'opti-behavior' ),
			'simple'  => __( 'Show JavaScript messages in browser developer tools.', 'opti-behavior' ),
		),

		// Storage Stats
		'database_size' => array(
			'title'   => __( 'Database Size', 'opti-behavior' ),
			'content' => __( 'Total space used by all Opti-Behavior database tables. Includes click events, sessions, visitors, heatmaps, and recordings data.', 'opti-behavior' ),
			'simple'  => __( 'How much space your analytics data takes in the database.', 'opti-behavior' ),
		),
		'total_tables' => array(
			'title'   => __( 'Total Tables', 'opti-behavior' ),
			'content' => __( 'Number of database tables created by Opti-Behavior. Each table stores a different type of analytics data.', 'opti-behavior' ),
			'simple'  => __( 'How many data containers the plugin uses.', 'opti-behavior' ),
		),
		'total_records' => array(
			'title'   => __( 'Total Records', 'opti-behavior' ),
			'content' => __( 'Total number of data entries across all tables. More records mean more detailed analytics but also use more storage.', 'opti-behavior' ),
			'simple'  => __( 'How many pieces of data have been saved.', 'opti-behavior' ),
		),

		// Scheduled Reports
		'scheduled_reports' => array(
			'title'   => __( 'Scheduled Reports', 'opti-behavior' ),
			'content' => __( 'Set up automatic email reports with your analytics data. Get daily, weekly, or monthly summaries delivered to your inbox.', 'opti-behavior' ),
			'simple'  => __( 'Receive regular email updates about your website traffic.', 'opti-behavior' ),
		),

		// Session Recordings Settings (PRO)
		'recording_enabled' => array(
			'title'   => __( 'Enable Session Recording', 'opti-behavior' ),
			'content' => __( 'When enabled, the plugin records visitor sessions (mouse movements, clicks, scrolls) so you can replay them later. Helps understand how users interact with your site.', 'opti-behavior' ),
			'simple'  => __( 'Record what visitors do on your site so you can watch it later.', 'opti-behavior' ),
		),
		'max_duration' => array(
			'title'   => __( 'Max Recording Duration', 'opti-behavior' ),
			'content' => __( 'Maximum length of a single session recording in seconds. Longer recordings use more storage space. Most useful interactions happen in the first few minutes.', 'opti-behavior' ),
			'simple'  => __( 'How long each recording can be before it stops.', 'opti-behavior' ),
			'example' => __( '600 seconds = 10 minutes maximum per recording.', 'opti-behavior' ),
		),
		'sample_rate' => array(
			'title'   => __( 'Mouse Movement Sample Rate', 'opti-behavior' ),
			'content' => __( 'How often (in milliseconds) to capture mouse position. Lower values give smoother playback but create larger files. Higher values save space but may look choppy.', 'opti-behavior' ),
			'simple'  => __( 'How smooth the mouse recording looks. Lower = smoother but more data.', 'opti-behavior' ),
		),
		'mask_inputs' => array(
			'title'   => __( 'Mask All Input Fields', 'opti-behavior' ),
			'content' => __( 'Replace all text typed in form fields with asterisks (***) in recordings. Protects passwords, emails, and personal information from being captured.', 'opti-behavior' ),
			'simple'  => __( 'Hide what people type in forms for privacy protection.', 'opti-behavior' ),
		),
		'record_canvas' => array(
			'title'   => __( 'Record Canvas Elements', 'opti-behavior' ),
			'content' => __( 'Capture content from HTML5 canvas and WebGL elements (charts, graphs, animations). Disable if your site has sensitive canvas content or to reduce recording size.', 'opti-behavior' ),
			'simple'  => __( 'Include charts and animations in recordings.', 'opti-behavior' ),
		),
		'storage_mode' => array(
			'title'   => __( 'Storage Type', 'opti-behavior' ),
			'content' => __( 'Choose where to save recording data. File storage is faster and recommended for high-traffic sites. Database storage is the legacy method and works on any hosting.', 'opti-behavior' ),
			'simple'  => __( 'Where to save recordings: in files (faster) or database (legacy).', 'opti-behavior' ),
		),
		'compression' => array(
			'title'   => __( 'Compression', 'opti-behavior' ),
			'content' => __( 'Compress recording files with gzip to save 80-90% disk space. Recommended to enable unless your server has limited CPU.', 'opti-behavior' ),
			'simple'  => __( 'Squeeze files smaller to save space on your server.', 'opti-behavior' ),
		),
		'retention_days' => array(
			'title'   => __( 'Retention Period', 'opti-behavior' ),
			'content' => __( 'Number of days to keep recording data before automatic deletion. Older recordings are removed to free up space. Set higher for long-term analysis.', 'opti-behavior' ),
			'simple'  => __( 'How many days to keep recordings before deleting them.', 'opti-behavior' ),
		),
		'auto_cleanup' => array(
			'title'   => __( 'Auto Cleanup', 'opti-behavior' ),
			'content' => __( 'Automatically delete old recording files every day based on the retention period setting. Keeps your storage usage under control without manual intervention.', 'opti-behavior' ),
			'simple'  => __( 'Automatically delete old recordings every day.', 'opti-behavior' ),
		),
		'max_storage_mb' => array(
			'title'   => __( 'Maximum Storage Size', 'opti-behavior' ),
			'content' => __( 'Set a maximum disk space limit for recordings. When exceeded, the oldest files are automatically deleted. Prevents your storage from growing indefinitely.', 'opti-behavior' ),
			'simple'  => __( 'Maximum space recordings can use before old ones are deleted.', 'opti-behavior' ),
		),

		// Danger Zone
		'delete_date_range' => array(
			'title'   => __( 'Delete by Date Range', 'opti-behavior' ),
			'content' => __( 'Remove analytics data collected within a specific time period while keeping everything else. Useful for cleaning up test data or old records without losing recent analytics.', 'opti-behavior' ),
			'simple'  => __( 'Erase data from a specific time period only.', 'opti-behavior' ),
			'theme'   => 'warning',
		),

		// Debug & Danger
		'debug_mode' => array(
			'title'   => __( 'Debug Mode', 'opti-behavior' ),
			'content' => __( 'Enable detailed logging for troubleshooting. Only turn this on when diagnosing problems, as it can slow down your site.', 'opti-behavior' ),
			'simple'  => __( 'Extra detailed information for fixing problems. Keep off normally.', 'opti-behavior' ),
			'theme'   => 'warning',
		),
		'delete_all_data' => array(
			'title'   => __( 'Delete All Data', 'opti-behavior' ),
			'content' => __( 'Permanently removes ALL analytics data including sessions, heatmaps, and visitor information. This cannot be undone!', 'opti-behavior' ),
			'simple'  => __( 'Erases everything forever. Be very careful!', 'opti-behavior' ),
			'theme'   => 'danger',
		),

		// Smart Data Cleanup
		'smart_cleanup' => array(
			'title'   => __( 'Smart Data Cleanup', 'opti-behavior' ),
			'content' => __( 'Intelligently identify and remove low-quality, spam, or outdated session data using configurable conditions. Helps keep your database lean and your analytics meaningful without losing valuable visitor insights.', 'opti-behavior' ),
			'simple'  => __( 'Automatically find and remove junk data to keep things fast.', 'opti-behavior' ),
			'theme'   => 'danger',
		),
		'bot_spam_cleanup' => array(
			'title'   => __( 'Bot & Spam Cleanup', 'opti-behavior' ),
			'content' => __( 'Detects sessions from known bots, crawlers, and spam sources based on user-agent analysis. Removing these keeps your analytics accurate and frees up database space used by non-human traffic.', 'opti-behavior' ),
			'simple'  => __( 'Remove fake visits from robots and spam bots.', 'opti-behavior' ),
			'theme'   => 'danger',
		),
		'conditional_cleanup' => array(
			'title'   => __( 'Conditional Cleanup', 'opti-behavior' ),
			'content' => __( 'Define custom rules to target specific session types for removal. Conditions use OR logic — any matching condition triggers deletion. Use Preview to see how many sessions match before deleting. The age filters ensure recent data is preserved.', 'opti-behavior' ),
			'simple'  => __( 'Set rules to find and remove unwanted session data.', 'opti-behavior' ),
			'theme'   => 'warning',
		),
		'scheduled_auto_cleanup' => array(
			'title'   => __( 'Scheduled Auto-Cleanup', 'opti-behavior' ),
			'content' => __( 'Runs the conditional cleanup rules automatically on a set schedule (daily, weekly, or monthly). Once enabled, it keeps your database optimized without manual intervention. Check the Cleanup History to monitor what was removed.', 'opti-behavior' ),
			'simple'  => __( 'Automatically clean junk data on a regular schedule.', 'opti-behavior' ),
			'theme'   => 'warning',
		),
		'cleanup_history' => array(
			'title'   => __( 'Cleanup History', 'opti-behavior' ),
			'content' => __( 'Shows a log of recent cleanup operations with the date, type (manual, scheduled, or bot), and number of sessions removed. Helps you verify that automated cleanups are working correctly.', 'opti-behavior' ),
			'simple'  => __( 'A log of past cleanups so you can see what was removed.', 'opti-behavior' ),
		),
		'conditions_time_based' => array(
			'title'   => __( 'Time-Based Conditions', 'opti-behavior' ),
			'content' => __( 'Target sessions based on their age or visitor inactivity. "Sessions older than X days" removes old data beyond a threshold. "Inactive visitors" removes sessions from visitors who haven\'t returned within the specified period.', 'opti-behavior' ),
			'simple'  => __( 'Remove old sessions or data from visitors who never came back.', 'opti-behavior' ),
		),
		'conditions_quality_based' => array(
			'title'   => __( 'Quality-Based Conditions', 'opti-behavior' ),
			'content' => __( 'Target sessions based on engagement quality. Filter by duration (too short = bots, too long = idle tabs), event count (too few = no interaction, too many = automated), page views, or bounce rate. Age filters protect recent data from deletion.', 'opti-behavior' ),
			'simple'  => __( 'Remove sessions with poor engagement like very short visits or no clicks.', 'opti-behavior' ),
		),
		'conditions_visitor_behavior' => array(
			'title'   => __( 'Visitor Behavior Conditions', 'opti-behavior' ),
			'content' => __( 'Target visitors based on their browsing patterns. "Single-visit visitors" removes sessions from one-time visitors who haven\'t returned after the specified number of days, helping clean up drive-by traffic.', 'opti-behavior' ),
			'simple'  => __( 'Remove data from visitors who only came once and never returned.', 'opti-behavior' ),
		),
		'delete_on_uninstall' => array(
			'title'   => __( 'Delete on Uninstall', 'opti-behavior' ),
			'content' => __( 'Choose whether to keep or delete all plugin data when you uninstall the plugin. Keeping data lets you restore it if you reinstall later.', 'opti-behavior' ),
			'simple'  => __( 'Should we delete everything when you remove the plugin?', 'opti-behavior' ),
		),

		// Privacy & GDPR Settings
		'privacy_mode' => array(
			'title'   => __( 'Privacy Mode', 'opti-behavior' ),
			'content' => __( 'Privacy Mode controls how visitor data is collected, stored, and processed. Anonymous Mode is fully GDPR-compliant by default and requires no cookie consent banner. Full Tracking offers more detailed analytics but requires visitor consent under GDPR.', 'opti-behavior' ),
			'simple'  => __( 'Choose between privacy-first (no cookies, no client-side storage) or full tracking (cookies + consent required).', 'opti-behavior' ),
		),
		'anonymous_mode' => array(
			'title'   => __( 'Anonymous Mode', 'opti-behavior' ),
			'content' => __( 'In Anonymous Mode, no cookies are set and no client-side storage is used, and no IP addresses are stored. Visitors are identified using a daily rotating hash that cannot be linked back to an individual. No geolocation API calls are made. This mode is GDPR-compliant by default and does not require a cookie consent banner.', 'opti-behavior' ),
			'simple'  => __( 'Tracks visitors without cookies, client-side storage, or personal data. Safe by default — no consent popup needed.', 'opti-behavior' ),
			'example' => __( 'Great for blogs, news sites, or any site that wants simple analytics without legal complexity.', 'opti-behavior' ),
		),
		'full_tracking' => array(
			'title'   => __( 'Full Tracking Mode', 'opti-behavior' ),
			'content' => __( 'Full Tracking mode sets persistent cookies to identify returning visitors, stores IP addresses, and may call external geolocation APIs to determine visitor location. This provides more accurate visitor counts and richer data, but requires a cookie consent banner under GDPR, ePrivacy, and similar regulations.', 'opti-behavior' ),
			'simple'  => __( 'More detailed tracking using cookies and IP addresses. Requires a consent banner for GDPR compliance.', 'opti-behavior' ),
			'example' => __( 'Best for e-commerce or membership sites that need accurate visitor identification.', 'opti-behavior' ),
		),
		'consent_banner' => array(
			'title'   => __( 'Consent Banner', 'opti-behavior' ),
			'content' => __( 'In Full Tracking mode, a cookie consent banner must be shown to visitors before any tracking cookies are set. Opti-Behavior includes a built-in banner, but will automatically defer to any of 8 supported third-party consent management plugins if one is detected active on your site.', 'opti-behavior' ),
			'simple'  => __( 'A popup asking visitors if they allow cookies. Required by law in many countries when using Full Tracking.', 'opti-behavior' ),
			'example' => __( 'Supported plugins: Cookiebot, GDPR Cookie Consent, CookieYes, Cookie Notice, Complianz, Borlabs Cookie, WP Cookie Notice, and GDPR Compliance.', 'opti-behavior' ),
		),

		// Privacy & GDPR - Consent Banner Settings
		'consent_banner_source' => array(
			'title'   => __( 'Consent Banner Source', 'opti-behavior' ),
			'content' => __( 'Choose which consent banner to show visitors. Auto-detect will use a third-party consent plugin if one is active, otherwise falls back to the built-in Opti-Behavior banner. Choose "Always use built-in" to always show the Opti-Behavior banner.', 'opti-behavior' ),
			'simple'  => __( 'Which cookie popup to show: auto-detect a plugin or always use our built-in one.', 'opti-behavior' ),
		),
		'banner_position' => array(
			'title'   => __( 'Banner Position', 'opti-behavior' ),
			'content' => __( 'Choose where the consent banner appears on the page. Compact Card shows a smaller professional card, Bottom Bar shows a fixed full-width bar at the bottom, Top Bar at the top, and Centered Popup shows a modal dialog with a backdrop overlay.', 'opti-behavior' ),
			'simple'  => __( 'Where the cookie popup appears: compact card, bottom, top, or center of the screen.', 'opti-behavior' ),
		),
		'banner_colors' => array(
			'title'   => __( 'Banner Colors', 'opti-behavior' ),
			'content' => __( 'Customize the consent banner appearance to match your site branding. Set the accent color (buttons and links), background color, and text color. Leave defaults for a neutral look that works with most designs.', 'opti-behavior' ),
			'simple'  => __( 'Change the colors of the cookie popup to match your website design.', 'opti-behavior' ),
		),
		'banner_title' => array(
			'title'   => __( 'Banner Title', 'opti-behavior' ),
			'content' => __( 'The heading text shown on the consent banner. Leave empty to use the default translatable title. Custom titles override the translation system.', 'opti-behavior' ),
			'simple'  => __( 'The big text at the top of the cookie popup. Leave blank for the default.', 'opti-behavior' ),
		),
		'banner_message' => array(
			'title'   => __( 'Banner Message', 'opti-behavior' ),
			'content' => __( 'The main message explaining why cookies are used. Leave empty to use the default translatable message. This text should clearly explain what data is collected and why.', 'opti-behavior' ),
			'simple'  => __( 'The explanation text in the cookie popup. Leave blank for the default message.', 'opti-behavior' ),
		),
		'banner_button_text' => array(
			'title'   => __( 'Banner Button Text', 'opti-behavior' ),
			'content' => __( 'Translate or customize the visible consent action buttons. These fields change the exact button text visitors see in the banner. Leave a field empty to use the default translated label for the current site language.', 'opti-behavior' ),
			'simple'  => __( 'Change the button labels or leave them empty to use translated defaults.', 'opti-behavior' ),
		),
		'banner_customize_button' => array(
			'title'   => __( 'Customize Button', 'opti-behavior' ),
			'content' => __( 'Text for the button that opens the detailed cookie preference panel. Use this field to translate the word "Customize" or replace it with wording that matches your site language and tone.', 'opti-behavior' ),
			'simple'  => __( 'The label for the button that opens cookie preferences.', 'opti-behavior' ),
		),
		'banner_reject_button' => array(
			'title'   => __( 'Reject Button', 'opti-behavior' ),
			'content' => __( 'Text for the button that rejects analytics cookies. Use clear wording so visitors understand this declines tracking cookies. Leave empty to use the default translated label.', 'opti-behavior' ),
			'simple'  => __( 'The label for the reject/decline button.', 'opti-behavior' ),
		),
		'banner_accept_button' => array(
			'title'   => __( 'Accept Button', 'opti-behavior' ),
			'content' => __( 'Text for the primary button that grants analytics cookie consent. Leave empty to use the default translated "Accept All" label, or enter your own translated text.', 'opti-behavior' ),
			'simple'  => __( 'The label for the accept/allow button.', 'opti-behavior' ),
		),
		'advanced_banner_text' => array(
			'title'   => __( 'Advanced Banner Text', 'opti-behavior' ),
			'content' => __( 'Customize secondary text shown in the banner and inside the cookie preferences panel. These fields are useful when translating the banner into another language or when your legal wording requires different labels.', 'opti-behavior' ),
			'simple'  => __( 'Extra labels and helper text for translations and legal wording.', 'opti-behavior' ),
		),
		'banner_policy_label' => array(
			'title'   => __( 'Policy Link Label', 'opti-behavior' ),
			'content' => __( 'The visible text for the policy link inside the banner message. Change this to translate "Cookie Policy" or to match your legal page title.', 'opti-behavior' ),
			'simple'  => __( 'The text visitors click to open your cookie or privacy policy.', 'opti-behavior' ),
		),
		'banner_policy_url' => array(
			'title'   => __( 'Policy Link URL', 'opti-behavior' ),
			'content' => __( 'The URL opened by the policy link. Use your Cookie Policy, Privacy Policy, or legal consent page. Leave empty to use the WordPress privacy policy URL when available.', 'opti-behavior' ),
			'simple'  => __( 'The page URL for your cookie or privacy policy.', 'opti-behavior' ),
		),
		'banner_customize_panel_label' => array(
			'title'   => __( 'Customize Panel Label', 'opti-behavior' ),
			'content' => __( 'The label shown beside the analytics cookie toggle inside the Customize panel. Translate this text so visitors understand what category they are enabling or disabling.', 'opti-behavior' ),
			'simple'  => __( 'The label for the analytics cookie toggle.', 'opti-behavior' ),
		),
		'banner_save_choice_button' => array(
			'title'   => __( 'Save Choice Button', 'opti-behavior' ),
			'content' => __( 'Text for the button that saves the visitor preference selected inside the Customize panel. Leave empty to use the default translated label.', 'opti-behavior' ),
			'simple'  => __( 'The button label for saving a custom cookie choice.', 'opti-behavior' ),
		),
		'banner_customize_panel_description' => array(
			'title'   => __( 'Customize Panel Description', 'opti-behavior' ),
			'content' => __( 'Short explanatory text shown under the analytics cookie toggle. Use this to explain what analytics cookies do in the visitor language.', 'opti-behavior' ),
			'simple'  => __( 'Helper text explaining the analytics cookie toggle.', 'opti-behavior' ),
		),

		// Frontend Stats Bar Settings
		'enable_stats_bar' => array(
			'title'   => __( 'Enable Stats Bar', 'opti-behavior' ),
			'content' => __( 'Show a compact analytics bar at the top of every frontend page. This bar is only visible to logged-in administrators and loads asynchronously so it has zero impact on page speed for regular visitors.', 'opti-behavior' ),
			'simple'  => __( 'Show a small stats bar at the top of your site. Only you (the admin) can see it.', 'opti-behavior' ),
		),
		'hide_in_builders' => array(
			'title'   => __( 'Hide in Page Builders', 'opti-behavior' ),
			'content' => __( 'Automatically hide the stats bar when a page is opened inside a visual page builder editor (Elementor, Divi, Beaver Builder, WPBakery, Brizy, Oxygen, Breakdance, SiteOrigin, Visual Composer, Live Composer, MotoPress, Themify, and the Customizer). Prevents the bar from covering the builder interface while you edit.', 'opti-behavior' ),
			'simple'  => __( 'Keep the stats bar out of the way while you edit pages with a page builder.', 'opti-behavior' ),
		),
		'stats_period' => array(
			'title'   => __( 'Stats Period', 'opti-behavior' ),
			'content' => __( 'The time range for statistics displayed in the frontend bar. Choose from last 7, 14, or 30 days. Shorter periods show more recent trends, longer periods show broader patterns.', 'opti-behavior' ),
			'simple'  => __( 'How far back to count the numbers shown in the stats bar.', 'opti-behavior' ),
		),
		'color_theme' => array(
			'title'   => __( 'Color Theme', 'opti-behavior' ),
			'content' => __( 'Choose a color theme for the frontend stats bar that matches your website design. Available themes: Dark, Light, Blue, Green, Purple, and Orange.', 'opti-behavior' ),
			'simple'  => __( 'Pick a color style for the stats bar that looks good on your site.', 'opti-behavior' ),
		),
		'visible_stats' => array(
			'title'   => __( 'Visible Stats', 'opti-behavior' ),
			'content' => __( 'Select which statistics to display in the frontend bar. Free stats include Visitors, Sessions, Page Views, Avg. Session Time, Scroll Depth, and Bounce Rate. Pro stats add Recordings, JS Errors, Friction Events, and Form Submissions.', 'opti-behavior' ),
			'simple'  => __( 'Choose which numbers to show in the stats bar.', 'opti-behavior' ),
		),

		// Scheduled Reports Settings
		'email_delivery_method' => array(
			'title'   => __( 'Email Delivery Method', 'opti-behavior' ),
			'content' => __( 'Choose how report emails are sent. WordPress Default uses wp_mail() which works on most hosts. Custom SMTP lets you configure an external mail service like Gmail, Outlook, or SendGrid for more reliable delivery.', 'opti-behavior' ),
			'simple'  => __( 'How to send report emails: built-in WordPress mail or an external email service.', 'opti-behavior' ),
		),
		'from_name_email' => array(
			'title'   => __( 'Sender Name & Email', 'opti-behavior' ),
			'content' => __( 'Set the sender name and email address for report emails. The From Name appears in the recipient inbox. The From Email should be a valid address on your domain. Leave blank to use the WordPress admin email.', 'opti-behavior' ),
			'simple'  => __( 'Who the report emails appear to come from. Leave blank to use your admin email.', 'opti-behavior' ),
		),
		'report_schedules' => array(
			'title'   => __( 'Report Schedules', 'opti-behavior' ),
			'content' => __( 'Manage your automated report schedules. Each schedule defines when to send, what period to cover, and who receives the report. You can create multiple schedules with different frequencies and recipients.', 'opti-behavior' ),
			'simple'  => __( 'Your list of automatic report email schedules. Click + New Schedule to add one.', 'opti-behavior' ),
		),
		'report_history' => array(
			'title'   => __( 'Recent Report History', 'opti-behavior' ),
			'content' => __( 'View the history of recently sent reports including delivery status (sent or failed), date, and recipient count. Failed reports may indicate email configuration issues.', 'opti-behavior' ),
			'simple'  => __( 'A log of past report emails showing if they were sent successfully.', 'opti-behavior' ),
		),

		// License & Quota Settings (PRO)
		'license_status' => array(
			'title'   => __( 'License Status', 'opti-behavior' ),
			'content' => __( 'Shows your Pro license details including the unique Installation ID, license type, registered domain, masked license key, and days remaining before expiration. Keep your license key private.', 'opti-behavior' ),
			'simple'  => __( 'Your Pro license information and how many days are left.', 'opti-behavior' ),
		),
		'monthly_quota' => array(
			'title'   => __( 'Monthly Quota Usage', 'opti-behavior' ),
			'content' => __( 'Track your session recording usage for the current billing month. Shows how many recordings have been used, how many remain, and your plan limit. Quota resets at the start of each month.', 'opti-behavior' ),
			'simple'  => __( 'How many session recordings you have used this month out of your plan limit.', 'opti-behavior' ),
		),
		'detailed_analytics' => array(
			'title'   => __( 'Detailed Analytics', 'opti-behavior' ),
			'content' => __( 'Breakdown of recording-related activity: Sessions Recorded (total recordings captured), Encryption Keys Generated (security keys for replay), and Sessions Viewed (how many recordings have been watched). Quota is based on Sessions Recorded only.', 'opti-behavior' ),
			'simple'  => __( 'Detailed numbers about your session recordings, encryption, and playback activity.', 'opti-behavior' ),
		),

		// Debug & Logging - Log File Settings
		'custom_log_path' => array(
			'title'   => __( 'Custom Log Path', 'opti-behavior' ),
			'content' => __( 'Set a custom directory path where log files will be stored. Must be an absolute server path. Leave empty to use the default WordPress uploads directory (wp-content/uploads).', 'opti-behavior' ),
			'simple'  => __( 'Where to save log files on your server. Leave blank for the default location.', 'opti-behavior' ),
		),
		'log_folder_name' => array(
			'title'   => __( 'Log Folder Name', 'opti-behavior' ),
			'content' => __( 'The folder name within the log path where log files are stored. This folder is created automatically if it does not exist. Default: opti-behavior-logs.', 'opti-behavior' ),
			'simple'  => __( 'Name of the folder where log files go.', 'opti-behavior' ),
		),
		'max_log_size' => array(
			'title'   => __( 'Max Log File Size', 'opti-behavior' ),
			'content' => __( 'Maximum size in megabytes for a single log file. When a log file exceeds this size, it is automatically rotated (renamed with a timestamp) and a new empty log file is started. Prevents log files from growing indefinitely.', 'opti-behavior' ),
			'simple'  => __( 'How big a log file can get before starting a new one.', 'opti-behavior' ),
		),
		'auto_cleanup_logs' => array(
			'title'   => __( 'Auto-Cleanup Old Logs', 'opti-behavior' ),
			'content' => __( 'Automatically delete old rotated log files after a specified number of days. Keeps your server storage tidy by removing outdated debug information that is no longer useful.', 'opti-behavior' ),
			'simple'  => __( 'Automatically delete old log files to save space.', 'opti-behavior' ),
		),
		'cleanup_after_days' => array(
			'title'   => __( 'Cleanup After Days', 'opti-behavior' ),
			'content' => __( 'Number of days to keep old log files before they are automatically deleted. Only applies when Auto-Cleanup is enabled. Lower values save more space, higher values keep more history for debugging.', 'opti-behavior' ),
			'simple'  => __( 'How many days to keep old log files before deleting them.', 'opti-behavior' ),
		),
	);
}

/**
 * Get all heatmaps page tooltips
 *
 * @return array
 */
function opti_behavior_get_heatmaps_tooltips() {
	return array(
		'heatmap_overview' => array(
			'title'   => __( 'What is a Heatmap?', 'opti-behavior' ),
			'content' => __( 'A heatmap shows where visitors click on your pages using colors. Red/orange = lots of clicks (hot), Blue/green = fewer clicks (cold). It\'s like a thermal image of visitor activity!', 'opti-behavior' ),
			'simple'  => __( 'A colorful picture showing where people click. Red = popular, Blue = not popular.', 'opti-behavior' ),
		),

		// Heatmaps Stats Cards
		'total_heatmaps' => array(
			'title'   => __( 'Total Heatmaps', 'opti-behavior' ),
			'content' => __( 'The number of pages currently being tracked with heatmaps. Each page with click data has its own heatmap visualization.', 'opti-behavior' ),
			'simple'  => __( 'How many pages have heatmap tracking enabled.', 'opti-behavior' ),
		),
		'total_clicks' => array(
			'title'   => __( 'Total Clicks', 'opti-behavior' ),
			'content' => __( 'The combined number of clicks recorded across all your heatmaps. More clicks mean more data for understanding visitor behavior.', 'opti-behavior' ),
			'simple'  => __( 'All the clicks recorded on all your tracked pages combined.', 'opti-behavior' ),
		),
		'total_sessions' => array(
			'title'   => __( 'Total Sessions', 'opti-behavior' ),
			'content' => __( 'The total number of visitor sessions recorded across your site, with spam, bot, and automated traffic excluded when the global spam filter is on. The percentage below shows what share of these sessions started this week.', 'opti-behavior' ),
			'simple'  => __( 'How many visits your site received in total (spam excluded).', 'opti-behavior' ),
		),
		'avg_time_on_page' => array(
			'title'   => __( 'Avg. Time on Page', 'opti-behavior' ),
			'content' => __( 'The average time visitors spend on pages with heatmaps. Longer times usually mean more engagement and more meaningful click data.', 'opti-behavior' ),
			'simple'  => __( 'How long people typically stay on your tracked pages.', 'opti-behavior' ),
		),
		'hottest_page' => array(
			'title'   => __( 'Hottest Page', 'opti-behavior' ),
			'content' => __( 'The page with the most click interactions. This is your most actively engaged content - great for learning what works!', 'opti-behavior' ),
			'simple'  => __( 'The page where people click the most.', 'opti-behavior' ),
		),
		'click_through_rate' => array(
			'title'   => __( 'Click-through Rate', 'opti-behavior' ),
			'content' => __( 'The percentage of visitors who click on something after viewing a page. Higher rates mean your pages encourage action and engagement.', 'opti-behavior' ),
			'simple'  => __( 'How often people click on things after arriving on a page.', 'opti-behavior' ),
		),
		'available_heatmaps' => array(
			'title'   => __( 'Available Heatmaps', 'opti-behavior' ),
			'content' => __( 'Browse all pages that have heatmap data. Click on any page to view its detailed click heatmap and analyze visitor behavior.', 'opti-behavior' ),
			'simple'  => __( 'A list of all pages with click tracking. Click one to see its heatmap!', 'opti-behavior' ),
		),
		'interactions' => array(
			'title'   => __( 'Interactions', 'opti-behavior' ),
			'content' => __( 'The total number of clicks recorded on this page. This includes all clicks on links, buttons, images, and any other elements visitors interacted with.', 'opti-behavior' ),
			'simple'  => __( 'How many times people clicked on things on this page.', 'opti-behavior' ),
		),
		'sessions' => array(
			'title'   => __( 'Sessions', 'opti-behavior' ),
			'content' => __( 'The number of distinct human visitor sessions on this page for the selected period, with spam excluded — the same canonical session count shown by the page\'s detail header, the post-edit stats box, and the frontend stats bar. Sessions are counted once across all URL variants of the same page. This is your real page traffic, not just heatmap-captured visits.', 'opti-behavior' ),
			'simple'  => __( 'Real human visitor sessions for this page (spam excluded). Same number as the detail page, stats box, and stats bar.', 'opti-behavior' ),
		),
		'click_heatmap' => array(
			'title'   => __( 'Click Heatmap', 'opti-behavior' ),
			'content' => __( 'Shows exactly where visitors click on your page. Useful for seeing if people find your buttons and links, or if they\'re clicking on things that aren\'t clickable.', 'opti-behavior' ),
			'simple'  => __( 'Shows every spot where people click on your page.', 'opti-behavior' ),
		),
		'attention_heatmap' => array(
			'title'   => __( 'Attention Heatmap', 'opti-behavior' ),
			'content' => __( 'Shows which parts of your page visitors look at the most based on where they hover their mouse and how long they stay in each area.', 'opti-behavior' ),
			'simple'  => __( 'Shows which parts of your page people look at most.', 'opti-behavior' ),
			'pro'     => true,
		),
		'scroll_heatmap' => array(
			'title'   => __( 'Scroll Heatmap', 'opti-behavior' ),
			'content' => __( 'Shows how far down visitors scroll on your page. The top is always visible, but fewer people reach the bottom. Put important content where most people will see it!', 'opti-behavior' ),
			'simple'  => __( 'Shows how far down people scroll. Darker = more people see that part.', 'opti-behavior' ),
			'pro'     => true,
		),
		'breakaway_heatmap' => array(
			'title'   => __( 'Breakaway Heatmap', 'opti-behavior' ),
			'content' => __( 'Shows where visitors tend to leave your page. Identify the "danger zones" where you\'re losing people and improve those sections.', 'opti-behavior' ),
			'simple'  => __( 'Shows where people stop reading and leave your page.', 'opti-behavior' ),
			'pro'     => true,
		),
		'pc_view' => array(
			'title'   => __( 'Desktop View', 'opti-behavior' ),
			'content' => __( 'Shows the heatmap as it appears on desktop computers (large screens). Click data from computer users is shown here.', 'opti-behavior' ),
			'simple'  => __( 'How the heatmap looks on big computer screens.', 'opti-behavior' ),
		),
		'mobile_view' => array(
			'title'   => __( 'Mobile View', 'opti-behavior' ),
			'content' => __( 'Shows the heatmap as it appears on mobile phones. Since phone users tap instead of click, their behavior may be different.', 'opti-behavior' ),
			'simple'  => __( 'How the heatmap looks on phones.', 'opti-behavior' ),
		),
		'date_range' => array(
			'title'   => __( 'Date Range', 'opti-behavior' ),
			'content' => __( 'Choose which time period to show heatmap data for. You can view today\'s clicks, last week, last month, or a custom range.', 'opti-behavior' ),
			'simple'  => __( 'Pick which days of click data to show.', 'opti-behavior' ),
		),
		'heatmap_sessions' => array(
			'title'   => __( 'Heatmap Sessions', 'opti-behavior' ),
			'content' => __( 'The number of visitor sessions included in this heatmap. More sessions = more accurate data. Pages with few sessions may not show reliable patterns.', 'opti-behavior' ),
			'simple'  => __( 'How many visits this heatmap is based on. More = better!', 'opti-behavior' ),
		),
		// Detail-page header pill "(N sessions)" — the ONE canonical session
		// universe used everywhere: distinct human, spam-excluded pageview
		// sessions for the page group, respecting the Period selector. SAME
		// number as the device chips, the Heatmaps list "Sessions" column, the
		// post-edit stats box, and the frontend stats bar. Unified 2026-08-13
		// onto the canonical pageview-session universe (previously showed a
		// heatmap-only agg_sessions count that disagreed with bar/metabox).
		'detail_sessions_pill' => array(
			'title'   => __( 'Sessions', 'opti-behavior' ),
			'content' => __( 'The number of distinct human visitor sessions on this page for the selected period, with spam excluded. Sessions are counted once across all URL variants of the same page. This is the same number shown on the Heatmaps list, the post-edit stats box, and the frontend stats bar. When you apply advanced filters, the device chips and Views below narrow to the matching sessions.', 'opti-behavior' ),
			'simple'  => __( 'Real human visitor sessions for this page (spam excluded), for the selected period — the same number as everywhere else in the plugin.', 'opti-behavior' ),
		),

		// Heatmap Detail Page - quick-stats strip. "Views" is the canonical
		// session count (same universe as the header pill and device chips),
		// while "Clicks" and "Avg Time" are heatmap-interaction metrics derived
		// from captured heatmap files, NOT session counts. Unified 2026-08-13.
		'strip_views' => array(
			'title'   => __( 'Views', 'opti-behavior' ),
			'content' => __( 'The number of distinct human visitor sessions for this device that MATCH your currently applied filters (period plus any advanced filters such as visitor type, browser, country, or traffic channel), with spam excluded. Same canonical session universe as the header Sessions pill and the device chips — not a raw heatmap-file count. With no advanced filter active it equals the current device chip; the header pill stays the period total.', 'opti-behavior' ),
			'simple'  => __( 'Real visitor sessions for the selected device that match your current filters. Same universe as the Sessions pill and device chips.', 'opti-behavior' ),
		),
		'strip_clicks' => array(
			'title'   => __( 'Clicks (heatmap interactions)', 'opti-behavior' ),
			'content' => __( 'The total number of click interactions captured for this heatmap. This is a heatmap-interaction metric drawn from recorded heatmap data — one session can produce many clicks — so it is intentionally NOT a session count and will not match the Views or Sessions numbers.', 'opti-behavior' ),
			'simple'  => __( 'How many clicks the heatmap captured. This counts interactions, not visitors — so it will not match Views.', 'opti-behavior' ),
		),
		'strip_avg_time' => array(
			'title'   => __( 'Avg Time (heatmap interactions)', 'opti-behavior' ),
			'content' => __( 'The average time associated with the captured heatmap interactions. Like Clicks, this is a heatmap-interaction metric derived from recorded heatmap data, not a session-based measurement.', 'opti-behavior' ),
			'simple'  => __( 'Average time from captured heatmap interactions. It is an interaction metric, not a session count.', 'opti-behavior' ),
		),

		// Heatmap Detail Page - Filters
		'filter_date_range' => array(
			'title'   => __( 'Date Range Filter', 'opti-behavior' ),
			'content' => __( 'Choose which time period to analyze. Filter clicks from today only, last week, last month, or view all recorded data.', 'opti-behavior' ),
			'simple'  => __( 'Pick which days\' clicks to show on the heatmap.', 'opti-behavior' ),
		),
		'filter_country' => array(
			'title'   => __( 'Country Filter', 'opti-behavior' ),
			'content' => __( 'Show clicks only from visitors in specific countries. Useful for seeing how different regions interact with your page.', 'opti-behavior' ),
			'simple'  => __( 'Show only clicks from people in certain countries.', 'opti-behavior' ),
		),
		'filter_browser' => array(
			'title'   => __( 'Browser Filter', 'opti-behavior' ),
			'content' => __( 'Show clicks only from specific browsers like Chrome, Firefox, or Safari. Helps identify browser-specific behavior patterns.', 'opti-behavior' ),
			'simple'  => __( 'Show clicks from people using certain web browsers.', 'opti-behavior' ),
		),
		'filter_visitor_type' => array(
			'title'   => __( 'Visitor Type Filter', 'opti-behavior' ),
			'content' => __( 'Filter between logged-in users and guest visitors. Logged-in users often behave differently because they\'re more familiar with your site.', 'opti-behavior' ),
			'simple'  => __( 'Show clicks from logged-in users only, guests only, or everyone.', 'opti-behavior' ),
		),

		// Heatmap Detail Page - Device Types
		'device_desktop' => array(
			'title'   => __( 'Desktop View', 'opti-behavior' ),
			'content' => __( 'View the heatmap rendered from desktop computer users. The number on this chip is the distinct human, spam-excluded desktop sessions MATCHING your currently applied filters — same canonical universe as the header Sessions count, so the desktop, mobile, and tablet chips add up to the filtered total (which equals the header Sessions pill when no advanced filter is applied).', 'opti-behavior' ),
			'simple'  => __( 'Desktop heatmap. The number is real desktop sessions matching your current filters; the three device chips add up to the filtered total.', 'opti-behavior' ),
		),
		'device_mobile' => array(
			'title'   => __( 'Mobile View', 'opti-behavior' ),
			'content' => __( 'View the heatmap rendered from mobile phone users. The number on this chip is the distinct human, spam-excluded mobile sessions MATCHING your currently applied filters — same canonical universe as the header Sessions count, so the desktop, mobile, and tablet chips add up to the filtered total (which equals the header Sessions pill when no advanced filter is applied).', 'opti-behavior' ),
			'simple'  => __( 'Mobile heatmap. The number is real mobile sessions matching your current filters; the three device chips add up to the filtered total.', 'opti-behavior' ),
		),
		'device_tablet' => array(
			'title'   => __( 'Tablet View', 'opti-behavior' ),
			'content' => __( 'View the heatmap rendered from tablet users. The number on this chip is the distinct human, spam-excluded tablet sessions MATCHING your currently applied filters — same canonical universe as the header Sessions count, so the desktop, mobile, and tablet chips add up to the filtered total (which equals the header Sessions pill when no advanced filter is applied).', 'opti-behavior' ),
			'simple'  => __( 'Tablet heatmap. The number is real tablet sessions matching your current filters; the three device chips add up to the filtered total.', 'opti-behavior' ),
		),

		// Heatmap Detail Page - Heatmap Types
		'type_click' => array(
			'title'   => __( 'Click Heatmap', 'opti-behavior' ),
			'content' => __( 'Shows where visitors click on your page. Hot spots (red/orange) indicate popular areas, cool spots (blue/green) show less clicked areas.', 'opti-behavior' ),
			'simple'  => __( 'A color map showing where people click the most.', 'opti-behavior' ),
		),
		'type_move' => array(
			'title'   => __( 'Move Heatmap', 'opti-behavior' ),
			'content' => __( 'Tracks mouse movement patterns. Mouse movement often follows eye movement, helping you understand what visitors look at.', 'opti-behavior' ),
			'simple'  => __( 'Shows where people move their mouse.', 'opti-behavior' ),
		),
		'type_attention' => array(
			'title'   => __( 'Attention Heatmap', 'opti-behavior' ),
			'content' => __( 'Combines scroll depth and time spent to show which parts of your page get the most attention from visitors.', 'opti-behavior' ),
			'simple'  => __( 'Shows which parts people look at most.', 'opti-behavior' ),
		),
		'type_scroll' => array(
			'title'   => __( 'Scroll Heatmap', 'opti-behavior' ),
			'content' => __( 'Shows how far down the page visitors scroll. Use this to ensure important content is placed where most visitors will see it.', 'opti-behavior' ),
			'simple'  => __( 'Shows how far down people scroll.', 'opti-behavior' ),
		),

		// Heatmap Detail Page - Stats
		'stat_views' => array(
			'title'   => __( 'Page Views', 'opti-behavior' ),
			'content' => __( 'Total number of times this page was viewed during the selected period. Each time someone opens the page counts as one view.', 'opti-behavior' ),
			'simple'  => __( 'How many times people looked at this page.', 'opti-behavior' ),
		),
		'stat_clicks' => array(
			'title'   => __( 'Total Clicks', 'opti-behavior' ),
			'content' => __( 'Total number of clicks recorded on this page. This includes clicks on links, buttons, images, and anywhere else on the page.', 'opti-behavior' ),
			'simple'  => __( 'How many times people clicked on things.', 'opti-behavior' ),
		),
		'stat_avg_time' => array(
			'title'   => __( 'Average Time', 'opti-behavior' ),
			'content' => __( 'How long visitors typically spend on this page. Longer times often indicate engaging content that keeps people reading.', 'opti-behavior' ),
			'simple'  => __( 'How long people usually stay on this page.', 'opti-behavior' ),
		),

		// Heatmap Detail Page - Actions
		'download_heatmap' => array(
			'title'   => __( 'Download Heatmap', 'opti-behavior' ),
			'content' => __( 'Save the current heatmap view as an image file. Great for sharing with your team, including in reports, or keeping records.', 'opti-behavior' ),
			'simple'  => __( 'Save a picture of the heatmap to your computer.', 'opti-behavior' ),
		),
		'refresh_heatmap' => array(
			'title'   => __( 'Refresh Heatmap', 'opti-behavior' ),
			'content' => __( 'Reload the heatmap data to show the latest clicks. Use this to see new activity that happened after you opened the page.', 'opti-behavior' ),
			'simple'  => __( 'Update the heatmap with the newest clicks.', 'opti-behavior' ),
		),
		'ai_insights' => array(
			'title'   => __( 'AI Insights', 'opti-behavior' ),
			'content' => __( 'Smart analysis of your heatmap data. AI identifies the most clicked elements and suggests ways to improve your page based on visitor behavior.', 'opti-behavior' ),
			'simple'  => __( 'Computer analysis that tells you what\'s working on your page.', 'opti-behavior' ),
		),
	);
}

/**
 * Get all sessions page tooltips
 *
 * @return array
 */
function opti_behavior_get_sessions_tooltips() {
	return array(
		'sessions_list' => array(
			'title'   => __( 'Session List', 'opti-behavior' ),
			'content' => __( 'Browse through individual visitor sessions. Each row is one visit to your website showing what pages they viewed, how long they stayed, and their interactions.', 'opti-behavior' ),
			'simple'  => __( 'A list of every visit to your website.', 'opti-behavior' ),
		),
		'session_duration' => array(
			'title'   => __( 'Session Duration', 'opti-behavior' ),
			'content' => __( 'How long this visitor stayed on your website during this visit. Longer sessions often mean they found your content valuable.', 'opti-behavior' ),
			'simple'  => __( 'How long the person spent on your website this visit.', 'opti-behavior' ),
		),
		'pages_visited' => array(
			'title'   => __( 'Pages Visited', 'opti-behavior' ),
			'content' => __( 'The number of different pages this visitor looked at during their session. More pages usually means higher engagement.', 'opti-behavior' ),
			'simple'  => __( 'How many different pages they looked at.', 'opti-behavior' ),
		),
		'entry_page' => array(
			'title'   => __( 'Entry Page', 'opti-behavior' ),
			'content' => __( 'The first page this visitor saw when they arrived at your website. This is often where they clicked a link from Google or social media.', 'opti-behavior' ),
			'simple'  => __( 'The first page they saw when arriving at your site.', 'opti-behavior' ),
		),
		'exit_page' => array(
			'title'   => __( 'Exit Page', 'opti-behavior' ),
			'content' => __( 'The last page this visitor viewed before leaving your website. If many people exit from the same page, it might need improvement.', 'opti-behavior' ),
			'simple'  => __( 'The last page they looked at before leaving.', 'opti-behavior' ),
		),
		'visitor_location' => array(
			'title'   => __( 'Visitor Location', 'opti-behavior' ),
			'content' => __( 'The country where this visitor is located, determined by their internet connection. This is approximate and respects privacy.', 'opti-behavior' ),
			'simple'  => __( 'What country this visitor is from.', 'opti-behavior' ),
		),
		'session_recording' => array(
			'title'   => __( 'Session Recording', 'opti-behavior' ),
			'content' => __( 'Watch a replay of exactly what this visitor saw and did on your website. Like a video of their visit showing mouse movements, clicks, and scrolling.', 'opti-behavior' ),
			'simple'  => __( 'A video replay of this person\'s visit to your website.', 'opti-behavior' ),
			'pro'     => true,
		),
	);
}

/**
 * Get all funnels page tooltips
 *
 * @return array
 */
function opti_behavior_get_funnels_tooltips() {
	return array(
		'what_is_funnel' => array(
			'title'   => __( 'What is a Funnel?', 'opti-behavior' ),
			'content' => __( 'A funnel tracks how visitors move through a series of pages, like: Homepage > Product Page > Cart > Checkout. It shows where people drop off so you can improve those steps.', 'opti-behavior' ),
			'simple'  => __( 'Tracks the path visitors take through your site, like following footprints.', 'opti-behavior' ),
			'example' => __( '100 people start > 60 add to cart > 30 checkout = your funnel shows where you\'re losing customers.', 'opti-behavior' ),
		),
		'conversion_rate' => array(
			'title'   => __( 'Conversion Rate', 'opti-behavior' ),
			'content' => __( 'The percentage of visitors who complete the entire funnel. Higher is better! This is your success rate.', 'opti-behavior' ),
			'simple'  => __( 'What percentage of people complete all the steps.', 'opti-behavior' ),
			'example' => __( '10% conversion rate means 10 out of 100 visitors completed your goal.', 'opti-behavior' ),
		),
		'drop_off' => array(
			'title'   => __( 'Drop-off Point', 'opti-behavior' ),
			'content' => __( 'Where visitors leave the funnel without completing it. High drop-offs at a step mean that step needs improvement - maybe it\'s confusing or takes too long.', 'opti-behavior' ),
			'simple'  => __( 'Where people give up and leave without finishing.', 'opti-behavior' ),
		),
		'funnel_step' => array(
			'title'   => __( 'Funnel Step', 'opti-behavior' ),
			'content' => __( 'Each page or action in your funnel is a step. Steps should follow the natural path you want visitors to take.', 'opti-behavior' ),
			'simple'  => __( 'One page or action in the path you\'re tracking.', 'opti-behavior' ),
		),
		'funnel_name' => array(
			'title'   => __( 'Funnel Name', 'opti-behavior' ),
			'content' => __( 'Give your funnel a descriptive name that identifies what user journey it tracks. This name appears in your funnel list and analytics reports.', 'opti-behavior' ),
			'simple'  => __( 'A label to identify this funnel in your dashboard.', 'opti-behavior' ),
			'example' => __( 'Examples: "Purchase Journey", "Newsletter Signup", "Free Trial Conversion"', 'opti-behavior' ),
		),
		'funnel_steps' => array(
			'title'   => __( 'Funnel Steps', 'opti-behavior' ),
			'content' => __( 'Define the sequence of pages visitors should follow. Each step represents a page in your conversion path. Steps are tracked in order - visitors must complete step 1 before step 2 counts.', 'opti-behavior' ),
			'simple'  => __( 'The pages visitors go through, in order.', 'opti-behavior' ),
			'example' => __( 'Step 1: Homepage → Step 2: Product Page → Step 3: Cart → Step 4: Checkout', 'opti-behavior' ),
		),
		'add_step' => array(
			'title'   => __( 'Add Step', 'opti-behavior' ),
			'content' => __( 'Add another page to your funnel sequence. You can add up to 10 steps. Each step needs a name and URL pattern to match.', 'opti-behavior' ),
			'simple'  => __( 'Click to add another page to track in your funnel.', 'opti-behavior' ),
			'example' => __( 'Add a checkout step after cart, or a thank-you page after purchase.', 'opti-behavior' ),
		),
		'suggested_funnels' => array(
			'title'   => __( 'Suggested Funnels', 'opti-behavior' ),
			'content' => __( 'We look at the plugins and pages your site actually uses (store, blog, contact forms, memberships, courses, bookings) and propose ready-made funnels built from your real URLs. Nothing is created until you click Create.', 'opti-behavior' ),
			'simple'  => __( 'Ready-made funnels for your type of site - one click to create.', 'opti-behavior' ),
			'example' => __( 'On a WooCommerce store: Shop > Product > Cart > Checkout > Order received.', 'opti-behavior' ),
		),
		'rescan_site' => array(
			'title'   => __( 'Re-scan Site', 'opti-behavior' ),
			'content' => __( 'Detects your site again from scratch, ignoring the cached result. It also brings back every suggestion you dismissed, so dismissing a card is never permanent.', 'opti-behavior' ),
			'simple'  => __( 'Look at the site again and restore dismissed suggestions.', 'opti-behavior' ),
			'example' => __( 'Run it right after installing WooCommerce to get the store funnels offered.', 'opti-behavior' ),
		),
	);
}

/**
 * Get all Smart Insights tooltips.
 *
 * Returns user-friendly help copy for the Smart Insights center and settings tab.
 *
 * @return array
 */
function opti_behavior_get_smart_insights_tooltips() {
	return array(
		'center_heading' => array(
			'title'   => __( 'What are Smart Insights?', 'opti-behavior' ),
			'content' => __( 'Smart Insights scans your aggregate behavior data and turns important patterns into prioritized recommendations.', 'opti-behavior' ),
			'simple'  => __( 'It tells you which pages or visitor patterns need attention first.', 'opti-behavior' ),
		),
		'refresh_insights' => array(
			'title'   => __( 'Refresh Insights', 'opti-behavior' ),
			'content' => __( 'Runs Smart Insights again for the selected period. Use this after new traffic has arrived or after changing the date range.', 'opti-behavior' ),
			'simple'  => __( 'Update the recommendations using the latest stored analytics data.', 'opti-behavior' ),
		),
		'period_filter' => array(
			'title'   => __( 'Analysis Period', 'opti-behavior' ),
			'content' => __( 'Choose the date range Smart Insights should analyze. Longer periods provide more stable signals; shorter periods help spot recent changes.', 'opti-behavior' ),
			'simple'  => __( 'The time window used to find behavior problems and opportunities.', 'opti-behavior' ),
		),
		'status_filter' => array(
			'title'   => __( 'Insight Status', 'opti-behavior' ),
			'content' => __( 'Filter insights by workflow status. Use New for fresh findings, In progress for items being fixed, and Resolved or Ignored to keep the list clean.', 'opti-behavior' ),
			'simple'  => __( 'Helps you track what still needs action.', 'opti-behavior' ),
		),
		'search_filter' => array(
			'title'   => __( 'Search Insights', 'opti-behavior' ),
			'content' => __( 'Search by signal name, page, URL, source, form, or campaign to quickly find a specific behavior pattern.', 'opti-behavior' ),
			'simple'  => __( 'Find one insight without scrolling through the full list.', 'opti-behavior' ),
		),
		'severity_filter' => array(
			'title'   => __( 'Severity Filter', 'opti-behavior' ),
			'content' => __( 'Severity reflects how important the signal is. Critical and High items usually deserve review before Medium or Low items.', 'opti-behavior' ),
			'simple'  => __( 'Show only the most important recommendations.', 'opti-behavior' ),
		),
		'sort_filter' => array(
			'title'   => __( 'Sort Insights', 'opti-behavior' ),
			'content' => __( 'Newest detected shows the latest signals first. Priority first sorts by expected impact and confidence.', 'opti-behavior' ),
			'simple'  => __( 'Choose whether to work by recency or by importance.', 'opti-behavior' ),
		),
		'advanced_filters' => array(
			'title'   => __( 'Advanced Filters', 'opti-behavior' ),
			'content' => __( 'Narrow the list by category, entity type, or confidence when you only want to review a specific type of optimization work.', 'opti-behavior' ),
			'simple'  => __( 'Use these when the list is long and you want a focused view.', 'opti-behavior' ),
		),
		'category_filter' => array(
			'title'   => __( 'Insight Category', 'opti-behavior' ),
			'content' => __( 'Categories group related issues such as engagement problems, exit behavior, mobile issues, traffic quality, or CRO opportunities.', 'opti-behavior' ),
			'simple'  => __( 'The type of problem or opportunity detected.', 'opti-behavior' ),
		),
		'entity_type_filter' => array(
			'title'   => __( 'Entity Type', 'opti-behavior' ),
			'content' => __( 'Entity type tells you what the insight is about, such as a page, form, funnel, traffic source, campaign, or device segment.', 'opti-behavior' ),
			'simple'  => __( 'What object the insight is connected to.', 'opti-behavior' ),
		),
		'confidence_filter' => array(
			'title'   => __( 'Confidence Filter', 'opti-behavior' ),
			'content' => __( 'Confidence estimates how reliable the signal is based on available data and comparison strength.', 'opti-behavior' ),
			'simple'  => __( 'Higher confidence means the recommendation is backed by stronger data.', 'opti-behavior' ),
		),
		'weekly_summary' => array(
			'title'   => __( 'Weekly CRO Summary', 'opti-behavior' ),
			'content' => __( 'A weekly briefing that groups recurring issues, biggest movers, and the next best optimization action. In Free mode, this remains a protected Pro preview.', 'opti-behavior' ),
			'simple'  => __( 'A weekly summary of what changed and what to fix next.', 'opti-behavior' ),
		),
		'insight_cards' => array(
			'title'   => __( 'Insight Cards', 'opti-behavior' ),
			'content' => __( 'Each card shows the detected signal, the affected page or segment, supporting metrics, priority, confidence, and the recommended next action.', 'opti-behavior' ),
			'simple'  => __( 'One card equals one behavior opportunity to review.', 'opti-behavior' ),
		),
		'scheduler_settings' => array(
			'title'   => __( 'Automatic Generation', 'opti-behavior' ),
			'content' => __( 'Automatic generation refreshes stored Smart Insights in small background batches so recommendations stay current without overloading low-resource hosting.', 'opti-behavior' ),
			'simple'  => __( 'Keeps recommendations fresh automatically.', 'opti-behavior' ),
		),
		'enable_scheduler' => array(
			'title'   => __( 'Enable Automatic Generation', 'opti-behavior' ),
			'content' => __( 'Turn this on when you want Smart Insights to update on a safe schedule. Manual refresh still works even when this is off.', 'opti-behavior' ),
			'simple'  => __( 'Let the plugin update insights in the background.', 'opti-behavior' ),
		),
		'generation_frequency' => array(
			'title'   => __( 'Generation Frequency', 'opti-behavior' ),
			'content' => __( 'Controls how often the background job starts. Daily is safest for most sites; more frequent runs are for higher traffic sites.', 'opti-behavior' ),
			'simple'  => __( 'How often Smart Insights checks for new recommendations.', 'opti-behavior' ),
		),
		'scheduler_period' => array(
			'title'   => __( 'Scheduled Analysis Period', 'opti-behavior' ),
			'content' => __( 'The rolling date range used by automatic generation. This does not stop you from using a custom period in the Smart Insights center.', 'opti-behavior' ),
			'simple'  => __( 'The default time window for background analysis.', 'opti-behavior' ),
		),
		'processing_profile' => array(
			'title'   => __( 'Processing Profile', 'opti-behavior' ),
			'content' => __( 'Controls how much work each scheduled run does. Gentle is safest for shared hosting; balanced is useful when the site has more traffic.', 'opti-behavior' ),
			'simple'  => __( 'How slowly or quickly background processing runs.', 'opti-behavior' ),
		),
		'scheduler_status' => array(
			'title'   => __( 'Scheduler Status', 'opti-behavior' ),
			'content' => __( 'Shows the next scheduled run, current cycle status, queue progress, and the last completed job so you can confirm automation is healthy.', 'opti-behavior' ),
			'simple'  => __( 'A quick health check for automatic generation.', 'opti-behavior' ),
		),
		'notification_settings' => array(
			'title'   => __( 'Global Notification Launcher', 'opti-behavior' ),
			'content' => __( 'The launcher shows important Smart Insights across WordPress admin pages so administrators can notice behavior signals without opening the full center.', 'opti-behavior' ),
			'simple'  => __( 'A small admin reminder when important signals exist.', 'opti-behavior' ),
		),
		'enable_notifications' => array(
			'title'   => __( 'Enable Notifications', 'opti-behavior' ),
			'content' => __( 'When enabled, eligible administrators can see the Smart Insights launcher and unread badge on WordPress admin pages.', 'opti-behavior' ),
			'simple'  => __( 'Show or hide the global Smart Insights launcher.', 'opti-behavior' ),
		),
		'launcher_behavior' => array(
			'title'   => __( 'Default Launcher Behavior', 'opti-behavior' ),
			'content' => __( 'Choose whether administrators start with the full launcher, a compact side point, or only see the launcher on Opti-Behavior pages.', 'opti-behavior' ),
			'simple'  => __( 'Controls how visible the launcher is by default.', 'opti-behavior' ),
		),
		'priority_threshold' => array(
			'title'   => __( 'Priority Threshold', 'opti-behavior' ),
			'content' => __( 'Controls which insights can create global notifications. High and critical only keeps busy sites focused on the most urgent items.', 'opti-behavior' ),
			'simple'  => __( 'Choose how important an insight must be before it notifies you.', 'opti-behavior' ),
		),
		'notification_period' => array(
			'title'   => __( 'Notification Period', 'opti-behavior' ),
			'content' => __( 'The date range used when counting notification-worthy insights for the global launcher.', 'opti-behavior' ),
			'simple'  => __( 'How far back the notification badge looks.', 'opti-behavior' ),
		),
		'locked_previews' => array(
			'title'   => __( 'Locked Pro Previews', 'opti-behavior' ),
			'content' => __( 'Choose whether the global notification panel should include one grouped preview for protected Pro-only insights.', 'opti-behavior' ),
			'simple'  => __( 'Show or hide one Pro preview in the global panel.', 'opti-behavior' ),
		),
		'personal_state' => array(
			'title'   => __( 'Personal Launcher State', 'opti-behavior' ),
			'content' => __( 'Your own launcher state can differ from the site default. Use this section to restore, compact, or clear your personal unread badge.', 'opti-behavior' ),
			'simple'  => __( 'Controls only your admin account.', 'opti-behavior' ),
		),
		'recovery_controls' => array(
			'title'   => __( 'Recovery Controls', 'opti-behavior' ),
			'content' => __( 'Use these buttons if you hid the launcher, want to move it back to the side point, or want to clear your unread marker.', 'opti-behavior' ),
			'simple'  => __( 'Quick fixes for your own launcher display.', 'opti-behavior' ),
		),
	);
}

/**
 * Get all post metabox tooltips
 *
 * Returns an array of tooltips for post/page editor analytics metabox
 *
 * @return array
 */
function opti_behavior_get_metabox_tooltips() {
	return array(
		// Main Columns
		'entry_sources' => array(
			'title'   => __( 'Entry Sources', 'opti-behavior' ),
			'content' => __( 'Shows which websites or search engines sent visitors to this page. Understanding where your traffic comes from helps you know which marketing efforts work best.', 'opti-behavior' ),
			'simple'  => __( 'How people found this page - through Google, social media, or other websites.', 'opti-behavior' ),
		),
		'visitor_behavior' => array(
			'title'   => __( 'Visitor Behavior', 'opti-behavior' ),
			'content' => __( 'Key statistics about how visitors interact with this specific page. See time spent, clicks, scrolling, and whether visitors leave quickly or explore more.', 'opti-behavior' ),
			'simple'  => __( 'What people do when they visit this page.', 'opti-behavior' ),
		),
		'exit_behavior' => array(
			'title'   => __( 'Exit Behavior', 'opti-behavior' ),
			'content' => __( 'Shows where visitors go after viewing this page. Did they click to another website? Move to another page on your site? Or just close the tab?', 'opti-behavior' ),
			'simple'  => __( 'Where people go after reading this page.', 'opti-behavior' ),
		),

		// Visitor Behavior Stats
		'avg_time_on_page' => array(
			'title'   => __( 'Avg Time on Page', 'opti-behavior' ),
			'content' => __( 'The average amount of time visitors spend reading this page. Longer times usually mean your content is engaging and useful.', 'opti-behavior' ),
			'simple'  => __( 'How long people usually spend reading this page.', 'opti-behavior' ),
			'example' => __( '2m 30s means most readers stay about two and a half minutes.', 'opti-behavior' ),
		),
		'total_interactions' => array(
			'title'   => __( 'Total Interactions', 'opti-behavior' ),
			'content' => __( 'All clicks recorded on this page combined (from both desktop and mobile users). More interactions mean people are actively engaging with your content.', 'opti-behavior' ),
			'simple'  => __( 'How many times people clicked on things on this page.', 'opti-behavior' ),
		),
		'desktop_events' => array(
			'title'   => __( 'Desktop Events', 'opti-behavior' ),
			'content' => __( 'Click events recorded from visitors using desktop computers or laptops. Desktop users often click differently than mobile users.', 'opti-behavior' ),
			'simple'  => __( 'Clicks from people using computers.', 'opti-behavior' ),
		),
		'mobile_events' => array(
			'title'   => __( 'Mobile Events', 'opti-behavior' ),
			'content' => __( 'Tap events recorded from visitors using phones or tablets. Mobile users navigate differently, so it\'s useful to track them separately.', 'opti-behavior' ),
			'simple'  => __( 'Taps from people using phones or tablets.', 'opti-behavior' ),
		),
		'avg_scroll_depth' => array(
			'title'   => __( 'Avg. Scroll Depth', 'opti-behavior' ),
			'content' => __( 'How far down the page visitors scroll on average. 100% means they reached the bottom. If this number is low, try making your content more engaging above the fold.', 'opti-behavior' ),
			'simple'  => __( 'How far people scroll down this page. Higher = they read more!', 'opti-behavior' ),
		),
		'bounce_rate_page' => array(
			'title'   => __( 'Bounce Rate', 'opti-behavior' ),
			'content' => __( 'Percentage of visitors who leave your site after viewing only this page. A high bounce rate on landing pages might mean visitors didn\'t find what they expected.', 'opti-behavior' ),
			'simple'  => __( 'How many people leave after seeing just this page. Lower is usually better!', 'opti-behavior' ),
		),
		'session_type' => array(
			'title'   => __( 'Session Type', 'opti-behavior' ),
			'content' => __( 'Shows the ratio of new sessions versus returning sessions for this page. This is counted by session, so the two numbers match the page session total.', 'opti-behavior' ),
			'simple'  => __( 'New visits vs. return visits for this page.', 'opti-behavior' ),
		),
		'visitor_type' => array(
			'title'   => __( 'Visitor Type', 'opti-behavior' ),
			'content' => __( 'Shows the ratio of new visitors (first time here) versus returning visitors (been here before). A healthy mix of both is ideal.', 'opti-behavior' ),
			'simple'  => __( 'New people vs. people who\'ve visited before.', 'opti-behavior' ),
		),
		'last_updated' => array(
			'title'   => __( 'Last Updated', 'opti-behavior' ),
			'content' => __( 'Shows when the most recent visitor interaction was recorded for this page. Recent activity means your page is still getting traffic.', 'opti-behavior' ),
			'simple'  => __( 'When someone last visited or clicked on this page.', 'opti-behavior' ),
		),

		// Additional Analytics Sections
		'countries_page' => array(
			'title'   => __( 'Countries', 'opti-behavior' ),
			'content' => __( 'Shows which countries your visitors come from for this specific page. Useful if you have content targeting specific regions.', 'opti-behavior' ),
			'simple'  => __( 'Where in the world your readers are from.', 'opti-behavior' ),
		),
		'browsers_page' => array(
			'title'   => __( 'Browsers', 'opti-behavior' ),
			'content' => __( 'Which web browsers visitors use when viewing this page. Make sure your page looks good on the most popular browsers!', 'opti-behavior' ),
			'simple'  => __( 'What programs people use to view this page.', 'opti-behavior' ),
		),
		'devices_page' => array(
			'title'   => __( 'Device Types', 'opti-behavior' ),
			'content' => __( 'Whether visitors view this page on phones, tablets, or computers. If mostly mobile, make sure the page is mobile-friendly!', 'opti-behavior' ),
			'simple'  => __( 'Phones, tablets, or computers - what people use to read this page.', 'opti-behavior' ),
		),

		// Controls & Actions
		'view_heatmap' => array(
			'title'   => __( 'View Heatmap', 'opti-behavior' ),
			'content' => __( 'Open the heatmap viewer for this page. See exactly where visitors click the most with a colorful visualization.', 'opti-behavior' ),
			'simple'  => __( 'See a colorful map of where people click on this page.', 'opti-behavior' ),
		),
		'view_recordings' => array(
			'title'   => __( 'View Recordings', 'opti-behavior' ),
			'content' => __( 'Watch video-like replays of real visitors using this page. See their mouse movements, clicks, and scrolling behavior.', 'opti-behavior' ),
			'simple'  => __( 'Watch videos of how people actually use this page.', 'opti-behavior' ),
			'pro'     => true,
		),
		'time_filter' => array(
			'title'   => __( 'Time Filter', 'opti-behavior' ),
			'content' => __( 'Change the date range for analytics data. View the last 24 hours, week, month, or all time to spot trends and compare periods.', 'opti-behavior' ),
			'simple'  => __( 'Choose which time period to show data for.', 'opti-behavior' ),
		),
		'analytics_chart' => array(
			'title'   => __( 'Analytics Chart', 'opti-behavior' ),
			'content' => __( 'Visual graph showing visitor and interaction trends over time. Spot patterns like traffic spikes or declining engagement at a glance.', 'opti-behavior' ),
			'simple'  => __( 'A graph showing how many people visited over time.', 'opti-behavior' ),
		),
	);
}

/**
 * Get tooltips for Pro features
 *
 * @return array
 */
function opti_behavior_get_pro_tooltips() {
	return array(
		// Session Recordings
		'recordings_overview' => array(
			'title'   => __( 'Session Recordings', 'opti-behavior' ),
			'content' => __( 'Watch video-like replays of real visitor sessions. See exactly what they saw, where they clicked, and how they scrolled. Perfect for understanding user behavior!', 'opti-behavior' ),
			'simple'  => __( 'Video replays showing how people used your website.', 'opti-behavior' ),
			'pro'     => true,
		),
		'recording_filters' => array(
			'title'   => __( 'Recording Filters', 'opti-behavior' ),
			'content' => __( 'Filter recordings by duration, page URL, device type, and more. Find specific sessions to analyze without watching everything.', 'opti-behavior' ),
			'simple'  => __( 'Search for specific recordings you want to watch.', 'opti-behavior' ),
			'pro'     => true,
		),
		'playback_speed' => array(
			'title'   => __( 'Playback Speed', 'opti-behavior' ),
			'content' => __( 'Speed up or slow down recording playback. Watch at 2x to save time, or slow down to catch details.', 'opti-behavior' ),
			'simple'  => __( 'Make recordings play faster or slower.', 'opti-behavior' ),
			'pro'     => true,
		),
		'skip_inactivity' => array(
			'title'   => __( 'Skip Inactivity', 'opti-behavior' ),
			'content' => __( 'Automatically skip parts where the visitor wasn\'t doing anything. Saves time when watching long recordings.', 'opti-behavior' ),
			'simple'  => __( 'Skip boring parts where nothing happened.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Error Tracking
		'error_tracking' => array(
			'title'   => __( 'Error Tracking', 'opti-behavior' ),
			'content' => __( 'Automatically detects JavaScript errors visitors encounter on your website. Fix bugs you didn\'t know existed!', 'opti-behavior' ),
			'simple'  => __( 'Finds problems and bugs on your website automatically.', 'opti-behavior' ),
			'pro'     => true,
		),
		'error_details' => array(
			'title'   => __( 'Error Details', 'opti-behavior' ),
			'content' => __( 'See exactly which error occurred, on which page, and in which browser. Includes the code file and line number for developers.', 'opti-behavior' ),
			'simple'  => __( 'Detailed information about each problem found.', 'opti-behavior' ),
			'pro'     => true,
		),
		'error_frequency' => array(
			'title'   => __( 'Error Frequency', 'opti-behavior' ),
			'content' => __( 'How often this error occurs. Frequent errors should be fixed first as they affect more visitors.', 'opti-behavior' ),
			'simple'  => __( 'How many times this problem happened.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Advanced Features
		'friction_detection' => array(
			'title'   => __( 'Friction Detection', 'opti-behavior' ),
			'content' => __( 'Automatically identifies "rage clicks" (frustrated clicking), dead clicks, and other signs that visitors are struggling with your interface.', 'opti-behavior' ),
			'simple'  => __( 'Finds places where people get frustrated on your site.', 'opti-behavior' ),
			'pro'     => true,
		),
		'performance_metrics' => array(
			'title'   => __( 'Performance Metrics', 'opti-behavior' ),
			'content' => __( 'Track how fast your pages load for real visitors. Slow pages lose visitors - see which pages need speed improvements.', 'opti-behavior' ),
			'simple'  => __( 'Shows how fast your website loads for visitors.', 'opti-behavior' ),
			'pro'     => true,
		),
		'broken_links' => array(
			'title'   => __( 'Broken Link Detection', 'opti-behavior' ),
			'content' => __( 'Finds links on your site that lead to error pages (404s). Fix these to improve user experience and SEO.', 'opti-behavior' ),
			'simple'  => __( 'Finds links that don\'t work anymore.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Errors Page Stats
		'total_errors' => array(
			'title'   => __( 'Total Errors', 'opti-behavior' ),
			'content' => __( 'The total number of JavaScript errors detected on your site during the selected period. These errors can break functionality and frustrate visitors.', 'opti-behavior' ),
			'simple'  => __( 'How many bugs or problems happened on your site.', 'opti-behavior' ),
			'pro'     => true,
		),
		'friction_events' => array(
			'title'   => __( 'Friction Events', 'opti-behavior' ),
			'content' => __( 'User interactions that indicate frustration: rage clicks (rapid clicking), dead clicks (clicking non-interactive elements), and thrashed cursors. High numbers suggest usability issues.', 'opti-behavior' ),
			'simple'  => __( 'Times when visitors got frustrated with your site.', 'opti-behavior' ),
			'pro'     => true,
		),
		'avg_performance' => array(
			'title'   => __( 'Average Performance', 'opti-behavior' ),
			'content' => __( 'A score from 0-100% measuring how fast your pages load for real visitors. Higher is better. Scores below 50% indicate significant performance issues.', 'opti-behavior' ),
			'simple'  => __( 'How fast your website is overall. Higher = faster!', 'opti-behavior' ),
			'pro'     => true,
		),
		'errors_24h' => array(
			'title'   => __( 'Errors (24h)', 'opti-behavior' ),
			'content' => __( 'JavaScript errors detected in the last 24 hours. A sudden increase might indicate a new bug from recent code changes.', 'opti-behavior' ),
			'simple'  => __( 'Bugs found in the last day.', 'opti-behavior' ),
			'pro'     => true,
		),
		'affected_sessions' => array(
			'title'   => __( 'Affected Sessions', 'opti-behavior' ),
			'content' => __( 'The number of visitor sessions that encountered at least one error. High numbers mean many visitors are experiencing problems.', 'opti-behavior' ),
			'simple'  => __( 'How many visitors ran into problems.', 'opti-behavior' ),
			'pro'     => true,
		),
		'critical_errors' => array(
			'title'   => __( 'Critical Errors', 'opti-behavior' ),
			'content' => __( 'Severe errors that likely break important functionality. These should be fixed immediately as they directly impact user experience.', 'opti-behavior' ),
			'simple'  => __( 'Serious bugs that need fixing right away.', 'opti-behavior' ),
			'pro'     => true,
		),
		'rage_clicks' => array(
			'title'   => __( 'Rage Clicks', 'opti-behavior' ),
			'content' => __( 'When visitors click rapidly and repeatedly on the same area, indicating frustration. Often happens when something doesn\'t respond as expected.', 'opti-behavior' ),
			'simple'  => __( 'Angry clicking when something doesn\'t work.', 'opti-behavior' ),
			'pro'     => true,
		),
		'slow_pages' => array(
			'title'   => __( 'Slow Pages', 'opti-behavior' ),
			'content' => __( 'Pages that take longer than 3 seconds to load. Slow pages frustrate visitors and hurt SEO rankings.', 'opti-behavior' ),
			'simple'  => __( 'Pages that take too long to load.', 'opti-behavior' ),
			'pro'     => true,
		),
		'avg_load_time' => array(
			'title'   => __( 'Average Load Time', 'opti-behavior' ),
			'content' => __( 'The average time it takes for your pages to fully load for visitors. Aim for under 3 seconds. Longer times lead to higher bounce rates.', 'opti-behavior' ),
			'simple'  => __( 'How long visitors wait for pages to load.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Errors Page Tabs
		'tab_errors_dashboard' => array(
			'title'   => __( 'Dashboard Tab', 'opti-behavior' ),
			'content' => __( 'Overview of all error tracking: summary KPIs, trends over the selected date range, top errors, and recent activity across JS errors, friction, performance, and broken links.', 'opti-behavior' ),
			'simple'  => __( 'Your at-a-glance summary of everything on this page.', 'opti-behavior' ),
			'pro'     => true,
		),
		'tab_js_errors' => array(
			'title'   => __( 'JS Errors Tab', 'opti-behavior' ),
			'content' => __( 'Lists JavaScript errors visitors hit on your site, grouped by error, with counts and details (file, line, affected pages and browsers). Filter by component, severity, and status to prioritize fixes.', 'opti-behavior' ),
			'simple'  => __( 'Shows code problems visitors ran into.', 'opti-behavior' ),
			'pro'     => true,
		),
		'tab_friction' => array(
			'title'   => __( 'Friction Events Tab', 'opti-behavior' ),
			'content' => __( 'Shows moments visitors struggled: rage clicks, dead clicks, error clicks, and thrashed cursors, with the element, page, and how often each happened.', 'opti-behavior' ),
			'simple'  => __( 'Shows where people got frustrated on your site.', 'opti-behavior' ),
			'pro'     => true,
		),
		'tab_performance' => array(
			'title'   => __( 'Performance Tab', 'opti-behavior' ),
			'content' => __( 'Real-visitor page speed per page: performance score, load time, and Core Web Vitals (LCP, responsiveness, CLS) so you can spot slow pages that lose visitors.', 'opti-behavior' ),
			'simple'  => __( 'Shows how fast your pages load for real visitors.', 'opti-behavior' ),
			'pro'     => true,
		),
		'tab_broken_links' => array(
			'title'   => __( 'Broken Links Tab', 'opti-behavior' ),
			'content' => __( 'Links on your site that lead to missing pages (404s), aggregated by URL with open/fixed/ignored status and re-check tools, so you can fix them for better UX and SEO.', 'opti-behavior' ),
			'simple'  => __( 'Shows links that don\'t work anymore.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Errors Page Table Columns - JS Errors tab
		'col_err_priority' => array(
			'title'   => __( 'Priority', 'opti-behavior' ),
			'content' => __( 'A computed priority score for the error group, based on severity, how often it occurs, how many sessions it affects, and how recent it is. Higher means fix it first.', 'opti-behavior' ),
			'simple'  => __( 'Which bugs to fix first.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_message' => array(
			'title'   => __( 'Error Message', 'opti-behavior' ),
			'content' => __( 'The error\'s source script or component with a representative error message, the number of error variants, and the file/line location when known.', 'opti-behavior' ),
			'simple'  => __( 'What went wrong and where in the code.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_type' => array(
			'title'   => __( 'Type', 'opti-behavior' ),
			'content' => __( 'The kind of JavaScript error, such as a runtime error, promise rejection, network/fetch failure, or console error.', 'opti-behavior' ),
			'simple'  => __( 'What kind of problem it is.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_severity' => array(
			'title'   => __( 'Severity', 'opti-behavior' ),
			'content' => __( 'How serious the error is: Critical, Error, Warning, or Info. Critical errors likely break functionality and should be fixed first.', 'opti-behavior' ),
			'simple'  => __( 'How bad the problem is.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_count' => array(
			'title'   => __( 'Count', 'opti-behavior' ),
			'content' => __( 'How many times this error occurred in the selected date range.', 'opti-behavior' ),
			'simple'  => __( 'How often it happened.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_sessions' => array(
			'title'   => __( 'Sessions', 'opti-behavior' ),
			'content' => __( 'The number of visitor sessions that hit this error. More sessions means more visitors are affected.', 'opti-behavior' ),
			'simple'  => __( 'How many visits ran into it.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_pages' => array(
			'title'   => __( 'Pages', 'opti-behavior' ),
			'content' => __( 'The pages where this error occurred. Use the Breakdown action for the full per-page detail.', 'opti-behavior' ),
			'simple'  => __( 'Where on your site it happened.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_last_seen' => array(
			'title'   => __( 'Last Seen', 'opti-behavior' ),
			'content' => __( 'When this error last occurred. Recent errors are more likely to still be present.', 'opti-behavior' ),
			'simple'  => __( 'The last time it happened.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_status' => array(
			'title'   => __( 'Status', 'opti-behavior' ),
			'content' => __( 'Your workflow status for the error: Open, Investigating, Resolved, or Ignored.', 'opti-behavior' ),
			'simple'  => __( 'Where you are with fixing it.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_err_actions' => array(
			'title'   => __( 'Actions', 'opti-behavior' ),
			'content' => __( 'Mark the error resolved or ignored, or expand its per-page breakdown with recent occurrences.', 'opti-behavior' ),
			'simple'  => __( 'Things you can do with this error.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Errors Page Table Columns - Friction Events tab
		'col_fr_type' => array(
			'title'   => __( 'Type', 'opti-behavior' ),
			'content' => __( 'The kind of friction: rage click, dead click, error click, or thrashed cursor. An auto-triage verdict (bug / review / visitor) is shown when available.', 'opti-behavior' ),
			'simple'  => __( 'What kind of frustration signal it is.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_fr_target' => array(
			'title'   => __( 'Target / Area', 'opti-behavior' ),
			'content' => __( 'The element visitors struggled with. For error clicks, the triggering error is shown first with the clicked element(s) below it.', 'opti-behavior' ),
			'simple'  => __( 'What visitors were clicking on.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_fr_pages' => array(
			'title'   => __( 'Pages', 'opti-behavior' ),
			'content' => __( 'The pages where this friction happened.', 'opti-behavior' ),
			'simple'  => __( 'Where on your site it happened.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_fr_signal' => array(
			'title'   => __( 'Signal', 'opti-behavior' ),
			'content' => __( 'The strength of the friction signal: total clicks (or movement events for thrashed cursors) plus the number of sessions affected.', 'opti-behavior' ),
			'simple'  => __( 'How strong the frustration was.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_fr_time' => array(
			'title'   => __( 'Time', 'opti-behavior' ),
			'content' => __( 'When this friction last happened. Hover the value to see when it was first seen.', 'opti-behavior' ),
			'simple'  => __( 'The last time it happened.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_fr_context' => array(
			'title'   => __( 'Context', 'opti-behavior' ),
			'content' => __( 'The devices, browsers, and countries of the affected visitors — helps spot device- or browser-specific issues.', 'opti-behavior' ),
			'simple'  => __( 'Who ran into it and on what device.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_fr_actions' => array(
			'title'   => __( 'Actions', 'opti-behavior' ),
			'content' => __( 'Open the event details, or expand the per-page breakdown for grouped events.', 'opti-behavior' ),
			'simple'  => __( 'Things you can do with this event.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Errors Page Table Columns - Performance tab
		'col_perf_page' => array(
			'title'   => __( 'Page', 'opti-behavior' ),
			'content' => __( 'The page these speed metrics were measured on.', 'opti-behavior' ),
			'simple'  => __( 'Which page the numbers belong to.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_perf_score' => array(
			'title'   => __( 'Score', 'opti-behavior' ),
			'content' => __( 'An overall performance score from 0 to 100 based on real visitor timings. Higher is better; below 50 signals significant problems.', 'opti-behavior' ),
			'simple'  => __( 'Overall speed grade. Higher = faster!', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_perf_load' => array(
			'title'   => __( 'Load Time', 'opti-behavior' ),
			'content' => __( 'The average full page load time for real visitors. Aim for under 3 seconds — longer times lose visitors.', 'opti-behavior' ),
			'simple'  => __( 'How long the page takes to load.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_perf_lcp' => array(
			'title'   => __( 'LCP', 'opti-behavior' ),
			'content' => __( 'Largest Contentful Paint — how long until the page\'s main content becomes visible. Good: under 2.5s.', 'opti-behavior' ),
			'simple'  => __( 'How fast the main content shows up.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_perf_resp' => array(
			'title'   => __( 'Responsiveness', 'opti-behavior' ),
			'content' => __( 'Interaction to Next Paint (INP; legacy FID as fallback) — how quickly the page reacts to clicks and typing. Good: 200ms or less.', 'opti-behavior' ),
			'simple'  => __( 'How fast the page reacts when you click.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_perf_cls' => array(
			'title'   => __( 'CLS', 'opti-behavior' ),
			'content' => __( 'Cumulative Layout Shift — how much the page content jumps around while loading. Good: under 0.1.', 'opti-behavior' ),
			'simple'  => __( 'How much the page jumps while loading.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_perf_sessions' => array(
			'title'   => __( 'Sessions', 'opti-behavior' ),
			'content' => __( 'The number of visitor sessions the metrics were measured from. More sessions means more reliable numbers.', 'opti-behavior' ),
			'simple'  => __( 'How many visits the numbers come from.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Errors Page Table Columns - Broken Links tab
		'col_bl_url' => array(
			'title'   => __( 'Reported URL', 'opti-behavior' ),
			'content' => __( 'The broken destination URL visitors hit, with its resource type and status badges and the link text when available.', 'opti-behavior' ),
			'simple'  => __( 'The address that doesn\'t work.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_bl_source' => array(
			'title'   => __( 'Source Page', 'opti-behavior' ),
			'content' => __( 'The page on your site that contains the broken link — where to go to fix it.', 'opti-behavior' ),
			'simple'  => __( 'The page the bad link lives on.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_bl_http' => array(
			'title'   => __( 'HTTP', 'opti-behavior' ),
			'content' => __( 'The HTTP status returned when the link was verified (e.g. 404 = not found). Hover the badge for verification details.', 'opti-behavior' ),
			'simple'  => __( 'The error code the link returns.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_bl_sessions' => array(
			'title'   => __( 'Sessions', 'opti-behavior' ),
			'content' => __( 'The number of visitor sessions that hit this broken link (the primary metric), with total occurrences shown below it.', 'opti-behavior' ),
			'simple'  => __( 'How many visits hit the bad link.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_bl_last_detected' => array(
			'title'   => __( 'Last Detected', 'opti-behavior' ),
			'content' => __( 'When this broken link was last detected on your site.', 'opti-behavior' ),
			'simple'  => __( 'The last time it was seen broken.', 'opti-behavior' ),
			'pro'     => true,
		),
		'col_bl_actions' => array(
			'title'   => __( 'Actions', 'opti-behavior' ),
			'content' => __( 'Recheck the link now, mark it as fixed, or reopen a fixed/ignored link.', 'opti-behavior' ),
			'simple'  => __( 'Things you can do with this link.', 'opti-behavior' ),
			'pro'     => true,
		),

		// Form Analytics
		'form_analytics_overview' => array(
			'title'   => __( 'Form Analytics', 'opti-behavior' ),
			'content' => __( 'Track how visitors interact with your forms: views, submissions, abandonments, and field-level drop-offs. Identify which forms convert best and where users give up.', 'opti-behavior' ),
			'simple'  => __( 'See how people use your forms and where they stop filling them out.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_views' => array(
			'title'   => __( 'Form Views', 'opti-behavior' ),
			'content' => __( 'Form-interaction basis: counts tracked form view rows/interactions. A visitor or session can generate more than one form view when multiple forms or repeated views are tracked.', 'opti-behavior' ),
			'simple'  => __( 'Tracked form view rows/interactions.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_submissions' => array(
			'title'   => __( 'Submissions', 'opti-behavior' ),
			'content' => __( 'Submission-row basis: counts completed form submission rows. This is not a unique visitor count; one visitor or session can submit more than once where the form allows it.', 'opti-behavior' ),
			'simple'  => __( 'How many completed form submission rows were tracked.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_conversion_rate' => array(
			'title'   => __( 'Conversion Rate', 'opti-behavior' ),
			'content' => __( 'Form-interaction basis: submissions divided by tracked form views/interactions for the selected filters. It is not calculated from unique Visitors or Sessions KPIs.', 'opti-behavior' ),
			'simple'  => __( 'Submission rows divided by tracked form views/interactions.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_avg_completion_time' => array(
			'title'   => __( 'Average Completion Time', 'opti-behavior' ),
			'content' => __( 'The average time visitors spend filling out a form from first interaction to submission. Very long times may suggest confusing fields. Very short times might indicate auto-fill usage.', 'opti-behavior' ),
			'simple'  => __( 'How long it takes on average for someone to fill out your form.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_abandonments' => array(
			'title'   => __( 'Abandonments', 'opti-behavior' ),
			'content' => __( 'The number of times visitors started interacting with a form but left without submitting. High abandonment indicates friction — check the Field Analysis tab to see where users drop off.', 'opti-behavior' ),
			'simple'  => __( 'Visitors who started filling out the form but gave up.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_activity_chart' => array(
			'title'   => __( 'Form Activity Over Time', 'opti-behavior' ),
			'content' => __( 'Submission/interaction basis: visualizes daily form submission and abandonment rows over the selected period, not unique visitors.', 'opti-behavior' ),
			'simple'  => __( 'A chart showing form activity day by day.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_top_conversion' => array(
			'title'   => __( 'Top Forms by Conversion', 'opti-behavior' ),
			'content' => __( 'Form-interaction basis: ranks forms by submissions divided by tracked form views/interactions for each form.', 'opti-behavior' ),
			'simple'  => __( 'Which of your forms are the most successful.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_recent_activity' => array(
			'title'   => __( 'Recent Form Activity', 'opti-behavior' ),
			'content' => __( 'Shows tracked forms with form-view, submission, abandonment, and related session metrics. Form counts are row/interactions-based; Sessions badges count matching session rows.', 'opti-behavior' ),
			'simple'  => __( 'A list of all your forms and how they are performing.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_field_analysis' => array(
			'title'   => __( 'Field Analysis', 'opti-behavior' ),
			'content' => __( 'Analyzes each field in your form individually. See which fields cause the most drop-offs, take the longest to fill, or have the highest error rates. Use this to simplify your forms.', 'opti-behavior' ),
			'simple'  => __( 'Shows which form fields cause problems for visitors.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_field_funnel' => array(
			'title'   => __( 'Field Drop-off Funnel', 'opti-behavior' ),
			'content' => __( 'Visualizes how visitors progress through your form fields. Each step shows how many visitors reached that field and the drop-off rate. Fields with high drop-off need attention.', 'opti-behavior' ),
			'simple'  => __( 'Shows where visitors stop filling out your form.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_field_metrics' => array(
			'title'   => __( 'Field-Level Metrics', 'opti-behavior' ),
			'content' => __( 'Detailed metrics for each form field: time spent, interaction count, error rate, refill rate, and blank rate. Identify problematic fields that need redesigning or clearer labels.', 'opti-behavior' ),
			'simple'  => __( 'Detailed stats for every field in your form.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_sessions' => array(
			'title'   => __( 'Sessions', 'opti-behavior' ),
			'content' => __( 'View individual visitor sessions that interacted with this form. See their submission status, device, country, and watch their session recording to understand their experience.', 'opti-behavior' ),
			'simple'  => __( 'Individual visits where someone used this form.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_filter_device' => array(
			'title'   => __( 'Device Filter', 'opti-behavior' ),
			'content' => __( 'Filter form analytics by device type (Desktop, Mobile, Tablet). Compare how forms perform across different devices to identify mobile-specific issues.', 'opti-behavior' ),
			'simple'  => __( 'Show data only for specific devices.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_filter_browser' => array(
			'title'   => __( 'Browser Filter', 'opti-behavior' ),
			'content' => __( 'Filter by browser (Chrome, Firefox, Safari, etc.). Useful for identifying browser-specific form rendering or validation issues.', 'opti-behavior' ),
			'simple'  => __( 'Show data only for specific browsers.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_filter_country' => array(
			'title'   => __( 'Country Filter', 'opti-behavior' ),
			'content' => __( 'Filter form analytics by visitor country. Helps you understand geographic patterns and whether localization affects form completion.', 'opti-behavior' ),
			'simple'  => __( 'Show data only for visitors from specific countries.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_filter_visitor' => array(
			'title'   => __( 'Visitor Type Filter', 'opti-behavior' ),
			'content' => __( 'Filters form analytics rows by the visitor type attached to each matching form submission/session. Filter option counts are submission-row based, not unique visitors.', 'opti-behavior' ),
			'simple'  => __( 'Show matching form activity for new or returning visitor-type rows.', 'opti-behavior' ),
			'pro'     => true,
		),
		'form_filter_referrer' => array(
			'title'   => __( 'Referrer Filter', 'opti-behavior' ),
			'content' => __( 'Filter by traffic source (Google, social media, direct, etc.). See which traffic sources bring the most form conversions.', 'opti-behavior' ),
			'simple'  => __( 'Show data based on where visitors came from.', 'opti-behavior' ),
			'pro'     => true,
		),
	);
}

/**
 * Get all A/B Testing screen tooltips (free plugin).
 *
 * Covers: Test List, Builder Steps 1-6, Results page.
 *
 * @return array
 */
function opti_behavior_get_ab_testing_tooltips() {
	return array(

		// ── Screen: Test List ──────────────────────────────────────────────────

		'ab_testing_heading' => array(
			'title'   => __( 'What is A/B Testing?', 'opti-behavior' ),
			'content' => __( 'Split testing lets you show different versions of a page to different visitors, then measure which version converts better.', 'opti-behavior' ),
			'simple'  => __( 'Compare two or more versions of a page to find which one works best.', 'opti-behavior' ),
		),
		'new_test_btn' => array(
			'title'   => __( 'Create a New Test', 'opti-behavior' ),
			'content' => __( 'Start the test builder wizard to set up a new split test. Takes about 2 minutes.', 'opti-behavior' ),
			'simple'  => '',
		),
		'stat_running' => array(
			'title'   => __( 'Active Tests', 'opti-behavior' ),
			'content' => __( 'Tests currently serving different variants to your visitors and collecting conversion data.', 'opti-behavior' ),
			'simple'  => '',
		),
		'stat_drafts' => array(
			'title'   => __( 'Draft Tests', 'opti-behavior' ),
			'content' => __( 'Tests you have configured but not yet launched. Drafts do not affect your live site.', 'opti-behavior' ),
			'simple'  => '',
		),
		'stat_completed' => array(
			'title'   => __( 'Completed Tests', 'opti-behavior' ),
			'content' => __( 'Tests that have been stopped or have declared a winner. Results are preserved for review.', 'opti-behavior' ),
			'simple'  => '',
		),
		'stat_impressions' => array(
			'title'   => __( 'Total Impressions', 'opti-behavior' ),
			'content' => __( 'The total number of times any variant was shown to a visitor across all your tests.', 'opti-behavior' ),
			'simple'  => '',
		),
		'filter_status' => array(
			'title'   => __( 'Filter by Status', 'opti-behavior' ),
			'content' => __( 'Show only tests in a specific status: Running, Draft, Paused, Completed, or Archived.', 'opti-behavior' ),
			'simple'  => '',
		),
		'filter_type' => array(
			'title'   => __( 'Filter by Test Type', 'opti-behavior' ),
			'content' => __( 'Show only Page Split, Element, or WooCommerce tests.', 'opti-behavior' ),
			'simple'  => '',
		),
		'search_tests' => array(
			'title'   => __( 'Search Tests', 'opti-behavior' ),
			'content' => __( 'Search by test name to quickly find a specific experiment.', 'opti-behavior' ),
			'simple'  => '',
		),
		'free_limits_notice' => array(
			'title'   => __( 'Free Plan Limits', 'opti-behavior' ),
			'content' => __( 'The free plan allows up to 3 concurrent tests, 3 variants per test, and 1 goal per test. Upgrade to Pro to remove all limits.', 'opti-behavior' ),
			'simple'  => '',
		),

		// ── Screen: Builder — Step 1 (Test Type) ──────────────────────────────

		'step_type_heading' => array(
			'title'   => __( 'Step 1 – Test Type', 'opti-behavior' ),
			'content' => __( 'Choose the kind of test you want to run. You can change this before launching but not after.', 'opti-behavior' ),
			'simple'  => '',
		),
		'type_page_split' => array(
			'title'   => __( 'Page Split Test', 'opti-behavior' ),
			'content' => __( 'Redirects visitors to entirely different page URLs. Each URL is a separate variant. Great for testing completely new page designs.', 'opti-behavior' ),
			'simple'  => '',
		),
		'type_element' => array(
			'title'   => __( 'Element Test', 'opti-behavior' ),
			'content' => __( 'Modifies specific elements (text, buttons, images) on the same URL using the visual editor. Visitors never leave the original page.', 'opti-behavior' ),
			'simple'  => '',
		),
		'type_woocommerce' => array(
			'title'   => __( 'WooCommerce Product Test', 'opti-behavior' ),
			'content' => __( 'Tests different product titles, prices, descriptions, or images on WooCommerce product pages. Measures impact on add-to-cart and revenue.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro.', 'opti-behavior' ),
		),

		// ── Screen: Builder — Step 2 (Target) ─────────────────────────────────

		'step_target_heading' => array(
			'title'   => __( 'Step 2 – Target', 'opti-behavior' ),
			'content' => __( 'Set the page where the test runs and give your test a recognisable name.', 'opti-behavior' ),
			'simple'  => '',
		),
		'test_name' => array(
			'title'   => __( 'Test Name', 'opti-behavior' ),
			'content' => __( 'A descriptive label for your own reference — e.g., "Homepage Hero CTA Test". Not shown to visitors.', 'opti-behavior' ),
			'simple'  => '',
		),
		'target_url' => array(
			'title'   => __( 'Target URL', 'opti-behavior' ),
			'content' => __( 'The URL of the page where visitors will be split into variants. Use the full URL including https://.', 'opti-behavior' ),
			'simple'  => '',
		),
		'target_post' => array(
			'title'   => __( 'Target Page or Post', 'opti-behavior' ),
			'content' => __( 'Pick from your published pages, posts, or products. The test will run on the selected content\'s URL.', 'opti-behavior' ),
			'simple'  => '',
		),
		'woo_product' => array(
			'title'   => __( 'WooCommerce Product', 'opti-behavior' ),
			'content' => __( 'Search and select the product whose page you want to A/B test. Changes will affect the product display in each variant.', 'opti-behavior' ),
			'simple'  => '',
		),
		'woo_product_search' => array(
			'title'   => __( 'Search Products', 'opti-behavior' ),
			'content' => __( 'Search by product name, SKU, or category to quickly find the product to test.', 'opti-behavior' ),
			'simple'  => '',
		),
		'woo_category_filter' => array(
			'title'   => __( 'Filter by Category', 'opti-behavior' ),
			'content' => __( 'Narrow the product list to a specific WooCommerce product category.', 'opti-behavior' ),
			'simple'  => '',
		),

		// ── Screen: Builder — Step 3 (Variants) ───────────────────────────────

		'step_variants_heading' => array(
			'title'   => __( 'Step 3 – Variants', 'opti-behavior' ),
			'content' => __( 'Define the different versions you want to compare. You always need at least one variant in addition to the original (Control).', 'opti-behavior' ),
			'simple'  => '',
		),
		'control_variant' => array(
			'title'   => __( 'Control Variant', 'opti-behavior' ),
			'content' => __( 'The original, unchanged version of your page. All other variants are measured against this baseline.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_name' => array(
			'title'   => __( 'Variant Name', 'opti-behavior' ),
			'content' => __( 'A short label for this version — e.g., "Variant B – Red Button". Used in charts and reports.', 'opti-behavior' ),
			'simple'  => '',
		),
		'traffic_split' => array(
			'title'   => __( 'Traffic Split', 'opti-behavior' ),
			'content' => __( 'What percentage of test visitors sees this variant. All variant percentages including Control must add up to 100%.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_url' => array(
			'title'   => __( 'Variant URL', 'opti-behavior' ),
			'content' => __( 'The alternative page URL visitors will be redirected to for this variant.', 'opti-behavior' ),
			'simple'  => '',
		),
		'visual_editor_btn' => array(
			'title'   => __( 'Open Visual Editor', 'opti-behavior' ),
			'content' => __( 'Launch the point-and-click editor to modify HTML elements on the page for this variant. Changes are stored as a diff — no file edits.', 'opti-behavior' ),
			'simple'  => '',
		),
		'add_variant_btn' => array(
			'title'   => __( 'Add Another Variant', 'opti-behavior' ),
			'content' => __( 'Add a new variant to test. The free plan supports up to 3 variants total (including Control).', 'opti-behavior' ),
			'simple'  => '',
		),

		// ── Screen: Builder — Step 4 (Goals) ──────────────────────────────────

		'step_goals_heading' => array(
			'title'   => __( 'Step 4 – Goals', 'opti-behavior' ),
			'content' => __( 'A goal is the action you want visitors to take. The test will track how often each variant achieves this goal.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_page_visit' => array(
			'title'   => __( 'Page Visit Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor lands on a specific URL — e.g., a thank-you page after purchase.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_click' => array(
			'title'   => __( 'Click Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor clicks any element matching a CSS selector — e.g., a buy button or link.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_form_submit' => array(
			'title'   => __( 'Form Submit Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor submits a form on the page.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_scroll_depth' => array(
			'title'   => __( 'Scroll Depth Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor scrolls past a certain percentage of the page.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro.', 'opti-behavior' ),
		),
		'goal_time_on_page' => array(
			'title'   => __( 'Time on Page Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor spends at least a set amount of time on the page.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro.', 'opti-behavior' ),
		),
		'goal_revenue' => array(
			'title'   => __( 'Revenue Goal', 'opti-behavior' ),
			'content' => __( 'Track the total revenue generated by visitors in each variant. Requires WooCommerce and Pro.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro + WooCommerce.', 'opti-behavior' ),
		),
		'goal_add_to_cart' => array(
			'title'   => __( 'Add to Cart Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor adds a product to cart. Requires WooCommerce and Pro.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro + WooCommerce.', 'opti-behavior' ),
		),
		'goal_purchase' => array(
			'title'   => __( 'Purchase Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor completes a WooCommerce purchase. Requires WooCommerce and Pro.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro + WooCommerce.', 'opti-behavior' ),
		),
		'goal_bounce_rate' => array(
			'title'   => __( 'Bounce Rate Goal', 'opti-behavior' ),
			'content' => __( 'Count a conversion when a visitor stays on the page longer than a threshold (or leaves before it). Requires Pro.', 'opti-behavior' ),
			'simple'  => __( 'Requires Pro.', 'opti-behavior' ),
		),
		'goal_destination_url' => array(
			'title'   => __( 'Destination URL', 'opti-behavior' ),
			'content' => __( 'Visitors who land on this exact URL will trigger a conversion. Supports partial matching with trailing wildcards.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_css_selector' => array(
			'title'   => __( 'CSS Selector', 'opti-behavior' ),
			'content' => __( 'An element identifier like .buy-button or #cta-link. Clicks on any matching element trigger a conversion.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_form_selector' => array(
			'title'   => __( 'Form Selector', 'opti-behavior' ),
			'content' => __( 'Choose "Any form" to track all form submissions, or enter a CSS selector to target a specific form.', 'opti-behavior' ),
			'simple'  => '',
		),
		'add_goal_btn' => array(
			'title'   => __( 'Add Another Goal', 'opti-behavior' ),
			'content' => __( 'Add a second (or more) goal to track multiple conversions simultaneously. Free plan: 1 goal per test.', 'opti-behavior' ),
			'simple'  => '',
		),

		// ── Screen: Builder — Step 5 (Settings) ───────────────────────────────

		'step_settings_heading' => array(
			'title'   => __( 'Step 5 – Settings', 'opti-behavior' ),
			'content' => __( 'Configure statistical thresholds and traffic settings. The defaults are suitable for most tests.', 'opti-behavior' ),
			'simple'  => '',
		),
		'confidence_level' => array(
			'title'   => __( 'Confidence Level', 'opti-behavior' ),
			'content' => __( 'How certain the statistical model must be before declaring a winner. 95% (recommended) means a 1-in-20 chance of a false positive. Higher = fewer false positives, but needs more data.', 'opti-behavior' ),
			'simple'  => '',
		),
		'traffic_percentage' => array(
			'title'   => __( 'Traffic Percentage', 'opti-behavior' ),
			'content' => __( 'What percentage of total page visitors will be enrolled in the test. Set below 100% to run the test on a subset of your audience — useful to reduce risk.', 'opti-behavior' ),
			'simple'  => '',
		),
		'min_sample_size' => array(
			'title'   => __( 'Minimum Sample Size', 'opti-behavior' ),
			'content' => __( 'The test will not declare significance until each variant has received at least this many impressions. Prevents premature winners.', 'opti-behavior' ),
			'simple'  => '',
		),
		'min_duration' => array(
			'title'   => __( 'Minimum Duration', 'opti-behavior' ),
			'content' => __( 'The test will run for at least this many days, even if statistical significance is reached sooner. Guards against day-of-week effects.', 'opti-behavior' ),
			'simple'  => '',
		),
		'save_draft_btn' => array(
			'title'   => __( 'Save as Draft', 'opti-behavior' ),
			'content' => __( 'Save your current configuration without launching. You can return to edit or launch later.', 'opti-behavior' ),
			'simple'  => '',
		),
		'launch_test_btn' => array(
			'title'   => __( 'Launch the Test', 'opti-behavior' ),
			'content' => __( 'Activate the test. Visitors will immediately start seeing different variants. Make sure all settings are correct first.', 'opti-behavior' ),
			'simple'  => '',
		),

		// ── Screen: Builder — Step 6 (Review) ─────────────────────────────────

		'step_review_heading' => array(
			'title'   => __( 'Step 6 – Review & Launch', 'opti-behavior' ),
			'content' => __( 'A summary of every setting you configured. Review carefully — you cannot change the test type or target URL after launching.', 'opti-behavior' ),
			'simple'  => '',
		),
		'review_test_type' => array(
			'title'   => __( 'Test Type', 'opti-behavior' ),
			'content' => __( 'The kind of split test: Page Split redirects visitors to different URLs, Element modifies page content in place, WooCommerce tests product page elements.', 'opti-behavior' ),
			'simple'  => '',
		),
		'review_target_url' => array(
			'title'   => __( 'Target URL', 'opti-behavior' ),
			'content' => __( 'The live page where visitors will be enrolled in the test.', 'opti-behavior' ),
			'simple'  => '',
		),
		'review_variants' => array(
			'title'   => __( 'Variants', 'opti-behavior' ),
			'content' => __( 'Each version of the page including the original Control and all challenger variants.', 'opti-behavior' ),
			'simple'  => '',
		),
		'review_goals' => array(
			'title'   => __( 'Goals', 'opti-behavior' ),
			'content' => __( 'The conversion actions the test will measure to determine a winner.', 'opti-behavior' ),
			'simple'  => '',
		),
		'review_settings' => array(
			'title'   => __( 'Test Settings', 'opti-behavior' ),
			'content' => __( 'The confidence level, traffic percentage, and duration thresholds that govern when a winner can be declared.', 'opti-behavior' ),
			'simple'  => '',
		),

		// ── Screen: Results Page ───────────────────────────────────────────────

		'results_heading' => array(
			'title'   => __( 'Test Results', 'opti-behavior' ),
			'content' => __( 'This page shows live performance data for your A/B test. Data updates as visitors interact with your variants.', 'opti-behavior' ),
			'simple'  => '',
		),
		'status_running' => array(
			'title'   => __( 'Running', 'opti-behavior' ),
			'content' => __( 'This test is live and actively splitting traffic between variants.', 'opti-behavior' ),
			'simple'  => '',
		),
		'status_paused' => array(
			'title'   => __( 'Paused', 'opti-behavior' ),
			'content' => __( 'This test is temporarily suspended. Traffic is no longer split, but no data is lost.', 'opti-behavior' ),
			'simple'  => '',
		),
		'status_completed' => array(
			'title'   => __( 'Completed', 'opti-behavior' ),
			'content' => __( 'This test has finished. A winner may have been declared.', 'opti-behavior' ),
			'simple'  => '',
		),
		'pause_btn' => array(
			'title'   => __( 'Pause the Test', 'opti-behavior' ),
			'content' => __( 'Temporarily stop splitting traffic. All visitors see the Control until the test is resumed. Data is preserved.', 'opti-behavior' ),
			'simple'  => '',
		),
		'resume_btn' => array(
			'title'   => __( 'Resume the Test', 'opti-behavior' ),
			'content' => __( 'Restart traffic splitting from where it was paused. Data collection continues.', 'opti-behavior' ),
			'simple'  => '',
		),
		'stop_btn' => array(
			'title'   => __( 'Stop the Test', 'opti-behavior' ),
			'content' => __( 'Permanently end the test. This cannot be undone. All data collected so far is preserved.', 'opti-behavior' ),
			'simple'  => '',
		),
		'hero_total_visitors' => array(
			'title'   => __( 'Total Visitors', 'opti-behavior' ),
			'content' => __( 'The total number of unique visitor impressions across all variants combined since the test started.', 'opti-behavior' ),
			'simple'  => '',
		),
		'hero_duration' => array(
			'title'   => __( 'Test Duration', 'opti-behavior' ),
			'content' => __( 'How long the test has been running in days. Longer tests produce more reliable results.', 'opti-behavior' ),
			'simple'  => '',
		),
		'hero_best_rate' => array(
			'title'   => __( 'Best Conversion Rate', 'opti-behavior' ),
			'content' => __( 'The highest conversion rate achieved by any single variant. Compare variants to find your winner.', 'opti-behavior' ),
			'simple'  => '',
		),
		'hero_confidence' => array(
			'title'   => __( 'Statistical Confidence', 'opti-behavior' ),
			'content' => __( 'How certain the statistical model is that the observed difference between variants is real, not due to random chance. Aim for 95% or higher.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_selector' => array(
			'title'   => __( 'Select a Goal', 'opti-behavior' ),
			'content' => __( 'Choose which goal\'s conversion data to display. Each goal has its own set of variant charts.', 'opti-behavior' ),
			'simple'  => '',
		),
		'goal_config_card' => array(
			'title'   => __( 'Goal Configuration', 'opti-behavior' ),
			'content' => __( 'The specific settings for the currently selected goal — e.g., the target URL or CSS selector used to detect conversions.', 'opti-behavior' ),
			'simple'  => '',
		),
		'sig_gauge' => array(
			'title'   => __( 'Statistical Significance Gauge', 'opti-behavior' ),
			'content' => __( 'The arc fills as more data is collected. Reaching 95% means results are statistically significant and reliable enough to act on.', 'opti-behavior' ),
			'simple'  => '',
		),
		'sig_threshold' => array(
			'title'   => __( 'Confidence Threshold', 'opti-behavior' ),
			'content' => __( 'The threshold configured in Step 5. The test will not auto-declare a winner below this level.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_conversion_rate' => array(
			'title'   => __( 'Conversion Rate', 'opti-behavior' ),
			'content' => __( 'The percentage of visitors who achieved the goal while seeing this variant. Higher is better.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_impressions' => array(
			'title'   => __( 'Impressions', 'opti-behavior' ),
			'content' => __( 'The number of times this variant was shown to visitors since the test started.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_conversions' => array(
			'title'   => __( 'Conversions', 'opti-behavior' ),
			'content' => __( 'The raw number of visitors who completed the goal while seeing this variant.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_uplift' => array(
			'title'   => __( 'Uplift vs. Control', 'opti-behavior' ),
			'content' => __( 'The relative improvement (or decline) of this variant\'s conversion rate compared to the original Control.', 'opti-behavior' ),
			'simple'  => '',
		),
		'variant_significance' => array(
			'title'   => __( 'Statistical Significance', 'opti-behavior' ),
			'content' => __( 'The p-value indicates the probability that the observed difference is due to chance. Values below 0.05 are considered statistically significant at 95% confidence.', 'opti-behavior' ),
			'simple'  => '',
		),
		'declare_winner_btn' => array(
			'title'   => __( 'Declare Best Performer', 'opti-behavior' ),
			'content' => __( 'Stop the test and record the variant with the highest conversion rate as the winner.', 'opti-behavior' ),
			'simple'  => '',
		),
		'apply_permanently_btn' => array(
			'title'   => __( 'Apply Permanently', 'opti-behavior' ),
			'content' => __( 'Route 100% of traffic to this variant permanently. For Element tests, the variant\'s DOM changes are saved to the page. This action cannot be undone.', 'opti-behavior' ),
			'simple'  => '',
		),
		'conversion_chart' => array(
			'title'   => __( 'Conversion Rate Chart', 'opti-behavior' ),
			'content' => __( 'Shows how each variant\'s conversion rate evolved over the test\'s duration. Converging lines indicate an inconclusive test; diverging lines point to a clear winner.', 'opti-behavior' ),
			'simple'  => '',
		),
		'no_conversion_data' => array(
			'title'   => __( 'No Data Yet', 'opti-behavior' ),
			'content' => __( 'No conversions have been recorded yet. Make sure the goal is set up correctly and that real visitors have seen the variants.', 'opti-behavior' ),
			'simple'  => '',
		),
		'winner_banner' => array(
			'title'   => __( 'A Winner Has Emerged', 'opti-behavior' ),
			'content' => __( 'The test has collected enough data to declare a statistically significant winner with high confidence.', 'opti-behavior' ),
			'simple'  => '',
		),
		'winner_declare_pill' => array(
			'title'   => __( 'Declare Best Performer', 'opti-behavior' ),
			'content' => __( 'Officially end the test and record the variant with the highest conversion rate as the winner. The test status changes to Completed.', 'opti-behavior' ),
			'simple'  => '',
		),
		'winner_apply_pill' => array(
			'title'   => __( 'Apply Winner Permanently', 'opti-behavior' ),
			'content' => __( 'Overwrite the original page with the winning variant\'s content. Only available for Element and WooCommerce test types.', 'opti-behavior' ),
			'simple'  => '',
		),
		'winner_revert_pill' => array(
			'title'   => __( 'Disable Applied Winner', 'opti-behavior' ),
			'content' => __( 'Stop the permanent winner change and return the test to Completed. The winner and historical data remain available.', 'opti-behavior' ),
			'simple'  => '',
		),
	);
}

/**
 * Get the per-signal Smart Insights tooltips.
 *
 * One entry per signal_id that Smart Insights can store, Free and Pro alike.
 * Pro signal copy lives here on purpose: the Free plugin renders every stored
 * insight card (including Pro-generated ones) and must be able to explain what
 * the signal means without loading the Pro signals catalog.
 *
 * The `_default` entry is the fallback used for any signal_id not listed here,
 * so a new signal never renders a card without an explanation.
 *
 * @return array Signal id => tooltip definition.
 */
function opti_behavior_get_smart_insights_signal_tooltips() {
	$tooltips = array(
		'_default' => array(
			'title'   => __( 'What is this signal?', 'opti-behavior' ),
			'content' => __( 'Smart Insights compares this page, form, funnel, or audience against your own site baseline for the selected period. A card appears when the difference is large enough and is measured on enough sessions to be trusted.', 'opti-behavior' ),
			'simple'  => __( 'A behavior pattern that stands out from your normal numbers.', 'opti-behavior' ),
		),

		// --- Free signals ---------------------------------------------------
		'basic_bounce_alert' => array(
			'title'   => __( 'Bounce rate alert', 'opti-behavior' ),
			'content' => __( 'Measures the share of sessions on a page that end without a second page view. It triggers when a page with enough traffic bounces clearly more than your site baseline for the same period.', 'opti-behavior' ),
			'simple'  => __( 'Too many visitors leave this page after seeing only that page.', 'opti-behavior' ),
		),
		'basic_mobile_bounce_warning' => array(
			'title'   => __( 'Mobile bounce warning', 'opti-behavior' ),
			'content' => __( 'Compares the bounce rate of mobile sessions against desktop sessions on the same page. It triggers when mobile bounces noticeably more, which usually points at layout, speed, or tap-target problems on small screens.', 'opti-behavior' ),
			'simple'  => __( 'Phone visitors leave much faster than desktop visitors.', 'opti-behavior' ),
		),
		'high_exit_rate_page' => array(
			'title'   => __( 'High exit rate page', 'opti-behavior' ),
			'content' => __( 'Measures how often a page is the last one seen in a session. Unlike bounce rate it also counts visitors who arrived from another page, so it flags pages where journeys stop rather than pages where they start badly.', 'opti-behavior' ),
			'simple'  => __( 'Visitors reach this page and then stop browsing.', 'opti-behavior' ),
		),
		'high_traffic_low_engagement' => array(
			'title'   => __( 'High traffic, low engagement', 'opti-behavior' ),
			'content' => __( 'Combines traffic volume with engagement signals such as time on page, scroll depth, and interactions. It triggers when a page receives a lot of visits but visitors do very little once they arrive.', 'opti-behavior' ),
			'simple'  => __( 'A popular page that visitors barely engage with.', 'opti-behavior' ),
		),
		'low_scroll_depth_important_page' => array(
			'title'   => __( 'Low scroll depth', 'opti-behavior' ),
			'content' => __( 'Measures how far down a page visitors scroll on average. It triggers on pages with meaningful traffic where most visitors never reach the lower part of the page, so content or calls to action placed there are rarely seen.', 'opti-behavior' ),
			'simple'  => __( 'Most visitors never scroll far enough to see the bottom of this page.', 'opti-behavior' ),
		),
		'traffic_spike_observation' => array(
			'title'   => __( 'Traffic spike', 'opti-behavior' ),
			'content' => __( 'Compares session volume for the current period against the previous one. It triggers when traffic rises sharply. This is an observation, not a problem: it is there so you can check whether the extra traffic behaves like your usual audience.', 'opti-behavior' ),
			'simple'  => __( 'Traffic jumped compared with the period before.', 'opti-behavior' ),
		),

		// --- Pro signals: engagement and exits --------------------------------
		'quick_exit_pattern' => array(
			'title'   => __( 'Quick exit pattern', 'opti-behavior' ),
			'content' => __( 'Looks at how quickly sessions end after landing. It triggers when an unusual share of visitors leave within the first seconds, before any real reading or interaction can happen.', 'opti-behavior' ),
			'simple'  => __( 'Visitors leave within seconds of arriving.', 'opti-behavior' ),
		),
		'engagement_decay' => array(
			'title'   => __( 'Engagement decay', 'opti-behavior' ),
			'content' => __( 'Compares current engagement (time, scroll, interactions) against the same page in the previous period. It triggers when engagement drops over time rather than being low all along, which usually follows a content, layout, or speed change.', 'opti-behavior' ),
			'simple'  => __( 'This page used to hold attention better than it does now.', 'opti-behavior' ),
		),
		'visitor_confusion_pattern' => array(
			'title'   => __( 'Visitor confusion', 'opti-behavior' ),
			'content' => __( 'Combines hesitation signals such as erratic scrolling, back-and-forth navigation, and repeated clicking on the same area. It triggers when many sessions show the behavior of people who cannot find what they came for.', 'opti-behavior' ),
			'simple'  => __( 'Visitors act lost on this page.', 'opti-behavior' ),
		),
		'dead_or_rage_click_signal' => array(
			'title'   => __( 'Dead and rage clicks', 'opti-behavior' ),
			'content' => __( 'Dead clicks are clicks on something that does nothing. Rage clicks are several fast clicks on the same spot. The signal triggers when either happens often enough on one element to indicate a broken or misleading interface.', 'opti-behavior' ),
			'simple'  => __( 'Visitors click something that does not react.', 'opti-behavior' ),
		),
		'session_recording_opportunity' => array(
			'title'   => __( 'Session recording opportunity', 'opti-behavior' ),
			'content' => __( 'Fires when a measured problem has recordings available that show the behavior directly. It does not detect a new issue: it tells you that watching a few real sessions is the fastest way to understand this one.', 'opti-behavior' ),
			'simple'  => __( 'Recordings exist that show this problem happening.', 'opti-behavior' ),
		),
		'ab_test_opportunity' => array(
			'title'   => __( 'A/B test opportunity', 'opti-behavior' ),
			'content' => __( 'Fires on pages where traffic is high enough for a split test to reach a usable result, and where a measured weakness gives you something concrete to test. It suggests validating a fix rather than shipping it blind.', 'opti-behavior' ),
			'simple'  => __( 'This page has enough traffic to test a change properly.', 'opti-behavior' ),
		),

		// --- Pro signals: conversion and CTA ----------------------------------
		'cta_low_performance' => array(
			'title'   => __( 'Low CTA performance', 'opti-behavior' ),
			'content' => __( 'Measures how many visitors who saw a call to action actually clicked it. It triggers when the click rate is clearly below what comparable elements on your site achieve, after enough impressions to be reliable.', 'opti-behavior' ),
			'simple'  => __( 'People see this button or link but do not click it.', 'opti-behavior' ),
		),
		'poor_conversion_rate' => array(
			'title'   => __( 'Poor conversion rate', 'opti-behavior' ),
			'content' => __( 'Compares the conversion rate of a page or flow against your site baseline. It triggers when the rate stays below that baseline over enough sessions that the gap is unlikely to be noise.', 'opti-behavior' ),
			'simple'  => __( 'Traffic arrives but few visitors complete the goal.', 'opti-behavior' ),
		),
		'conversion_drop_alert' => array(
			'title'   => __( 'Conversion drop', 'opti-behavior' ),
			'content' => __( 'Compares the current conversion rate against the previous period for the same scope. It triggers on a sudden fall, which usually means something changed recently: a deploy, a price, a tracking break, or a traffic mix shift.', 'opti-behavior' ),
			'simple'  => __( 'Conversions fell compared with the period before.', 'opti-behavior' ),
		),
		'traffic_spike_without_conversion' => array(
			'title'   => __( 'Traffic spike without conversion', 'opti-behavior' ),
			'content' => __( 'Triggers when session volume rises sharply while conversions stay flat. The extra visitors behave differently from your usual audience, which typically points at a low-intent source, a campaign mismatch, or bot-like traffic.', 'opti-behavior' ),
			'simple'  => __( 'More visitors arrived, but the extra visitors do not convert.', 'opti-behavior' ),
		),

		// --- Pro signals: mobile ----------------------------------------------
		'mobile_friction_detected' => array(
			'title'   => __( 'Mobile friction', 'opti-behavior' ),
			'content' => __( 'Compares mobile sessions against desktop sessions on the same page using engagement, errors, and interaction signals. It triggers when the mobile experience is measurably worse, not merely different.', 'opti-behavior' ),
			'simple'  => __( 'The mobile version of this page performs worse than desktop.', 'opti-behavior' ),
		),
		'mobile_cta_click_rate_lower_than_desktop' => array(
			'title'   => __( 'Mobile CTA gap', 'opti-behavior' ),
			'content' => __( 'Compares the click rate of the same call to action on mobile and on desktop. It triggers when mobile clicks clearly lag, which usually means the element is hidden below the fold, too small to tap, or covered by a sticky bar.', 'opti-behavior' ),
			'simple'  => __( 'The same button gets clicked far less on phones.', 'opti-behavior' ),
		),

		// --- Pro signals: traffic quality and campaigns ------------------------
		'low_quality_traffic_source' => array(
			'title'   => __( 'Low quality traffic source', 'opti-behavior' ),
			'content' => __( 'Groups sessions by source and compares their engagement and conversion against your site average. It triggers when one source sends enough sessions to be judged and those sessions consistently underperform.', 'opti-behavior' ),
			'simple'  => __( 'One traffic source brings visitors who do not engage.', 'opti-behavior' ),
		),
		'campaign_page_intent_mismatch' => array(
			'title'   => __( 'Campaign and page mismatch', 'opti-behavior' ),
			'content' => __( 'Compares what a campaign promises with how its visitors behave on the landing page. It triggers when campaign traffic bounces or disengages much faster than other traffic on the same page, which points at a promise the page does not keep.', 'opti-behavior' ),
			'simple'  => __( 'Campaign visitors do not find what the ad led them to expect.', 'opti-behavior' ),
		),
		'segment_anomaly_detection' => array(
			'title'   => __( 'Segment anomaly', 'opti-behavior' ),
			'content' => __( 'Splits sessions by device, browser, country, source, and campaign and looks for one group that behaves very differently from the rest. It triggers only when the group is large enough for the difference to be statistically meaningful.', 'opti-behavior' ),
			'simple'  => __( 'One audience group behaves very differently from everyone else.', 'opti-behavior' ),
		),
		'returning_visitor_opportunity' => array(
			'title'   => __( 'Returning visitor opportunity', 'opti-behavior' ),
			'content' => __( 'Compares returning visitors with first-time visitors. It triggers when returning visitors show clearly stronger intent than the experience currently rewards, so a targeted offer or shortcut is likely to pay off.', 'opti-behavior' ),
			'simple'  => __( 'Your returning visitors are worth more than the page treats them.', 'opti-behavior' ),
		),

		// --- Pro signals: funnels and commerce ---------------------------------
		'funnel_dropoff_detected' => array(
			'title'   => __( 'Funnel drop-off', 'opti-behavior' ),
			'content' => __( 'Follows visitors from one funnel step to the next. It triggers when one step loses a much larger share of visitors than the steps around it, which isolates where the journey breaks.', 'opti-behavior' ),
			'simple'  => __( 'One step in the funnel loses far more visitors than the others.', 'opti-behavior' ),
		),
		'product_page_to_cart_dropoff' => array(
			'title'   => __( 'Product page to cart drop-off', 'opti-behavior' ),
			'content' => __( 'Measures how many product page visitors add an item to the cart. It triggers when that share falls clearly below your store baseline, which usually points at price, stock, shipping information, or a weak add-to-cart action.', 'opti-behavior' ),
			'simple'  => __( 'Visitors view the product but do not add it to the cart.', 'opti-behavior' ),
		),
		'product_page_engagement_issue' => array(
			'title'   => __( 'Product page engagement issue', 'opti-behavior' ),
			'content' => __( 'Looks at time, scroll, and interactions on product pages. It triggers when visitors do not reach the information that normally drives a purchase decision, such as images, description, or reviews.', 'opti-behavior' ),
			'simple'  => __( 'Shoppers do not engage with this product page.', 'opti-behavior' ),
		),
		'cart_to_checkout_dropoff' => array(
			'title'   => __( 'Cart to checkout drop-off', 'opti-behavior' ),
			'content' => __( 'Measures how many visitors with a filled cart actually start checkout. It triggers when that share is unusually low, which typically points at unexpected costs, an account requirement, or an unclear checkout entry point.', 'opti-behavior' ),
			'simple'  => __( 'Carts are filled but checkout is never started.', 'opti-behavior' ),
		),
		'checkout_to_purchase_dropoff' => array(
			'title'   => __( 'Checkout to purchase drop-off', 'opti-behavior' ),
			'content' => __( 'Measures how many visitors who start checkout finish the order. It triggers when the completion share is clearly below baseline, which is the most expensive place on the site to lose someone.', 'opti-behavior' ),
			'simple'  => __( 'Checkout is started but the order is not completed.', 'opti-behavior' ),
		),
		'checkout_friction_detected' => array(
			'title'   => __( 'Checkout friction', 'opti-behavior' ),
			'content' => __( 'Combines errors, hesitation, field corrections, and time spent inside the checkout steps. It triggers when the checkout is measurably harder to complete than the rest of the journey, and points at the step responsible.', 'opti-behavior' ),
			'simple'  => __( 'Something inside checkout is slowing buyers down.', 'opti-behavior' ),
		),

		// --- Pro signals: forms and errors -------------------------------------
		'form_abandonment_detected' => array(
			'title'   => __( 'Form abandonment', 'opti-behavior' ),
			'content' => __( 'Compares how many visitors start a form against how many submit it. It triggers when a form with enough starts is abandoned much more often than your other forms.', 'opti-behavior' ),
			'simple'  => __( 'Visitors begin this form but never send it.', 'opti-behavior' ),
		),
		'form_error_friction' => array(
			'title'   => __( 'Form error friction', 'opti-behavior' ),
			'content' => __( 'Counts validation errors raised while a form is being filled. It triggers when errors are frequent enough to be a design problem rather than normal typing mistakes, for example an unclear format rule.', 'opti-behavior' ),
			'simple'  => __( 'This form rejects visitors too often before they can submit.', 'opti-behavior' ),
		),
		'field_level_friction' => array(
			'title'   => __( 'Field level friction', 'opti-behavior' ),
			'content' => __( 'Narrows form problems down to a single field using time spent, corrections, re-entries, and abandonment at that field. It triggers when one field is measurably harder than the rest of the form.', 'opti-behavior' ),
			'simple'  => __( 'One specific field is where people give up.', 'opti-behavior' ),
		),
		'error_impact_on_conversion' => array(
			'title'   => __( 'Error impact on conversion', 'opti-behavior' ),
			'content' => __( 'Compares sessions that hit a JavaScript error with sessions that did not, on the same page. It triggers when the error group converts or engages clearly worse, which turns a technical error into a measured business cost.', 'opti-behavior' ),
			'simple'  => __( 'Sessions that hit this error perform worse than clean sessions.', 'opti-behavior' ),
		),
	);

	/**
	 * Filters the per-signal Smart Insights tooltip copy.
	 *
	 * @param array $tooltips Signal id => array with title, content and simple keys.
	 */
	return apply_filters( 'opti_behavior_smart_insights_signal_tooltips', $tooltips );
}

/**
 * Get the Smart Insights detail-modal section tooltips.
 *
 * Keys match the section identifiers used by assets/js/smart-insights.js when it
 * renders the insight detail modal, so each rendered section heading can carry
 * the same purple "?" helper used on the settings screens.
 *
 * @return array Section key => tooltip definition.
 */
function opti_behavior_get_smart_insights_section_tooltips() {
	$tooltips = array(
		'impact' => array(
			'title'   => __( 'Business impact', 'opti-behavior' ),
			'content' => __( 'Translates the signal into what it costs you: how many sessions or visitors are affected, and where available the estimated lost revenue. Figures are estimates based on your own measured rates for the selected period, not predictions.', 'opti-behavior' ),
			'simple'  => __( 'What this problem is worth in visitors or money.', 'opti-behavior' ),
		),
		'diagnosis' => array(
			'title'   => __( 'Diagnosis', 'opti-behavior' ),
			'content' => __( 'The interpretation of the measured numbers: what the data most likely means and why it matters for your site. It explains the finding; it does not add new measurements.', 'opti-behavior' ),
			'simple'  => __( 'What the numbers most likely mean.', 'opti-behavior' ),
		),
		'evidence' => array(
			'title'   => __( 'Evidence', 'opti-behavior' ),
			'content' => __( 'The raw metrics behind the card: the measured value, the baseline it is compared with, and the number of sessions the comparison rests on. If the session count is small, treat the finding as directional.', 'opti-behavior' ),
			'simple'  => __( 'The actual numbers that triggered this insight.', 'opti-behavior' ),
		),
		'evidence_refs' => array(
			'title'   => __( 'Evidence and proof', 'opti-behavior' ),
			'content' => __( 'Direct links to the recordings, heatmaps, form reports, or error entries that show this behavior. Each link opens the matching report already filtered to the page, period, and segment of this insight.', 'opti-behavior' ),
			'simple'  => __( 'Open the recordings and reports that prove this finding.', 'opti-behavior' ),
		),
		'hypothesis' => array(
			'title'   => __( 'Hypothesis and next experiment', 'opti-behavior' ),
			'content' => __( 'A testable explanation of the cause, plus the change worth trying and how to measure it. Treat it as a starting point for an A/B test, not as a confirmed cause.', 'opti-behavior' ),
			'simple'  => __( 'What to try next, and how to check whether it worked.', 'opti-behavior' ),
		),
		'experiment' => array(
			'title'   => __( 'Experiment result', 'opti-behavior' ),
			'content' => __( 'The outcome of the test that was run for this insight: whether the metric improved, stayed flat, or got worse after the change, measured against the period before it.', 'opti-behavior' ),
			'simple'  => __( 'What happened after the change was applied.', 'opti-behavior' ),
		),
		'where' => array(
			'title'   => __( 'Where is the problem?', 'opti-behavior' ),
			'content' => __( 'Splits the affected sessions by device, browser, country, traffic source, and campaign to show whether one audience carries the problem or whether it is spread evenly. If it is spread evenly, the page itself is the cause, not a segment.', 'opti-behavior' ),
			'simple'  => __( 'Which audience group actually has this problem.', 'opti-behavior' ),
		),
		'where_pulse' => array(
			'title'   => __( 'Segment health', 'opti-behavior' ),
			'content' => __( 'One dot per audience group. Red means the group carries the problem, amber means it is worth watching, green means it behaves normally, and gray means too few sessions were measured to judge it. The counter shows how many groups cleared the data floor.', 'opti-behavior' ),
			'simple'  => __( 'Red carries the problem, amber is borderline, green is fine, gray is not measurable.', 'opti-behavior' ),
		),
		'where_outliers' => array(
			'title'   => __( 'Segments that differ most', 'opti-behavior' ),
			'content' => __( 'Each card compares one audience group with everyone else on the same metric. The paired bars show both values, the arrow shows whether the group got better or worse between the first and second half of the period, and the small chart shows the daily values. Cards marked "Combined" describe a pair such as one browser on one country, detected because neither part alone explained the problem. Clicking a card re-scopes the chart and the report links to that group.', 'opti-behavior' ),
			'simple'  => __( 'One card per audience group, compared against everyone else.', 'opti-behavior' ),
		),
		'where_mix' => array(
			'title'   => __( 'Did the audience change?', 'opti-behavior' ),
			'content' => __( 'Compares the share each country, source, or device holds this period against the previous one. A large shift means your audience composition changed, so a metric can move without any page changing. Shown only when the shift is big enough and measured on enough sessions.', 'opti-behavior' ),
			'simple'  => __( 'Whether the metric moved because your audience changed, not the page.', 'opti-behavior' ),
		),
		'context' => array(
			'title'   => __( 'Context', 'opti-behavior' ),
			'content' => __( 'The scope of the finding: the period analyzed, the page or entity involved, the confidence level, and how often this insight has recurred. Read it before acting so you know exactly what the numbers cover.', 'opti-behavior' ),
			'simple'  => __( 'What period, page, and reliability this insight covers.', 'opti-behavior' ),
		),
		'next_action' => array(
			'title'   => __( 'Next action', 'opti-behavior' ),
			'content' => __( 'The single highest-value step for this insight, chosen from the full recommendation list. Start here when you only have time for one change.', 'opti-behavior' ),
			'simple'  => __( 'The one thing to do first.', 'opti-behavior' ),
		),
		'related_reports' => array(
			'title'   => __( 'Related reports', 'opti-behavior' ),
			'content' => __( 'Shortcuts into the reports that hold the underlying data: heatmaps, recordings, funnels, forms, and traffic reports. Each link carries the page, period, and segment of this insight, so you land on the matching view instead of the report home.', 'opti-behavior' ),
			'simple'  => __( 'Open the full reports behind this insight.', 'opti-behavior' ),
		),
		'recommendations' => array(
			'title'   => __( 'Recommended actions', 'opti-behavior' ),
			'content' => __( 'Concrete changes matched to this signal type, ordered by expected effect. They are suggestions based on common causes for this pattern, so check them against what you know about the page before applying one.', 'opti-behavior' ),
			'simple'  => __( 'Changes worth trying, most promising first.', 'opti-behavior' ),
		),
		'causes' => array(
			'title'   => __( 'Likely causes', 'opti-behavior' ),
			'content' => __( 'The explanations that most often produce this pattern. Causes marked as measured were confirmed against your own data; the others are candidates to rule out one by one.', 'opti-behavior' ),
			'simple'  => __( 'What usually causes a pattern like this.', 'opti-behavior' ),
		),
	);

	/**
	 * Filters the Smart Insights detail-modal section tooltip copy.
	 *
	 * @param array $tooltips Section key => array with title, content and simple keys.
	 */
	return apply_filters( 'opti_behavior_smart_insights_section_tooltips', $tooltips );
}
