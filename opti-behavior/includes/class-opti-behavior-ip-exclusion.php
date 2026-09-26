<?php
/**
 * IP exclusion for tracking scripts.
 *
 * Lets site owners list IP addresses (exact IPv4/IPv6, CIDR ranges, or IPv4
 * wildcards) whose visits must NOT be tracked. When the current visitor's IP
 * matches any rule, every frontend tracker (heatmap/behavior, A/B, funnels,
 * and Pro trackers such as session recording, form analytics and error
 * tracking) skips enqueueing its script — the visit is simply ignored,
 * exactly like Google Analytics IP filters.
 *
 * Rules live in the shared `opti_behavior_traffic_settings` option under the
 * `excluded_ips` key (array of strings, managed from Settings → Traffic &
 * Behavior).
 *
 * @package Opti_Behavior
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static IP-exclusion helper.
 *
 * @since 1.6.0
 */
class Opti_Behavior_IP_Exclusion {

	/**
	 * Per-request cache of the exclusion verdict, keyed by IP.
	 *
	 * @var array<string,bool>
	 */
	private static $cache = array();

	/**
	 * Get the configured exclusion rules.
	 *
	 * @since 1.6.0
	 * @return string[] List of validated rules (may be empty).
	 */
	public static function get_excluded_ips() {
		$settings = get_option( 'opti_behavior_traffic_settings', array() );
		$rules    = isset( $settings['excluded_ips'] ) && is_array( $settings['excluded_ips'] )
			? $settings['excluded_ips']
			: array();

		/**
		 * Filter the configured IP-exclusion rules.
		 *
		 * @since 1.6.0
		 * @param string[] $rules Exclusion rules (exact IP, CIDR, or IPv4 wildcard).
		 */
		return apply_filters( 'opti_behavior_excluded_ips', $rules );
	}

	/**
	 * Resolve the client IP for the current request.
	 *
	 * Deliberately uses REMOTE_ADDR only — proxy headers (X-Forwarded-For…)
	 * are spoofable and would let visitors opt themselves out of tracking.
	 * Sites behind a trusted reverse proxy can adjust via the filter.
	 *
	 * @since 1.6.0
	 * @return string Client IP (empty string when unavailable, e.g. CLI).
	 */
	public static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the client IP used for exclusion matching.
		 *
		 * @since 1.6.0
		 * @param string $ip Client IP from REMOTE_ADDR.
		 */
		return apply_filters( 'opti_behavior_ip_exclusion_client_ip', $ip );
	}

	/**
	 * Cloudflare edge ranges (https://www.cloudflare.com/ips/). CF-Connecting-IP
	 * is trusted only when the TCP peer (REMOTE_ADDR) is one of them.
	 *
	 * @var string[]
	 */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
		'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/**
	 * Visitor IP for geolocation, anonymous identity hashing and rate limits.
	 *
	 * Forwarding headers are only believed when they come from a proxy we can
	 * trust, never from the open internet (WordPress.org security review of
	 * 1.9.2: X-Forwarded-For / Client-IP / CF-Connecting-IP were accepted from
	 * any caller, so one client could claim a new IP per request):
	 * - REMOTE_ADDR is a Cloudflare edge => CF-Connecting-IP;
	 * - REMOTE_ADDR is private / reserved (reverse proxy on the host or LAN) =>
	 *   the right-most public X-Forwarded-For hop (the one our proxy appended),
	 *   else X-Real-IP;
	 * - otherwise REMOTE_ADDR itself.
	 *
	 * @return string Visitor IP ('' when unavailable, e.g. CLI).
	 */
	public static function get_visitor_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$remote = '';
		}
		$ip = $remote;

		if ( '' !== $remote ) {
			if ( self::is_cloudflare_ip( $remote ) ) {
				$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
				if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
					$ip = $cf;
				}
			} elseif ( ! self::is_public_ip( $remote ) ) {
				$hop = self::forwarded_client_ip();
				if ( '' !== $hop ) {
					$ip = $hop;
				}
			}
		}

		/**
		 * Filter the visitor IP (custom trusted-proxy setups).
		 *
		 * @param string $ip     Resolved visitor IP.
		 * @param string $remote Raw REMOTE_ADDR.
		 */
		return (string) apply_filters( 'opti_behavior_visitor_ip', $ip, $remote );
	}

	/**
	 * Whether an IP is a public (routable, non-reserved) address.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether an IP belongs to a Cloudflare edge range.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_cloudflare_ip( $ip ) {
		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			if ( self::cidr_match( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Client IP reported by a trusted local reverse proxy.
	 *
	 * @return string Right-most public X-Forwarded-For hop, else a valid
	 *                X-Real-IP, else ''.
	 */
	private static function forwarded_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$hops = array_reverse( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
			foreach ( $hops as $hop ) {
				$hop = trim( $hop );
				if ( self::is_public_ip( $hop ) ) {
					return $hop;
				}
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$real = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) );
			if ( false !== filter_var( $real, FILTER_VALIDATE_IP ) ) {
				return $real;
			}
		}
		return '';
	}

	/**
	 * Whether a rule string is a valid exclusion rule.
	 *
	 * Accepted formats:
	 * - Exact IPv4 or IPv6 (`192.168.1.10`, `2001:db8::1`, `::1`)
	 * - CIDR range, v4 or v6 (`192.168.1.0/24`, `2001:db8::/32`)
	 * - IPv4 wildcard on trailing octets (`192.168.1.*`, `10.0.*`)
	 *
	 * @since 1.6.0
	 * @param string $rule Candidate rule.
	 * @return bool
	 */
	public static function is_valid_rule( $rule ) {
		$rule = trim( (string) $rule );
		if ( '' === $rule ) {
			return false;
		}

		// Exact IP.
		if ( false !== filter_var( $rule, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// CIDR.
		if ( false !== strpos( $rule, '/' ) ) {
			list( $subnet, $bits ) = array_pad( explode( '/', $rule, 2 ), 2, '' );
			if ( '' === $bits || ! ctype_digit( $bits ) ) {
				return false;
			}
			$bits = (int) $bits;
			if ( false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $bits >= 0 && $bits <= 32;
			}
			if ( false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return $bits >= 0 && $bits <= 128;
			}
			return false;
		}

		// IPv4 wildcard: 1-3 leading numeric octets, remaining octets as `*`.
		if ( false !== strpos( $rule, '*' ) ) {
			return (bool) preg_match(
				'/^(\d{1,3})(\.\d{1,3}){0,2}(\.\*)+$/',
				$rule
			) && self::wildcard_octets_valid( $rule );
		}

		return false;
	}

	/**
	 * Validate numeric octets of a wildcard rule are within 0-255.
	 *
	 * @param string $rule Wildcard rule already shape-matched.
	 * @return bool
	 */
	private static function wildcard_octets_valid( $rule ) {
		$parts = explode( '.', $rule );
		if ( count( $parts ) > 4 ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( '*' === $part ) {
				continue;
			}
			if ( ! ctype_digit( $part ) || (int) $part > 255 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an IP matches a single rule.
	 *
	 * @since 1.6.0
	 * @param string $ip   Client IP.
	 * @param string $rule Exclusion rule (exact / CIDR / wildcard).
	 * @return bool
	 */
	public static function matches( $ip, $rule ) {
		$ip   = trim( (string) $ip );
		$rule = trim( (string) $rule );
		if ( '' === $ip || '' === $rule ) {
			return false;
		}

		// Exact match (normalize both sides for IPv6 shorthand, e.g. ::1 vs 0:0:0:0:0:0:0:1).
		if ( false !== filter_var( $rule, FILTER_VALIDATE_IP ) ) {
			if ( 0 === strcasecmp( $ip, $rule ) ) {
				return true;
			}
			$ip_bin   = @inet_pton( $ip );
			$rule_bin = @inet_pton( $rule );
			return false !== $ip_bin && false !== $rule_bin && $ip_bin === $rule_bin;
		}

		// CIDR.
		if ( false !== strpos( $rule, '/' ) ) {
			return self::cidr_match( $ip, $rule );
		}

		// IPv4 wildcard.
		if ( false !== strpos( $rule, '*' ) ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return false;
			}
			// Pad short rules to 4 octets: `10.0.*` means `10.0.*.*`.
			$parts = explode( '.', $rule );
			while ( count( $parts ) < 4 ) {
				$parts[] = '*';
			}
			$rule    = implode( '.', $parts );
			$pattern = '/^' . str_replace( array( '.', '*' ), array( '\.', '\d{1,3}' ), $rule ) . '$/';
			return (bool) preg_match( $pattern, $ip );
		}

		return false;
	}

	/**
	 * CIDR containment check (IPv4 + IPv6 via binary comparison).
	 *
	 * @param string $ip   Client IP.
	 * @param string $cidr Rule in `subnet/bits` form.
	 * @return bool
	 */
	private static function cidr_match( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		if ( '' === $bits || ! ctype_digit( (string) $bits ) ) {
			return false;
		}
		$bits = (int) $bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false; // Different families (v4 IP vs v6 rule or vice versa) never match.
		}

		$max_bits = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}
		if ( 0 === $bits ) {
			return true;
		}

		$full_bytes = intdiv( $bits, 8 );
		$rem_bits   = $bits % 8;

		if ( $full_bytes > 0 && 0 !== substr_compare( $ip_bin, substr( $subnet_bin, 0, $full_bytes ), 0, $full_bytes ) ) {
			return false;
		}
		if ( $rem_bits > 0 ) {
			$mask = 0xFF << ( 8 - $rem_bits ) & 0xFF;
			if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the given (or current) visitor IP is excluded from tracking.
	 *
	 * @since 1.6.0
	 * @param string|null $ip IP to test; null = current request's client IP.
	 * @return bool True when tracking scripts must NOT run for this IP.
	 */
	public static function is_excluded( $ip = null ) {
		if ( null === $ip ) {
			$ip = self::get_client_ip();
		}
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return false;
		}

		if ( isset( self::$cache[ $ip ] ) ) {
			return self::$cache[ $ip ];
		}

		$excluded = false;
		foreach ( self::get_excluded_ips() as $rule ) {
			if ( self::matches( $ip, $rule ) ) {
				$excluded = true;
				break;
			}
		}

		/**
		 * Filter the final exclusion verdict for an IP.
		 *
		 * @since 1.6.0
		 * @param bool   $excluded Whether tracking is blocked for this IP.
		 * @param string $ip       The IP tested.
		 */
		$excluded = (bool) apply_filters( 'opti_behavior_ip_is_excluded', $excluded, $ip );

		self::$cache[ $ip ] = $excluded;
		return $excluded;
	}

	/**
	 * Reset the per-request cache (used by tests / after settings save).
	 *
	 * @since 1.6.0
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Parse a raw textarea payload into validated rules.
	 *
	 * @since 1.6.0
	 * @param string $raw One rule per line.
	 * @return array{valid: string[], invalid: string[]} Deduplicated valid rules + rejected lines.
	 */
	public static function parse_rules( $raw ) {
		$valid   = array();
		$invalid = array();

		// Split on newlines, commas, or any whitespace — IP rules never contain
		// spaces, and sanitize_text_field-style passes may collapse newlines.
		foreach ( preg_split( '/[\s,]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( self::is_valid_rule( $line ) ) {
				if ( ! in_array( $line, $valid, true ) ) {
					$valid[] = $line;
				}
			} else {
				$invalid[] = $line;
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}
}
