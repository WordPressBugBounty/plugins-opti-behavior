<?php
/**
 * URL privacy helpers: strip personal data from tracked URLs (OPT-574).
 *
 * Visitors land on URLs that carry personal data in the query string:
 * download links (`?email=…&access_code=…`), password reset links
 * (`wp-login.php?action=rp&key=…&login=…`), WooCommerce order keys,
 * newsletter links (`?email=…`). The trackers used to store those URLs as
 * they are, so the admin screens (Traffic, Heatmaps, Forms, Recordings…)
 * showed e-mail addresses and access codes in clear.
 *
 * `opti_behavior_strip_sensitive_query_args()` is THE function every
 * writer applies before an URL reaches the database or a data file
 * (Free owns it, Pro calls it behind a function_exists() guard). The
 * browser trackers apply the same list before sending (localized through
 * `opti_behavior_url_privacy_js_config()`).
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'opti_behavior_default_sensitive_query_params' ) ) {
	/**
	 * Default names of query parameters that carry personal data or secrets.
	 *
	 * @return string[] Lowercase parameter names.
	 */
	function opti_behavior_default_sensitive_query_params() {
		return array(
			'email', 'e-mail', 'e_mail', 'mail', 'user_email', 'username', 'user', 'login', 'log',
			'pwd', 'pass', 'password', 'access_code', 'code', 'token', 'access_token', 'refresh_token',
			'id_token', 'auth', 'authorization', 'key', 'api_key', 'apikey', 'license', 'license_key',
			'licence', 'secret', 'session', 'sessionid', 'sid', 'nonce', '_wpnonce', 'hash', 'signature',
			'sig', 'otp', 'phone', 'tel',
			// Address of a visitor's own site (download / licence links, OPT-602).
			'site_url', 'siteurl', 'home_url', 'website', 'domain',
		);
	}
}

if ( ! function_exists( 'opti_behavior_sensitive_query_params' ) ) {
	/**
	 * Names of the query parameters removed from every tracked URL.
	 *
	 * Filter `opti_behavior_sensitive_query_params` adds or removes names.
	 *
	 * @return string[] Unique lowercase names.
	 */
	function opti_behavior_sensitive_query_params() {
		$params = opti_behavior_default_sensitive_query_params();
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the query parameter names stripped from tracked URLs.
			 *
			 * @param string[] $params Lowercase parameter names (exact match, case-insensitive).
			 */
			$params = apply_filters( 'opti_behavior_sensitive_query_params', $params );
		}

		$clean = array();
		foreach ( (array) $params as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}
			$name = strtolower( trim( (string) $name ) );
			if ( '' !== $name ) {
				$clean[ $name ] = true;
			}
		}

		return array_keys( $clean );
	}
}

if ( ! function_exists( 'opti_behavior_value_looks_like_email' ) ) {
	/**
	 * Whether a (possibly URL-encoded) value contains an e-mail address.
	 *
	 * @param string $value Raw value.
	 * @return bool
	 */
	function opti_behavior_value_looks_like_email( $value ) {
		$value = (string) $value;
		if ( '' === $value || ( false === strpos( $value, '@' ) && false === stripos( $value, '%40' ) ) ) {
			return false;
		}
		$decoded = urldecode( $value );

		return 1 === preg_match( '/[^@\s]+@[^@\s]+\.[a-z]{2,}/i', $decoded );
	}
}

if ( ! function_exists( 'opti_behavior_strip_sensitive_pairs' ) ) {
	/**
	 * Remove the sensitive `name=value` pairs of a query string.
	 *
	 * Pairs are kept byte for byte (same encoding, same order); only the
	 * removed ones disappear.
	 *
	 * @param string              $query     Query string without the leading `?`.
	 * @param array<string,mixed> $sensitive Sensitive names as keys.
	 * @return string Remaining query string ('' when nothing is left).
	 */
	function opti_behavior_strip_sensitive_pairs( $query, $sensitive ) {
		$kept = array();
		foreach ( explode( '&', (string) $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$eq    = strpos( $pair, '=' );
			$name  = false === $eq ? $pair : substr( $pair, 0, $eq );
			$value = false === $eq ? '' : substr( $pair, $eq + 1 );

			// `user[email]=…` / `email[]=…` count as their base name.
			$base = strtolower( trim( urldecode( $name ) ) );
			$base = preg_replace( '/\[.*$/', '', $base );

			if ( isset( $sensitive[ $base ] ) || opti_behavior_value_looks_like_email( $value ) ) {
				continue;
			}
			$kept[] = $pair;
		}

		return implode( '&', $kept );
	}
}

if ( ! function_exists( 'opti_behavior_strip_sensitive_query_args' ) ) {
	/**
	 * Strip personal data / secrets from an URL before it is stored or sent.
	 *
	 * - every query parameter whose name is in
	 *   {@see opti_behavior_sensitive_query_params()} (case-insensitive);
	 * - every parameter whose value contains an e-mail address, whatever its
	 *   name (also `%40`-encoded);
	 * - the same rules inside the `#fragment` (`#access_token=…`, `#/route?email=…`),
	 *   and a fragment that is itself an e-mail address.
	 *
	 * Everything else (utm_*, `s`, pagination, A/B parameters…) is kept as it
	 * was. Works on absolute URLs, paths and protocol-relative URLs.
	 *
	 * @param mixed $url URL.
	 * @return mixed Clean URL; non-string values are returned untouched.
	 */
	function opti_behavior_strip_sensitive_query_args( $url ) {
		if ( ! is_string( $url ) || '' === $url || false === strpbrk( $url, '?#' ) ) {
			return $url;
		}

		$sensitive = array_flip( opti_behavior_sensitive_query_params() );

		$fragment = null;
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = (string) substr( $url, $hash_pos + 1 );
			$url      = (string) substr( $url, 0, $hash_pos );
		}

		$q_pos = strpos( $url, '?' );
		if ( false !== $q_pos ) {
			$query = opti_behavior_strip_sensitive_pairs( (string) substr( $url, $q_pos + 1 ), $sensitive );
			$url   = (string) substr( $url, 0, $q_pos );
			if ( '' !== $query ) {
				$url .= '?' . $query;
			}
		}

		if ( null !== $fragment ) {
			$f_pos = strpos( $fragment, '?' );
			if ( false !== $f_pos ) {
				// Router fragment: "#/route?email=…".
				$f_query  = opti_behavior_strip_sensitive_pairs( (string) substr( $fragment, $f_pos + 1 ), $sensitive );
				$fragment = (string) substr( $fragment, 0, $f_pos ) . ( '' !== $f_query ? '?' . $f_query : '' );
			} elseif ( false !== strpos( $fragment, '=' ) ) {
				$fragment = opti_behavior_strip_sensitive_pairs( $fragment, $sensitive );
			} elseif ( opti_behavior_value_looks_like_email( $fragment ) ) {
				$fragment = '';
			}
			if ( '' !== $fragment ) {
				$url .= '#' . $fragment;
			}
		}

		return $url;
	}
}

if ( ! function_exists( 'opti_behavior_strip_sensitive_urls_deep' ) ) {
	/**
	 * Apply {@see opti_behavior_strip_sensitive_query_args()} to every string
	 * of a decoded tracker payload that looks like an URL (absolute,
	 * protocol-relative, or a path with a query / fragment).
	 *
	 * @param mixed $value Payload (array / object / scalar).
	 * @param int   $depth Recursion guard.
	 * @return mixed Same structure, URLs cleaned.
	 */
	function opti_behavior_strip_sensitive_urls_deep( $value, $depth = 0 ) {
		if ( $depth > 12 ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = opti_behavior_strip_sensitive_urls_deep( $item, $depth + 1 );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = opti_behavior_strip_sensitive_urls_deep( $item, $depth + 1 );
			}
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value || false === strpbrk( $value, '?#' ) ) {
			return $value;
		}
		if ( preg_match( '#^\s*(?:https?:)?//#i', $value ) || ( '/' === $value[0] && false === strpbrk( $value, " \t\n" ) ) ) {
			return opti_behavior_strip_sensitive_query_args( $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'opti_behavior_page_identity_strip_params' ) ) {
	/**
	 * Query parameters that never make a different page (OPT-568, OPT-602):
	 * utm_*, _x_tr_* (Google Translate proxy), click ids, hl (language of the
	 * Google page the visitor came from) and the WooCommerce cart actions
	 * that land on the same page (removed_item, add-to-cart, added-to-cart).
	 * Exact names; a trailing `*` = prefix.
	 *
	 * Filter `opti_behavior_traffic_page_strip_params` for a site whose own
	 * pages use one of them.
	 *
	 * @return string[] Lowercase rules.
	 */
	function opti_behavior_page_identity_strip_params() {
		$params = array(
			'utm_*', '_x_tr_*', 'fbclid', 'gclid', 'msclkid', 'srsltid', 'gbraid', 'wbraid', 'dclid',
			'yclid', 'ttclid', 'twclid', 'li_fat_id', 'mc_eid', 'mc_cid', 'igshid', '_gl', 'hl',
			'removed_item', 'add-to-cart', 'added-to-cart',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$params = apply_filters( 'opti_behavior_traffic_page_strip_params', $params );
		}

		return array_values( array_map( 'strtolower', array_filter( array_map( 'strval', (array) $params ) ) ) );
	}
}

if ( ! function_exists( 'opti_behavior_page_identity_key' ) ) {
	/**
	 * THE page identity (OPT-602): one page = one key for Traffic Top Pages,
	 * the Page X-Ray list and the Page X-Ray dossier. Lowercase host (no
	 * scheme), no fragment, no trailing slash, personal-data parameters
	 * removed ({@see opti_behavior_strip_sensitive_query_args()}), then the
	 * query without {@see opti_behavior_page_identity_strip_params()}, sorted.
	 * Every other parameter (`s`, pagination, A/B, unknown) keeps the page apart.
	 *
	 * @param string $url Stored page URL.
	 * @return string Key ('' for an empty URL).
	 */
	function opti_behavior_page_identity_key( $url ) {
		static $strip = null;
		if ( null === $strip ) {
			$strip = opti_behavior_page_identity_strip_params();
		}

		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = (string) opti_behavior_strip_sensitive_query_args( $url );

		$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback outside WordPress (tests).
		if ( ! is_array( $parsed ) ) {
			return strtolower( $url );
		}

		$key  = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		$path = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';
		$key .= '' === $path ? '/' : $path;

		if ( ! empty( $parsed['query'] ) ) {
			$kept = array();
			foreach ( explode( '&', $parsed['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name = strtolower( rawurldecode( (string) strtok( $pair, '=' ) ) );
				$drop = false;
				foreach ( $strip as $rule ) {
					if ( '*' === substr( $rule, -1 ) ? 0 === strpos( $name, substr( $rule, 0, -1 ) ) : $name === $rule ) {
						$drop = true;
						break;
					}
				}
				if ( ! $drop ) {
					$kept[] = $pair;
				}
			}
			if ( ! empty( $kept ) ) {
				sort( $kept );
				$key .= '?' . implode( '&', $kept );
			}
		}

		return $key;
	}
}

if ( ! function_exists( 'opti_behavior_page_identity_members' ) ) {
	/**
	 * Every `optibehavior_pages` row that is the same page as each given id
	 * (same {@see opti_behavior_page_identity_key()}): http / https, trailing
	 * slash and every query variant of the same path are read, then kept only
	 * when their key matches. Batched: 2 queries per 25 ids.
	 *
	 * @param int[] $page_ids Page ids.
	 * @return array<int,int[]> page_id => member ids (always contains the id
	 *                          itself when the row exists), ascending.
	 */
	function opti_behavior_page_identity_members( array $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		$out      = array();
		if ( empty( $page_ids ) || ! isset( $wpdb ) ) {
			return $out;
		}
		$table = $wpdb->prefix . 'optibehavior_pages';

		foreach ( array_chunk( $page_ids, 25 ) as $chunk ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin table name from $wpdb->prefix; ids / patterns bound through prepare(); callers cache the result.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, url FROM {$table} WHERE id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					$chunk
				),
				ARRAY_A
			);

			$keys  = array();
			$likes = array();
			$args  = array();
			foreach ( (array) $rows as $row ) {
				$id  = (int) $row['id'];
				$key = opti_behavior_page_identity_key( (string) $row['url'] );
				if ( '' === $key ) {
					$out[ $id ] = array( $id );
					continue;
				}
				$keys[ $id ] = $key;
				$parts       = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $row['url'] ) : parse_url( (string) $row['url'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback outside WordPress.
				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					$out[ $id ] = array( $id );
					continue;
				}
				$base = $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . rtrim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );
				foreach ( array( 'http://', 'https://' ) as $scheme ) {
					$exact = $wpdb->esc_like( $scheme . $base );
					foreach ( array( '', '/' ) as $slash ) {
						$likes[] = 'url LIKE %s';
						$args[]  = $exact . $slash;
						$likes[] = 'url LIKE %s';
						$args[]  = $exact . $slash . '?%';
						$likes[] = 'url LIKE %s';
						$args[]  = $exact . $slash . '#%';
					}
				}
			}
			if ( empty( $likes ) ) {
				continue;
			}

			$candidates = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, url FROM {$table} WHERE " . implode( ' OR ', $likes ) . ' LIMIT 5000', array_values( $args ) ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$by_key = array();
			foreach ( (array) $candidates as $candidate ) {
				$by_key[ opti_behavior_page_identity_key( (string) $candidate['url'] ) ][] = (int) $candidate['id'];
			}
			foreach ( $keys as $id => $key ) {
				$members = isset( $by_key[ $key ] ) ? $by_key[ $key ] : array();
				$members[] = $id;
				$members   = array_values( array_unique( $members ) );
				sort( $members );
				$out[ $id ] = $members;
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'opti_behavior_url_privacy_js_config' ) ) {
	/**
	 * Settings the browser trackers need to apply the same rule before
	 * sending (wp_localize_script key `urlPrivacy`).
	 *
	 * @return array{params:string[]}
	 */
	function opti_behavior_url_privacy_js_config() {
		return array(
			'params' => opti_behavior_sensitive_query_params(),
		);
	}
}
