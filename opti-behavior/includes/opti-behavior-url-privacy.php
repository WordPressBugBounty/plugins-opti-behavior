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

if ( ! function_exists( 'opti_behavior_param_name_is_secret' ) ) {
	/**
	 * Whether a query parameter NAME announces a secret, beyond the exact
	 * names of {@see opti_behavior_sensitive_query_params()} (OPT-1687:
	 * `?reset-token=…`, `rp_key`, `password-reset-token` were stored and shown
	 * in Top Pages). Same rule in the JS trackers (`obNameIsSecret()`, Free +
	 * Pro): keep both in step.
	 *
	 * The name is split into words (separators `- _ .` and camelCase), then:
	 * - the joined name CONTAINS token, password, passwd, pwd or secret;
	 * - a word IS key(s), reset, auth, oauth or session(s);
	 * - the joined name STARTS with reset, session, or auth (not author);
	 * - the joined name ENDS with key after a known prefix (apikey, rpkey,
	 *   resetkey, activationkey, accesskey…).
	 * Kept: utm_*, s, page, paged, ref, p, lang, author, keyword, monkey…
	 *
	 * @param string $name Raw (possibly URL-encoded) parameter name.
	 * @return bool
	 */
	function opti_behavior_param_name_is_secret( $name ) {
		$name = preg_replace( '/\[.*$/', '', trim( urldecode( (string) $name ) ) );
		$name = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', (string) $name );
		$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return false;
		}
		$joined = implode( '', $words );

		foreach ( array( 'token', 'password', 'passwd', 'pwd', 'secret' ) as $part ) {
			if ( false !== strpos( $joined, $part ) ) {
				return true;
			}
		}
		foreach ( $words as $word ) {
			if ( in_array( $word, array( 'key', 'keys', 'reset', 'auth', 'oauth', 'session', 'sessions' ), true ) ) {
				return true;
			}
		}
		if ( 0 === strpos( $joined, 'reset' ) || 0 === strpos( $joined, 'session' )
			|| ( 0 === strpos( $joined, 'auth' ) && 0 !== strpos( $joined, 'author' ) ) ) {
			return true;
		}
		foreach ( array( 'api', 'rp', 'reset', 'activation', 'access', 'auth', 'private', 'license', 'licence', 'login', 'user', 'pass', 'order', 'consumer', 'signing', 'encryption' ) as $prefix ) {
			if ( $prefix . 'key' === substr( $joined, - strlen( $prefix . 'key' ) ) ) {
				return true;
			}
		}

		return false;
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

			if ( isset( $sensitive[ $base ] ) || opti_behavior_param_name_is_secret( $name ) || opti_behavior_value_looks_like_email( $value ) ) {
				continue;
			}
			$kept[] = $pair;
		}

		return implode( '&', $kept );
	}
}

if ( ! function_exists( 'opti_behavior_order_endpoint_slugs' ) ) {
	/**
	 * WooCommerce endpoints whose next path segment is an order number
	 * (OPT-1405): `/checkout/order-received/186/`, `/my-account/view-order/186/`,
	 * `/checkout/order-pay/186/`. Default names + the names set in
	 * WooCommerce > Settings > Advanced (translated sites: `commande-recue`…).
	 * Without WooCommerce the list is empty: nothing is changed.
	 *
	 * Filter `opti_behavior_order_endpoint_slugs` adds or removes names.
	 *
	 * @return string[] Unique lowercase names, raw and URL-encoded.
	 */
	function opti_behavior_order_endpoint_slugs() {
		$slugs = array();
		if ( class_exists( 'WooCommerce', false ) ) {
			$slugs = array( 'order-received', 'view-order', 'order-pay' );
			if ( function_exists( 'get_option' ) ) {
				foreach ( array( 'woocommerce_checkout_order_received_endpoint', 'woocommerce_myaccount_view_order_endpoint', 'woocommerce_checkout_pay_endpoint' ) as $option ) {
					$value = get_option( $option, '' );
					if ( is_string( $value ) && '' !== $value ) {
						$slugs[] = $value;
					}
				}
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the endpoint names whose order number is removed from tracked URLs.
			 *
			 * @param string[] $slugs Endpoint names (empty without WooCommerce).
			 */
			$slugs = apply_filters( 'opti_behavior_order_endpoint_slugs', $slugs );
		}

		$clean = array();
		foreach ( (array) $slugs as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}
			$slug = strtolower( trim( (string) $slug, " \t\n\r\0\x0B/" ) );
			if ( '' === $slug || false !== strpbrk( $slug, "/?#&= \t" ) ) {
				continue;
			}
			$clean[ $slug ] = true;
			$clean[ strtolower( rawurlencode( $slug ) ) ] = true;
		}

		return array_keys( $clean );
	}
}

if ( ! function_exists( 'opti_behavior_strip_order_numbers' ) ) {
	/**
	 * Remove the WooCommerce order number that follows an order endpoint
	 * (OPT-1405, Juriste 13/10: never an order number, not even hashed):
	 * `/checkout/order-received/186/` → `/checkout/order-received/`, and with
	 * plain permalinks `?order-received=186` → `?order-received=`. The
	 * endpoint itself stays, so funnels "contains /checkout/order-received/"
	 * still match. Idempotent.
	 *
	 * @param mixed $url URL.
	 * @return mixed Clean URL; non-strings untouched.
	 */
	function opti_behavior_strip_order_numbers( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$alt = array();
		foreach ( opti_behavior_order_endpoint_slugs() as $slug ) {
			if ( false !== stripos( $url, $slug ) ) {
				$alt[] = preg_quote( $slug, '~' );
			}
		}
		if ( empty( $alt ) ) {
			return $url;
		}
		$alt = implode( '|', $alt );

		$cut  = strcspn( $url, '?#' );
		$path = preg_replace( '~/(' . $alt . ')/\d+(?=/|$)/?~i', '/$1/', (string) substr( $url, 0, $cut ) );
		$rest = preg_replace( '~([?&])(' . $alt . ')=\d+(?=&|#|$)~i', '$1$2=', (string) substr( $url, $cut ) );
		if ( null === $path || null === $rest ) {
			return $url;
		}

		return $path . $rest;
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
	 *   and a fragment that is itself an e-mail address;
	 * - the WooCommerce order number after an order endpoint
	 *   ({@see opti_behavior_strip_order_numbers()}, OPT-1405).
	 *
	 * Everything else (utm_*, `s`, pagination, A/B parameters…) is kept as it
	 * was. Works on absolute URLs, paths and protocol-relative URLs.
	 *
	 * @param mixed $url URL.
	 * @return mixed Clean URL; non-string values are returned untouched.
	 */
	function opti_behavior_strip_sensitive_query_args( $url ) {
		$url = opti_behavior_strip_order_numbers( $url );
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
	 * protocol-relative, or a path without white space).
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
		if ( ! is_string( $value ) || '' === $value ) {
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
	 * scheme, no leading `www.`: www.x.tld and x.tld are one site, OPT-1173),
	 * no fragment, no trailing slash, personal-data parameters
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

		$key = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		if ( 0 === strpos( $key, 'www.' ) && strlen( $key ) > 4 ) {
			$key = substr( $key, 4 );
		}
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
	 * (same {@see opti_behavior_page_identity_key()}): http / https, host with
	 * and without `www.` (OPT-1173), trailing slash and every query variant of
	 * the same path are read, then kept only when their key matches. Batched:
	 * 2 queries per 25 ids.
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
				$bare = strtolower( $parts['host'] );
				if ( 0 === strpos( $bare, 'www.' ) && strlen( $bare ) > 4 ) {
					$bare = substr( $bare, 4 );
				}
				$tail = ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . rtrim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );
				foreach ( array( 'http://', 'https://' ) as $scheme ) {
					foreach ( array( $bare, 'www.' . $bare ) as $host ) {
						$exact = $wpdb->esc_like( $scheme . $host . $tail );
						foreach ( array( '', '/' ) as $slash ) {
							// Keyed by pattern: the www and the bare row of one page ask for the same rows.
							$args[ $exact . $slash ]        = $exact . $slash;
							$args[ $exact . $slash . '?%' ] = $exact . $slash . '?%';
							$args[ $exact . $slash . '#%' ] = $exact . $slash . '#%';
						}
					}
				}
			}
			$likes = array_fill( 0, count( $args ), 'url LIKE %s' );
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
	 * @return array{params:string[],order_endpoints:string[]}
	 */
	function opti_behavior_url_privacy_js_config() {
		return array(
			'params'          => opti_behavior_sensitive_query_params(),
			'order_endpoints' => opti_behavior_order_endpoint_slugs(),
		);
	}
}

if ( ! function_exists( 'opti_behavior_visitor_label' ) ) {
	/**
	 * Anonymous label of a visitor for the admin screens and exports
	 * (OPT-1689): "Visitor #a1b2c3" = 6 characters of a keyed hash (site salt)
	 * of the visitor id. Stable from one screen to the next, the same rule for
	 * a logged-in account and a guest; never a login, name or e-mail, and the
	 * visitor id cannot be read back from it.
	 *
	 * @param string $visitor_id Stored visitor id.
	 * @return string Label ('' for an empty id).
	 */
	function opti_behavior_visitor_label( $visitor_id ) {
		$visitor_id = (string) $visitor_id;
		if ( '' === $visitor_id ) {
			return '';
		}
		$code = function_exists( 'wp_hash' )
			? substr( wp_hash( 'ob-visitor|' . $visitor_id ), 0, 6 )
			: substr( hash( 'sha256', 'ob-visitor|' . $visitor_id ), 0, 6 );
		$word = function_exists( '__' ) ? __( 'Visitor', 'opti-behavior' ) : 'Visitor';

		return $word . ' #' . $code;
	}
}

if ( ! function_exists( 'opti_behavior_privacy_mode_is_full' ) ) {
	/**
	 * Whether the site runs in Full Tracking mode (OPT-1691). Any other or
	 * unknown value counts as Anonymous Mode.
	 *
	 * @return bool
	 */
	function opti_behavior_privacy_mode_is_full() {
		$options = function_exists( 'get_option' ) ? get_option( 'opti_behavior_heatmap_option', array() ) : array();
		$options = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $options ) : $options;

		return is_array( $options ) && isset( $options['privacy_mode'] ) && 'full' === $options['privacy_mode'];
	}
}

if ( ! function_exists( 'opti_behavior_display_ip' ) ) {
	/**
	 * IP address as an admin screen or an export may show it (OPT-1691):
	 * never a whole address. Full Tracking mode = the stored address cut to
	 * its network (IPv4 last octet 0, IPv6 /48; it was stored only with the
	 * visitor's consent); every other case, an empty value or anything that
	 * is not an address = "Anonymous".
	 *
	 * @param string    $ip   Stored value.
	 * @param bool|null $full Full Tracking mode (null = read the setting).
	 * @return string Truncated address or 'Anonymous'.
	 */
	function opti_behavior_display_ip( $ip, $full = null ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';
		if ( null === $full ) {
			$full = opti_behavior_privacy_mode_is_full();
		}
		if ( ! $full || '' === $ip || 'Anonymous' === $ip ) {
			return 'Anonymous';
		}

		$cut = '';
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets    = explode( '.', $ip );
			$octets[3] = '0';
			$cut       = implode( '.', $octets );
		} elseif ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				$cut = (string) inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
			}
		}

		return '' === $cut ? 'Anonymous' : $cut;
	}
}
