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
			// OPT-2859: agents of AI companies we did not name yet. Every
			// pattern is a full agent token on purpose: a generic word
			// ('Operator', 'OpenAI', 'Devin') would match ordinary user agents
			// through stripos() and invent robots.
			'chatgpt-agent'                 => 'ChatGPT-Agent',
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
			'googleagent-mariner'           => 'GoogleAgent-Mariner',
			'google-notebooklm'             => 'Google-NotebookLM',
			'google-cloudvertexbot'         => 'Google-CloudVertexBot',
			'gemini'                        => 'Gemini',
			'bard'                          => 'Bard',
			'ccbot'                         => 'CCBot',
			'bytespider'                    => 'Bytespider',
			'amazonbot'                     => 'Amazonbot',
			'amzn-searchbot'                => 'Amzn-SearchBot',
			'amzn-user'                     => 'Amzn-User',
			'amazonbuyforme'                => 'AmazonBuyForMe',
			'bedrockbot'                    => 'bedrockbot',
			'novaact'                       => 'NovaAct',
			'applebot-extended'             => 'Applebot-Extended',
			'applebot'                      => 'Applebot',
			'meta-externalagent'            => 'meta-externalagent',
			'meta-externalfetcher'          => 'meta-externalfetcher',
			'meta-webindexer'               => 'meta-webindexer',
			'meta-facebookbot'              => 'FacebookBot',
			'azureai-searchbot'             => 'AzureAI-SearchBot',
			'pangubot'                      => 'PanguBot',
			'alibaba-ai'                    => 'Alibaba-AI',
			'character-ai'                  => 'character-ai',
			'poe-chat-bot'                  => 'poe-chat-bot',
			'phindbot'                      => 'PhindBot',
			'iaskbot'                       => 'iAskBot',
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
	 * AI bots only: company + why they came (OPT-2825).
	 *
	 * Keys are the `bot_type` slugs stored in `optibehavior_bot_visits` (same
	 * keys as get_known_bot_patterns()). A bot absent from this map is NOT an
	 * AI bot and never appears in the AI row of the Traffic screen — classic
	 * search engines (Googlebot, bingbot, Applebot) stay out on purpose.
	 *
	 * Intents mirror the four pills of the Traffic screen:
	 * user_responses (an AI read the page to answer someone now),
	 * indexing_search, model_training, data_collection.
	 *
	 * @since 1.11.0
	 * @return array<string,array{company:string,intent:string}>
	 */
	public static function get_ai_bot_map() {
		$map = array(
			// OpenAI.
			'gptbot'                       => array( 'company' => 'OpenAI', 'intent' => 'model_training' ),
			'chatgpt-user'                 => array( 'company' => 'OpenAI', 'intent' => 'user_responses' ),
			'oai-searchbot'                => array( 'company' => 'OpenAI', 'intent' => 'indexing_search' ),
			'oai-adsbot'                   => array( 'company' => 'OpenAI', 'intent' => 'data_collection' ),
			'chatgpt-agent'                => array( 'company' => 'OpenAI', 'intent' => 'user_responses' ),
			// Anthropic.
			'claudebot'                    => array( 'company' => 'Anthropic', 'intent' => 'model_training' ),
			'anthropic-ai'                 => array( 'company' => 'Anthropic', 'intent' => 'model_training' ),
			'claude-user'                  => array( 'company' => 'Anthropic', 'intent' => 'user_responses' ),
			'claude-web'                   => array( 'company' => 'Anthropic', 'intent' => 'user_responses' ),
			'claude-searchbot'             => array( 'company' => 'Anthropic', 'intent' => 'indexing_search' ),
			// Perplexity.
			'perplexitybot'                => array( 'company' => 'Perplexity', 'intent' => 'indexing_search' ),
			'perplexity-user'              => array( 'company' => 'Perplexity', 'intent' => 'user_responses' ),
			// Google (AI surfaces only; Googlebot is a classic search engine).
			'google-extended'              => array( 'company' => 'Google', 'intent' => 'model_training' ),
			'googleagent-mariner'          => array( 'company' => 'Google', 'intent' => 'user_responses' ),
			'google-notebooklm'            => array( 'company' => 'Google', 'intent' => 'user_responses' ),
			'google-cloudvertexbot'        => array( 'company' => 'Google', 'intent' => 'data_collection' ),
			'gemini'                       => array( 'company' => 'Google', 'intent' => 'user_responses' ),
			'bard'                         => array( 'company' => 'Google', 'intent' => 'user_responses' ),
			// Meta. `meta-facebookbot` matches the token "FacebookBot" only:
			// the link preview agent is "facebookexternalhit", another slug, so
			// it stays in Bot Traffic and never lands in the AI row (OPT-2859).
			'meta-externalagent'           => array( 'company' => 'Meta', 'intent' => 'data_collection' ),
			'meta-externalfetcher'         => array( 'company' => 'Meta', 'intent' => 'user_responses' ),
			'meta-webindexer'              => array( 'company' => 'Meta', 'intent' => 'indexing_search' ),
			'meta-facebookbot'             => array( 'company' => 'Meta', 'intent' => 'data_collection' ),
			// ByteDance, Amazon, Apple, Microsoft.
			'bytespider'                   => array( 'company' => 'ByteDance', 'intent' => 'model_training' ),
			'amazonbot'                    => array( 'company' => 'Amazon', 'intent' => 'model_training' ),
			'amzn-searchbot'               => array( 'company' => 'Amazon', 'intent' => 'indexing_search' ),
			'amzn-user'                    => array( 'company' => 'Amazon', 'intent' => 'user_responses' ),
			'amazonbuyforme'               => array( 'company' => 'Amazon', 'intent' => 'user_responses' ),
			'novaact'                      => array( 'company' => 'Amazon', 'intent' => 'user_responses' ),
			'bedrockbot'                   => array( 'company' => 'Amazon', 'intent' => 'data_collection' ),
			'applebot-extended'            => array( 'company' => 'Apple', 'intent' => 'model_training' ),
			'azureai-searchbot'            => array( 'company' => 'Microsoft', 'intent' => 'indexing_search' ),
			// xAI.
			'grokbot'                      => array( 'company' => 'xAI', 'intent' => 'model_training' ),
			'xai-bot'                      => array( 'company' => 'xAI', 'intent' => 'model_training' ),
			'xai'                          => array( 'company' => 'xAI', 'intent' => 'model_training' ),
			'grok'                         => array( 'company' => 'xAI', 'intent' => 'user_responses' ),
			// Mistral, DeepSeek, Cohere.
			'mistralai-user'               => array( 'company' => 'Mistral', 'intent' => 'user_responses' ),
			'mistral-ai'                   => array( 'company' => 'Mistral', 'intent' => 'model_training' ),
			'deepseek'                     => array( 'company' => 'DeepSeek', 'intent' => 'user_responses' ),
			'deepseekbot'                  => array( 'company' => 'DeepSeek', 'intent' => 'model_training' ),
			'cohere-ai'                    => array( 'company' => 'Cohere', 'intent' => 'model_training' ),
			'cohere-training-data-crawler' => array( 'company' => 'Cohere', 'intent' => 'model_training' ),
			// Other AI companies.
			'pangubot'                     => array( 'company' => 'Huawei', 'intent' => 'model_training' ),
			'alibaba-ai'                   => array( 'company' => 'Alibaba', 'intent' => 'model_training' ),
			'character-ai'                 => array( 'company' => 'Character.AI', 'intent' => 'user_responses' ),
			'poe-chat-bot'                 => array( 'company' => 'Poe', 'intent' => 'user_responses' ),
			'phindbot'                     => array( 'company' => 'Phind', 'intent' => 'indexing_search' ),
			'iaskbot'                      => array( 'company' => 'iAsk', 'intent' => 'indexing_search' ),
			// Search-style AI assistants.
			'duckassistbot'                => array( 'company' => 'DuckDuckGo', 'intent' => 'user_responses' ),
			'youbot'                       => array( 'company' => 'You.com', 'intent' => 'indexing_search' ),
			'ai2bot'                       => array( 'company' => 'Allen Institute', 'intent' => 'model_training' ),
			'allenai'                      => array( 'company' => 'Allen Institute', 'intent' => 'model_training' ),
			// OPT-2859: ccbot (Common Crawl), diffbot, omgili / omgilibot and the
			// three GoogleOther agents are dataset / crawl services, not agents of
			// an AI company: they stay in the Bot Traffic widget, so the same pass
			// is never counted on two widgets of the same screen.
		);

		/**
		 * Filter the AI bot map (company + intent per stored bot_type).
		 *
		 * @since 1.11.0
		 * @param array $map bot_type => { company, intent }.
		 */
		return (array) apply_filters( 'opti_behavior_ai_bot_map', $map );
	}

	/**
	 * The four AI intent keys, in display order (OPT-2825).
	 *
	 * @since 1.11.0
	 * @return string[]
	 */
	public static function get_ai_intent_keys() {
		return array( 'user_responses', 'indexing_search', 'model_training', 'data_collection' );
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
		// Anonymous Mode never keeps a full address: the bot log gets the network only (OPT-843).
		$ip = $this->get_storable_ip();
		// Personal data (email, access_code, token…) out of the bot log (OPT-574).
		$visited_url = opti_behavior_strip_sensitive_query_args( $this->get_current_url() );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? opti_behavior_strip_sensitive_query_args( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';

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
		// Forwarding headers only from a trusted proxy — see
		// IP_Exclusion::get_visitor_ip().
		if ( class_exists( 'Opti_Behavior_IP_Exclusion' ) ) {
			$ip = Opti_Behavior_IP_Exclusion::get_visitor_ip();
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		// Validate IP address
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '';
	}

	/**
	 * IP value a bot log row may store for the current request.
	 *
	 * Detection relies on the User-Agent only, so a human whose browser matches a
	 * pattern (link previews, a wide custom pattern) can land here. A bot has no
	 * consent to give: the privacy mode alone decides. Full mode keeps the address,
	 * Anonymous Mode (also the fallback for an unset / unknown value) keeps the
	 * network part only.
	 *
	 * @return string
	 */
	private function get_storable_ip() {
		$ip = $this->get_client_ip();

		$options = $this->core->get_options();
		$mode    = isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';

		if ( 'full' === $mode ) {
			return $ip;
		}

		return self::truncate_ip( $ip );
	}

	/**
	 * Truncate an IP address to its network part.
	 *
	 * IPv4: last octet set to 0 (/24). IPv6: everything after the first 48 bits
	 * set to 0 (/48). Anything that is not a valid address gives an empty string.
	 *
	 * @param string $ip IP address.
	 * @return string Truncated address, or '' when $ip is not a valid address.
	 */
	public static function truncate_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets    = explode( '.', $ip );
			$octets[3] = '0';
			return implode( '.', $octets );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed || 16 !== strlen( $packed ) ) {
				return '';
			}
			$truncated = inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
			return false === $truncated ? '' : $truncated;
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
		// OPT-2881: never sanitize_text_field() the address. It drops every
		// percent-encoded octet, so an Arabic or Japanese path was stored as
		// "/--/---/" (only the dashes of the slug were left). esc_url_raw()
		// below keeps %xx and UTF-8 and removes what an address may not hold.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_uri = is_string( $request_uri ) ? wp_strip_all_tags( $request_uri ) : '';

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

		// OPT-2859: an AI pass is also counted in the daily counters, which keep
		// 12 months where this log keeps 30 days. One upsert, no IP, no user
		// agent, no referrer; a bot outside the AI map is refused there.
		if ( class_exists( 'Opti_Behavior_AI_Bot_Daily' ) ) {
			Opti_Behavior_AI_Bot_Daily::record( $bot_type, $visited_url );
		}
	}
}
