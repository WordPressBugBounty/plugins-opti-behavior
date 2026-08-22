<?php
/**
 * Portable advisory-lock helper.
 *
 * Wraps MySQL GET_LOCK/RELEASE_LOCK single-flight semantics in a way that is
 * safe across database engines. The plugin's setup / migration / aggregation
 * code originally called GET_LOCK directly and treated any non-'1' return as
 * "another request holds the lock, skip". That assumption is FALSE on engines
 * that do not implement GET_LOCK (e.g. the WordPress SQLite integration used by
 * Studio / Playground), where GET_LOCK yields NULL / an empty result set. The
 * old code then permanently skipped table creation and aggregation on those
 * installs.
 *
 * This helper fails OPEN: it only reports "busy" when the lock primitive
 * DEFINITIVELY says another connection holds the lock (a literal '0'). A NULL /
 * empty / unexpected result is treated as "lock unsupported" and the caller is
 * told to PROCEED without a cross-request mutex (SQLite is single-writer and
 * serializes writes anyway, so the concurrency storm the lock defends against
 * cannot occur there). On MySQL behaviour is unchanged.
 *
 * @package opti-behavior
 * @since 1.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portable advisory-lock helper.
 *
 * @since 1.8.3
 */
class Opti_Behavior_Heatmap_DB_Lock {

	/**
	 * Lock was acquired for this connection. The caller owns it and MUST
	 * release it (via release()).
	 *
	 * @var string
	 */
	const ACQUIRED = 'acquired';

	/**
	 * Lock is provably held by ANOTHER connection. The caller should skip.
	 *
	 * @var string
	 */
	const BUSY = 'busy';

	/**
	 * Lock primitive is not supported by this database engine (e.g. SQLite).
	 * The caller should PROCEED without a mutex — never skip.
	 *
	 * @var string
	 */
	const UNSUPPORTED = 'unsupported';

	/**
	 * Attempt to acquire a named advisory lock.
	 *
	 * @since 1.8.3
	 * @param string $lock_name Advisory lock name.
	 * @param int    $timeout   Seconds to wait for the lock (0 = non-blocking).
	 * @return string One of self::ACQUIRED, self::BUSY, self::UNSUPPORTED.
	 */
	public static function acquire( $lock_name, $timeout = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock, no caching possible.
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, max( 0, (int) $timeout ) )
		);

		if ( '1' === (string) $result ) {
			return self::ACQUIRED;
		}

		// A literal '0' is the ONLY value MySQL returns to mean "held by another
		// connection / timed out waiting". Only then do we tell the caller to skip.
		if ( '0' === (string) $result ) {
			return self::BUSY;
		}

		// NULL / empty result set / anything else => the engine does not support
		// GET_LOCK (SQLite integration returns zero rows) or an error occurred.
		// Fail OPEN so table creation / migrations still run.
		return self::UNSUPPORTED;
	}

	/**
	 * Release a named advisory lock.
	 *
	 * A no-op unless the lock was actually acquired by this connection, so it is
	 * safe to call unconditionally in a finally block with the state returned by
	 * acquire().
	 *
	 * @since 1.8.3
	 * @param string $lock_name Advisory lock name.
	 * @param string $state     State returned by acquire().
	 * @return void
	 */
	public static function release( $lock_name, $state = self::ACQUIRED ) {
		if ( self::ACQUIRED !== $state ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock release.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}
}
