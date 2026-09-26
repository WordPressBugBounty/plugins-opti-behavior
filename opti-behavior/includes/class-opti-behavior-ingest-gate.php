<?php
/**
 * Ingest gate for frontend tracking endpoints.
 *
 * Central per-request gate that decides whether an incoming tracking event
 * (heatmap, session, heartbeat, geo, funnel, A/B) must be ignored because it
 * originates from a bot user agent or an excluded IP.
 *
 * Rationale (cache-poisoning fix): render-time bot/IP checks bake a
 * per-visitor decision into page-cached HTML — one bot or excluded-IP visit
 * priming the cache strips the tracker for every subsequent human visitor.
 * These decisions therefore run here, at ingest time (admin-ajax is never
 * page-cached), where they are authoritative for every single request.
 *
 * The bot list is the union of:
 * - the verbatim substring list formerly in
 *   Opti_Behavior_Heatmap_Frontend::is_bot() (strictness parity: at least as
 *   strict as the old render-time gate), plus
 * - the named patterns from Opti_Behavior_Heatmap_Bot_Tracker, plus
 * - the site's `custom_bot_patterns` (Settings → Traffic & Behavior).
 *
 * @package Opti_Behavior
 * @since   1.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static ingest-gate helper.
 *
 * @since 1.6.1
 */
class Opti_Behavior_Ingest_Gate {

	/**
	 * Per-request cache of bot verdicts, keyed by user agent.
	 *
	 * @var array<string,bool>
	 */
	private static $ua_cache = array();

	/**
	 * Substring patterns formerly hard-coded in the frontend `is_bot()`
	 * render-time check (public/class-opti-behavior-heatmap-frontend.php).
	 * Kept verbatim so the ingest gate is at least as strict as the old gate.
	 *
	 * @var string[]
	 */
	private static $legacy_frontend_patterns = array(
		'bot', 'crawl', 'spider', 'slurp', 'search', 'index',
		'facebook', 'twitter', 'linkedin', 'pinterest',
		'chatgpt-user', 'claude-user', 'claude-web', 'anthropic-ai',
		'perplexity-user', 'google-extended', 'googleother',
		'gemini', 'bard', 'ccbot', 'bytespider', 'amazonbot',
		'applebot', 'meta-externalagent', 'cohere-ai', 'youBot',
		'omgili', 'ai2bot', 'allenai', 'duckassistbot',
		'grok', 'xai', 'mistral-ai', 'deepseek',
	);

	/**
	 * Get the full list of bot UA substring patterns.
	 *
	 * Union of the legacy frontend list, the bot-tracker named patterns, and
	 * the site's custom patterns. Case-insensitive substring semantics.
	 *
	 * @since 1.6.1
	 * @return string[] Deduplicated (case-insensitively) pattern list.
	 */
	public static function get_bot_patterns() {
		$patterns = self::$legacy_frontend_patterns;

		// Named patterns from the server-side bot tracker (Googlebot, bingbot,
		// AhrefsBot, uptime monitors, …).
		if ( class_exists( 'Opti_Behavior_Heatmap_Bot_Tracker' ) ) {
			$patterns = array_merge(
				$patterns,
				array_values( Opti_Behavior_Heatmap_Bot_Tracker::get_known_bot_patterns() )
			);
		}

		// Custom patterns from Settings → Traffic & Behavior.
		$settings        = get_option( 'opti_behavior_traffic_settings', array() );
		$custom_patterns = isset( $settings['custom_bot_patterns'] ) && is_array( $settings['custom_bot_patterns'] )
			? $settings['custom_bot_patterns']
			: array();
		foreach ( $custom_patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern ) {
				$patterns[] = $pattern;
			}
		}

		// Case-insensitive dedupe (matching is stripos-based anyway).
		$deduped = array();
		foreach ( $patterns as $pattern ) {
			$deduped[ strtolower( $pattern ) ] = $pattern;
		}
		$patterns = array_values( $deduped );

		/**
		 * Filter the bot UA substring patterns used by the ingest gate.
		 *
		 * @since 1.6.1
		 * @param string[] $patterns Case-insensitive substring patterns.
		 */
		return apply_filters( 'opti_behavior_ingest_bot_patterns', $patterns );
	}

	/**
	 * Whether a user agent belongs to a known bot.
	 *
	 * Empty UA is NOT treated as a bot (parity with the old frontend check).
	 *
	 * @since 1.6.1
	 * @param string|null $ua User agent to test; null = current request's UA.
	 * @return bool True when the UA matches a bot pattern.
	 */
	public static function is_bot_user_agent( $ua = null ) {
		if ( null === $ua ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		}
		$ua = (string) $ua;

		if ( '' === $ua ) {
			return false;
		}

		if ( isset( self::$ua_cache[ $ua ] ) ) {
			return self::$ua_cache[ $ua ];
		}

		$is_bot = false;
		foreach ( self::get_bot_patterns() as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
				$is_bot = true;
				break;
			}
		}

		self::$ua_cache[ $ua ] = $is_bot;
		return $is_bot;
	}

	/**
	 * Whether the current ingest request must be rejected (silently ignored).
	 *
	 * True when the visitor is a bot (UA match) or their IP is excluded from
	 * tracking. Callers should respond with a success envelope carrying
	 * `status: ignored` so tracker JS does not retry.
	 *
	 * @since 1.6.1
	 * @param string $context Optional endpoint label for debug logging.
	 * @return bool True when the event must NOT be ingested.
	 */
	public static function should_reject( $context = '' ) {
		$reason = '';

		if ( self::is_bot_user_agent() ) {
			$reason = 'bot_user_agent';
		} elseif ( class_exists( 'Opti_Behavior_IP_Exclusion' ) && Opti_Behavior_IP_Exclusion::is_excluded() ) {
			$reason = 'excluded_ip';
		}

		if ( '' === $reason ) {
			return false;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated behind WP_DEBUG_LOG.
			error_log( sprintf( '[Opti-Behavior] Ingest gate dropped event (%s): %s', $reason, $context ) );
		}

		return true;
	}

	/**
	 * Fixed-budget rate limiter for public ingest writes and outbound lookups.
	 *
	 * Counts one hit in a transient and answers false once `$limit` hits were
	 * counted inside `$window` seconds. Scope `ip` keys the budget to the
	 * visitor IP (Opti_Behavior_IP_Exclusion::get_visitor_ip(): forwarding
	 * headers only from a trusted proxy); scope `site` is one budget for the
	 * whole site. Callers only
	 * consult it on rare paths (a NEW funnel entry, an uncached geolocation
	 * lookup), never on every pageview. Added for the WordPress.org security
	 * review of 1.9.2 (2026-09-25).
	 *
	 * @param string $bucket Budget name (a-z, 0-9, _).
	 * @param int    $limit  Hits allowed per window; <= 0 disables the limit.
	 * @param int    $window Window length in seconds.
	 * @param string $scope  'ip' (default) or 'site'.
	 * @return bool True when the hit is allowed (and was counted).
	 */
	public static function allow_hit( $bucket, $limit, $window, $scope = 'ip' ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true;
		}

		$who = 'site';
		if ( 'ip' === $scope ) {
			$ip = class_exists( 'Opti_Behavior_IP_Exclusion' ) ? Opti_Behavior_IP_Exclusion::get_visitor_ip() : '';
			// A private / reserved connecting address is a reverse proxy or a
			// local test run: every visitor shares it, so a per-IP budget would
			// cap the whole site. It cannot be forged from outside, so skip.
			if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
			$who = substr( md5( $ip ), 0, 16 );
		}
		$key   = 'ob_rl_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $bucket ) ) . '_' . $who;
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, max( 1, (int) $window ) );
		return true;
	}

	/**
	 * Reset the per-request cache (used by tests / after settings save).
	 *
	 * @since 1.6.1
	 */
	public static function flush_cache() {
		self::$ua_cache = array();
	}
}
