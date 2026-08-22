<?php
/**
 * Admin contact email resolver.
 *
 * Chooses one reliable administrator contact email for Opti-Behavior API calls.
 *
 * @package opti-behavior
 * @since 1.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Heatmap_Admin_Email_Resolver
 *
 * Collects current user, site admin, super-admin, and administrator user email
 * candidates, then returns one deterministic best contact email.
 *
 * @since 1.4.1
 */
class Opti_Behavior_Heatmap_Admin_Email_Resolver {

	/**
	 * Resolve the best administrator contact email.
	 *
	 * Supported arguments:
	 * - prefer_current_user: Prioritize the current managing admin when available.
	 * - include_current_user: Include the current user when they can manage options.
	 * - include_site_admin: Include the WordPress admin_email option.
	 * - include_super_admins: Include multisite super admins.
	 * - include_administrator_users: Include administrator role users.
	 * - user_limit: Maximum administrator users to inspect.
	 *
	 * @since 1.4.1
	 *
	 * @param array $args Resolver options.
	 * @return array Structured result.
	 */
	public static function resolve( $args = array() ) {
		$args = array_merge(
			array(
				'prefer_current_user'        => true,
				'include_current_user'       => true,
				'include_site_admin'         => true,
				'include_super_admins'       => true,
				'include_administrator_users' => true,
				'user_limit'                 => 20,
			),
			is_array( $args ) ? $args : array()
		);

		$candidates            = array();
		$site_admin_status     = self::get_site_admin_status();
		$site_admin_email      = isset( $site_admin_status['email'] ) ? $site_admin_status['email'] : '';
		$site_admin_is_valid   = ( isset( $site_admin_status['status'] ) && 'valid' === $site_admin_status['status'] );
		$site_admin_is_present = ( isset( $site_admin_status['raw'] ) && '' !== trim( (string) $site_admin_status['raw'] ) );

		if ( ! empty( $args['include_current_user'] ) ) {
			self::collect_current_user_candidate( $candidates, $args );
		}

		if ( ! empty( $args['include_site_admin'] ) && $site_admin_is_valid ) {
			self::add_candidate(
				$candidates,
				$site_admin_email,
				'site_admin',
				array(
					'user_id' => 0,
				),
				$args
			);
		}

		if ( ! empty( $args['include_super_admins'] ) ) {
			self::collect_super_admin_candidates( $candidates, $args );
		}

		if ( ! empty( $args['include_administrator_users'] ) ) {
			self::collect_administrator_user_candidates( $candidates, $args );
		}

		$fallback_reason = 'none';
		if ( ! $site_admin_is_valid ) {
			if ( isset( $site_admin_status['status'] ) && 'placeholder' === $site_admin_status['status'] ) {
				$fallback_reason = 'site_admin_placeholder';
			} elseif ( $site_admin_is_present ) {
				$fallback_reason = 'site_admin_invalid';
			} else {
				$fallback_reason = 'site_admin_missing';
			}
		}

		return self::build_result( $candidates, $fallback_reason );
	}

	/**
	 * Return only the resolved email value.
	 *
	 * @since 1.4.1
	 *
	 * @param array $args Resolver options.
	 * @return string|null Best email, or null when no candidate is available.
	 */
	public static function get_email( $args = array() ) {
		$result = self::resolve( $args );

		return ! empty( $result['email'] ) ? $result['email'] : null;
	}

	/**
	 * Collect the current logged-in admin candidate.
	 *
	 * @since 1.4.1
	 *
	 * @param array $candidates Candidate map, keyed by normalized email.
	 * @param array $args Resolver options.
	 * @return void
	 */
	private static function collect_current_user_candidate( &$candidates, $args ) {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( empty( $user ) || empty( $user->ID ) || empty( $user->user_email ) ) {
			return;
		}

		self::add_candidate(
			$candidates,
			$user->user_email,
			'current_user',
			array(
				'user_id' => (int) $user->ID,
			),
			$args
		);
	}

	/**
	 * Collect multisite super-admin candidates.
	 *
	 * @since 1.4.1
	 *
	 * @param array $candidates Candidate map, keyed by normalized email.
	 * @param array $args Resolver options.
	 * @return void
	 */
	private static function collect_super_admin_candidates( &$candidates, $args ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_super_admins' ) || ! function_exists( 'get_user_by' ) ) {
			return;
		}

		$super_admins = get_super_admins();
		if ( empty( $super_admins ) || ! is_array( $super_admins ) ) {
			return;
		}

		foreach ( $super_admins as $login ) {
			$user = get_user_by( 'login', $login );
			if ( empty( $user ) || empty( $user->ID ) || empty( $user->user_email ) ) {
				continue;
			}

			self::add_candidate(
				$candidates,
				$user->user_email,
				'super_admin',
				array(
					'user_id' => (int) $user->ID,
				),
				$args
			);
		}
	}

	/**
	 * Collect administrator-role user candidates.
	 *
	 * @since 1.4.1
	 *
	 * @param array $candidates Candidate map, keyed by normalized email.
	 * @param array $args Resolver options.
	 * @return void
	 */
	private static function collect_administrator_user_candidates( &$candidates, $args ) {
		if ( ! function_exists( 'get_users' ) ) {
			return;
		}

		$user_limit = isset( $args['user_limit'] ) ? (int) $args['user_limit'] : 20;
		if ( $user_limit < 1 ) {
			$user_limit = 20;
		}

		$users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => $user_limit,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'user_email' ),
			)
		);

		if ( empty( $users ) || ! is_array( $users ) ) {
			return;
		}

		foreach ( $users as $user ) {
			if ( empty( $user ) || empty( $user->user_email ) ) {
				continue;
			}

			self::add_candidate(
				$candidates,
				$user->user_email,
				'administrator_user',
				array(
					'user_id' => empty( $user->ID ) ? 0 : (int) $user->ID,
				),
				$args
			);
		}
	}

	/**
	 * Add a candidate when it is valid and not placeholder-like.
	 *
	 * @since 1.4.1
	 *
	 * @param array  $candidates Candidate map, keyed by normalized email.
	 * @param string $email Raw email value.
	 * @param string $source Candidate source.
	 * @param array  $metadata Candidate metadata.
	 * @param array  $args Resolver options.
	 * @return void
	 */
	private static function add_candidate( &$candidates, $email, $source, $metadata, $args ) {
		$email = self::normalize_email( $email );
		if ( empty( $email ) || self::is_placeholder_email( $email ) ) {
			return;
		}

		$user_id = isset( $metadata['user_id'] ) ? (int) $metadata['user_id'] : 0;
		$score   = self::score_candidate( $email, $source, $user_id, $args );
		$key     = strtolower( $email );

		$candidate = array(
			'email'   => $email,
			'source'  => $source,
			'score'   => $score,
			'user_id' => $user_id,
		);

		if ( ! isset( $candidates[ $key ] ) || self::candidate_is_better( $candidate, $candidates[ $key ] ) ) {
			$candidates[ $key ] = $candidate;
		}
	}

	/**
	 * Build the public result array.
	 *
	 * @since 1.4.1
	 *
	 * @param array  $candidates Candidate map, keyed by normalized email.
	 * @param string $fallback_reason Reason the resolver moved beyond site admin.
	 * @return array Structured result.
	 */
	private static function build_result( $candidates, $fallback_reason ) {
		$candidate_count = count( $candidates );

		if ( empty( $candidates ) ) {
			return array(
				'email'           => null,
				'source'          => 'none',
				'candidate_count' => 0,
				'fallback_reason' => $fallback_reason,
			);
		}

		$candidates = array_values( $candidates );
		usort( $candidates, array( __CLASS__, 'sort_candidates' ) );
		$best = $candidates[0];

		return array(
			'email'           => $best['email'],
			'source'          => $best['source'],
			'candidate_count' => $candidate_count,
			'fallback_reason' => $fallback_reason,
		);
	}

	/**
	 * Get normalized status for the site admin email option.
	 *
	 * @since 1.4.1
	 *
	 * @return array Site admin status details.
	 */
	private static function get_site_admin_status() {
		$raw = function_exists( 'get_option' ) ? get_option( 'admin_email' ) : '';

		if ( '' === trim( (string) $raw ) ) {
			return array(
				'raw'    => $raw,
				'email'  => '',
				'status' => 'missing',
			);
		}

		$email = self::normalize_email( $raw );
		if ( empty( $email ) ) {
			return array(
				'raw'    => $raw,
				'email'  => '',
				'status' => 'invalid',
			);
		}

		if ( self::is_placeholder_email( $email ) ) {
			return array(
				'raw'    => $raw,
				'email'  => $email,
				'status' => 'placeholder',
			);
		}

		return array(
			'raw'    => $raw,
			'email'  => $email,
			'status' => 'valid',
		);
	}

	/**
	 * Normalize and validate an email address.
	 *
	 * @since 1.4.1
	 *
	 * @param string $email Raw email address.
	 * @return string Normalized email, or empty string when invalid.
	 */
	private static function normalize_email( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email ) {
			return '';
		}

		$email = function_exists( 'sanitize_email' ) ? sanitize_email( $email ) : filter_var( $email, FILTER_SANITIZE_EMAIL );
		if ( '' === $email ) {
			return '';
		}

		if ( function_exists( 'is_email' ) ) {
			return is_email( $email ) ? $email : '';
		}

		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}

	/**
	 * Determine whether an email is placeholder-like or non-contactable.
	 *
	 * @since 1.4.1
	 *
	 * @param string $email Normalized email address.
	 * @return bool True when placeholder-like.
	 */
	private static function is_placeholder_email( $email ) {
		$parts = explode( '@', strtolower( $email ) );
		if ( 2 !== count( $parts ) ) {
			return true;
		}

		$local  = $parts[0];
		$domain = self::normalize_domain( $parts[1] );

		$placeholder_domains = array(
			'example.com',
			'example.org',
			'example.net',
			'localhost',
			'localhost.localdomain',
			'invalid',
		);

		if ( in_array( $domain, $placeholder_domains, true ) ) {
			return true;
		}

		foreach ( array( '.example.com', '.example.org', '.example.net' ) as $placeholder_suffix ) {
			if ( self::string_ends_with( $domain, $placeholder_suffix ) ) {
				return true;
			}
		}

		$placeholder_locals = array(
			'wordpress',
			'noreply',
			'no-reply',
			'no.reply',
			'donotreply',
			'do-not-reply',
			'do.not.reply',
		);

		return in_array( $local, $placeholder_locals, true );
	}

	/**
	 * Score a candidate.
	 *
	 * @since 1.4.1
	 *
	 * @param string $email Email address.
	 * @param string $source Candidate source.
	 * @param int    $user_id WordPress user ID, if any.
	 * @param array  $args Resolver options.
	 * @return int Candidate score.
	 */
	private static function score_candidate( $email, $source, $user_id, $args ) {
		$prefer_current_user = ! empty( $args['prefer_current_user'] );
		$score               = self::get_source_priority( $source, $prefer_current_user );
		$email_domain        = self::get_email_domain( $email );
		$site_host           = self::get_site_host();

		if ( $email_domain && $site_host && self::domains_match( $email_domain, $site_host ) ) {
			$score += 25;
		}

		if ( 'current_user' === $source ) {
			$score += 10;
		}

		if ( $user_id > 0 ) {
			$score += max( 0, 10 - min( 10, $user_id ) );
		}

		return $score;
	}

	/**
	 * Source priority table.
	 *
	 * @since 1.4.1
	 *
	 * @param string $source Candidate source.
	 * @param bool   $prefer_current_user Whether to prioritize the current user.
	 * @return int Source score.
	 */
	private static function get_source_priority( $source, $prefer_current_user ) {
		if ( $prefer_current_user ) {
			$priorities = array(
				'current_user'       => 1000,
				'site_admin'         => 900,
				'super_admin'        => 800,
				'administrator_user' => 700,
			);
		} else {
			$priorities = array(
				'site_admin'         => 1000,
				'current_user'       => 900,
				'super_admin'        => 800,
				'administrator_user' => 700,
			);
		}

		return isset( $priorities[ $source ] ) ? $priorities[ $source ] : 0;
	}

	/**
	 * Compare two candidates for duplicate resolution.
	 *
	 * @since 1.4.1
	 *
	 * @param array $candidate New candidate.
	 * @param array $existing Existing candidate.
	 * @return bool True when the new candidate is better.
	 */
	private static function candidate_is_better( $candidate, $existing ) {
		if ( $candidate['score'] !== $existing['score'] ) {
			return $candidate['score'] > $existing['score'];
		}

		return self::normalize_user_id_for_sort( $candidate['user_id'] ) < self::normalize_user_id_for_sort( $existing['user_id'] );
	}

	/**
	 * Sort candidates by score, source, user ID, then email for deterministic output.
	 *
	 * @since 1.4.1
	 *
	 * @param array $left Left candidate.
	 * @param array $right Right candidate.
	 * @return int Sort comparison.
	 */
	private static function sort_candidates( $left, $right ) {
		if ( $left['score'] !== $right['score'] ) {
			return ( $left['score'] > $right['score'] ) ? -1 : 1;
		}

		$left_user_id  = self::normalize_user_id_for_sort( $left['user_id'] );
		$right_user_id = self::normalize_user_id_for_sort( $right['user_id'] );
		if ( $left_user_id !== $right_user_id ) {
			return ( $left_user_id < $right_user_id ) ? -1 : 1;
		}

		return strcmp( $left['email'], $right['email'] );
	}

	/**
	 * Normalize user ID for sorting.
	 *
	 * @since 1.4.1
	 *
	 * @param int $user_id User ID.
	 * @return int Sortable user ID.
	 */
	private static function normalize_user_id_for_sort( $user_id ) {
		$user_id = (int) $user_id;

		return $user_id > 0 ? $user_id : PHP_INT_MAX;
	}

	/**
	 * Get an email domain.
	 *
	 * @since 1.4.1
	 *
	 * @param string $email Email address.
	 * @return string Domain, or empty string.
	 */
	private static function get_email_domain( $email ) {
		$parts = explode( '@', strtolower( $email ) );

		return 2 === count( $parts ) ? self::normalize_domain( $parts[1] ) : '';
	}

	/**
	 * Get the current site host.
	 *
	 * @since 1.4.1
	 *
	 * @return string Site host, or empty string.
	 */
	private static function get_site_host() {
		$url = '';

		if ( function_exists( 'home_url' ) ) {
			$url = home_url();
		} elseif ( function_exists( 'site_url' ) ) {
			$url = site_url();
		}

		if ( empty( $url ) ) {
			return '';
		}

		if ( ! function_exists( 'wp_parse_url' ) ) {
			return '';
		}

		return self::normalize_domain( wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Normalize domain values.
	 *
	 * @since 1.4.1
	 *
	 * @param string $domain Domain value.
	 * @return string Normalized domain.
	 */
	private static function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );
		$domain = preg_replace( '/^www\./', '', $domain );

		return trim( $domain, ". \t\n\r\0\x0B" );
	}

	/**
	 * Determine whether two domains represent the same site.
	 *
	 * @since 1.4.1
	 *
	 * @param string $email_domain Email domain.
	 * @param string $site_host Site host.
	 * @return bool True when domains match.
	 */
	private static function domains_match( $email_domain, $site_host ) {
		$email_domain = self::normalize_domain( $email_domain );
		$site_host    = self::normalize_domain( $site_host );

		if ( '' === $email_domain || '' === $site_host ) {
			return false;
		}

		return $email_domain === $site_host || self::string_ends_with( $email_domain, '.' . $site_host );
	}

	/**
	 * PHP 7-compatible string suffix check.
	 *
	 * @since 1.4.1
	 *
	 * @param string $haystack Full string.
	 * @param string $needle Suffix.
	 * @return bool True when haystack ends with needle.
	 */
	private static function string_ends_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
