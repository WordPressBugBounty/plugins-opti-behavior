<?php
/**
 * Opti-Behavior Bot Tracker Class
 *
 * Handles server-side bot detection and tracking
 *
 * @package opti-behavior-heatmap
 * @copyright 2025 Opti-User
 * @version 1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opti_Behavior_Heatmap_Bot_Tracker
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database queries required for bot tracking. Custom tables used for high-volume tracking. Caching not appropriate for real-time bot detection.
class Opti_Behavior_Heatmap_Bot_Tracker {

	/**
	 * Core instance
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Bot patterns for detection
	 *
	 * @var array
	 */
	private $bot_patterns = array();

	/**
	 * Constructor
	 *
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->init_bot_patterns();
		$this->init_hooks();
	}

	/**
	 * Initialize bot patterns
	 */
	private function init_bot_patterns() {
		$this->bot_patterns = self::get_known_bot_patterns();
	}

	/**
	 * Get the named bot pattern registry.
	 *
	 * Public static accessor so other components (e.g. the ingest gate,
	 * Opti_Behavior_Ingest_Gate) can reuse the canonical bot list without
	 * duplicating it. Keys are bot-type slugs, values are the UA substrings
	 * matched case-insensitively.
	 *
	 * @since 1.6.1
	 * @return array<string,string> bot_type => UA substring pattern.
	 */
	public static function get_known_bot_patterns() {
		return array(
			// AI crawlers and AI user-triggered fetchers.
			'oai-searchbot'                 => 'OAI-SearchBot',
			'oai-adsbot'                    => 'OAI-AdsBot',
			'gptbot'                        => 'GPTBot',
			'chatgpt-user'                  => 'ChatGPT-User',
			'claudebot'                     => 'ClaudeBot',
			'claude-user'                   => 'Claude-User',
			'claude-searchbot'              => 'Claude-SearchBot',
			'claude-web'                    => 'Claude-Web',
			'anthropic-ai'                  => 'anthropic-ai',
			'perplexitybot'                 => 'PerplexityBot',
			'perplexity-user'               => 'Perplexity-User',
			'google-extended'               => 'Google-Extended',
			'googleother-image'             => 'GoogleOther-Image',
			'googleother-video'             => 'GoogleOther-Video',
			'googleother'                   => 'GoogleOther',
			'gemini'                        => 'Gemini',
			'bard'                          => 'Bard',
			'ccbot'                         => 'CCBot',
			'bytespider'                    => 'Bytespider',
			'amazonbot'                     => 'Amazonbot',
			'applebot-extended'             => 'Applebot-Extended',
			'applebot'                      => 'Applebot',
			'meta-externalagent'            => 'meta-externalagent',
			'meta-facebookbot'              => 'FacebookBot',
			'diffbot'                       => 'Diffbot',
			'cohere-ai'                     => 'cohere-ai',
			'cohere-training-data-crawler'  => 'cohere-training-data-crawler',
			'youbot'                        => 'YouBot',
			'omgilibot'                     => 'omgilibot',
			'omgili'                        => 'omgili',
			'ai2bot'                        => 'AI2Bot',
			'allenai'                       => 'AllenAI',
			'duckassistbot'                 => 'DuckAssistBot',
			'grokbot'                       => 'GrokBot',
			'xai-bot'                       => 'xAI-Bot',
			'xai'                           => 'xAI',
			'grok'                          => 'Grok',
			'mistralai-user'                => 'MistralAI-User',
			'mistral-ai'                    => 'mistral-ai',
			'deepseekbot'                   => 'DeepSeekBot',
			'deepseek'                      => 'DeepSeek',
			// Search engines.
			'googlebot'       => 'Googlebot',
			'googlebot-image' => 'Googlebot-Image',
			'googlebot-news'  => 'Googlebot-News',
			'bingbot'         => 'bingbot',
			'bingpreview'     => 'BingPreview',
			'yahoo'           => 'Yahoo! Slurp',
			'duckduckbot'     => 'DuckDuckBot',
			'baiduspider'     => 'Baiduspider',
			'yandexbot'       => 'YandexBot',
			'petalbot'        => 'PetalBot',
			'sogou'           => 'Sogou',
			'exabot'          => 'Exabot',
			'seznambot'       => 'SeznamBot',
			// Social media.
			'facebookbot'     => 'facebookexternalhit',
			'twitterbot'      => 'Twitterbot',
			'linkedinbot'     => 'LinkedInBot',
			'pinterestbot'    => 'Pinterest',
			'whatsapp'        => 'WhatsApp',
			// SEO tools.
			'ahrefsbot'       => 'AhrefsBot',
			'semrushbot'      => 'SemrushBot',
			'mj12bot'         => 'MJ12bot',
			'dotbot'          => 'DotBot',
			'blexbot'         => 'BLEXBot',
			'dataforseobot'   => 'DataForSeoBot',
			'siteauditbot'    => 'SiteAuditBot',
			'screaming-frog'  => 'Screaming Frog',
			// Monitoring.
			'uptimerobot'     => 'UptimeRobot',
			'pingdom'         => 'Pingdom',
			'statuspage'      => 'StatusPage',
		);
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Use template_redirect to track bot visits before page renders
		add_action( 'template_redirect', array( $this, 'track_bot_visit' ), 1 );
	}

	/**
	 * Track bot visit
	 */
	public function track_bot_visit() {
		// Skip if in admin area
		if ( is_admin() ) {
			return;
		}

		// Get user agent
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		
		if ( empty( $user_agent ) ) {
			return;
		}

		// Detect bot type
		$bot_type = $this->detect_bot_type( $user_agent );
		
		if ( ! $bot_type ) {
			return;
		}

		// Get additional data
		$ip = $this->get_client_ip();
		$visited_url = $this->get_current_url();
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		// Store bot visit. Deferred to shutdown so the synchronous INSERT no
		// longer runs inside template_redirect (perf fix, customer report
		// 2026-07): the page renders first, the write happens after output.
		add_action(
			'shutdown',
			function () use ( $bot_type, $user_agent, $ip, $visited_url, $referrer ) {
				$this->store_bot_visit( $bot_type, $user_agent, $ip, $visited_url, $referrer );
			},
			99
		);
	}

	/**
	 * Detect bot type from user agent
	 *
	 * @param string $user_agent User agent string.
	 * @return string|null Bot type or null if not a bot.
	 */
	private function detect_bot_type( $user_agent ) {
		$user_agent_lower = strtolower( $user_agent );

		// Check against known bot patterns
		foreach ( $this->bot_patterns as $bot_type => $pattern ) {
			if ( stripos( $user_agent, $pattern ) !== false ) {
				return $bot_type;
			}
		}

		// Check custom bot patterns from settings
		$settings = get_option( 'opti_behavior_traffic_settings', array() );
		$custom_patterns = isset( $settings['custom_bot_patterns'] ) ? $settings['custom_bot_patterns'] : array();

		if ( ! empty( $custom_patterns ) && is_array( $custom_patterns ) ) {
			foreach ( $custom_patterns as $pattern ) {
				$pattern = trim( $pattern );
				if ( ! empty( $pattern ) && stripos( $user_agent, $pattern ) !== false ) {
					return 'custom';
				}
			}
		}

		return null;
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip = explode( ',', $ip );
			$ip = trim( $ip[0] );
		} elseif ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// Validate IP address
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '';
	}

	/**
	 * Get current URL
	 *
	 * @return string
	 */
	private function get_current_url() {
		$protocol = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http';
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return esc_url_raw( $protocol . '://' . $host . $request_uri );
	}

	/**
	 * Store bot visit in database
	 *
	 * @param string $bot_type Bot type.
	 * @param string $user_agent User agent string.
	 * @param string $ip IP address.
	 * @param string $visited_url Visited URL.
	 * @param string $referrer Referrer URL.
	 */
	private function store_bot_visit( $bot_type, $user_agent, $ip, $visited_url, $referrer ) {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}optibehavior_bot_visits",
			array(
				'bot_type'    => $bot_type,
				'user_agent'  => $user_agent,
				'ip'          => $ip,
				'visited_url' => $visited_url,
				'referrer'    => $referrer,
				'visit_time'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
