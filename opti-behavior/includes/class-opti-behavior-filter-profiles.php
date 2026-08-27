<?php
/**
 * Filter Profiles — site-wide saved advanced-filter sets.
 *
 * FREE-owned storage + CRUD AJAX for the "Filter Profiles" feature. A profile is
 * a named set of the 16 allow-listed advanced-filter values (all 4 filter groups;
 * date range/preset is NOT part of a profile). Storage is a single site-wide
 * `wp_options` row shared by every filter surface across the FREE + PRO suite.
 *
 * Ownership: the FREE plugin exclusively owns the option lifecycle (activation
 * seed, idempotent admin_init re-seed, uninstall delete). PRO never seeds/deletes
 * the option — it only CRUDs through these AJAX endpoints.
 *
 * @package opti-behavior
 * @since 1.8.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The sanitizer allow-list lives in the shared advanced-filters trait; reuse it
// verbatim rather than re-implementing the 16-field contract.
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-advanced-filters.php';

/**
 * Site-wide saved advanced-filter profiles (storage + AJAX CRUD).
 *
 * @since 1.8.3.0
 */
class Opti_Behavior_Filter_Profiles {

	use Opti_Behavior_Advanced_Filters_Trait;

	/**
	 * Option name (autoload `no`). FREE-owned.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'opti_behavior_filter_profiles';

	/**
	 * Stored schema version (reserved for future migrations).
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Maximum number of profiles a site may keep.
	 *
	 * @var int
	 */
	const MAX_PROFILES = 50;

	/**
	 * Maximum profile-name length (characters).
	 *
	 * @var int
	 */
	const NAME_MAX_LENGTH = 60;

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'opti_behavior_filter_profiles';

	/**
	 * Singleton instance.
	 *
	 * @var Opti_Behavior_Filter_Profiles|null
	 */
	private static $instance = null;

	/**
	 * Boot the singleton and register the AJAX handlers.
	 *
	 * @since 1.8.3.0
	 * @return Opti_Behavior_Filter_Profiles
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the four CRUD AJAX handlers.
	 *
	 * @since 1.8.3.0
	 */
	private function __construct() {
		add_action( 'wp_ajax_opti_behavior_filter_profiles_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_opti_behavior_filter_profiles_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_opti_behavior_filter_profiles_update', array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_opti_behavior_filter_profiles_delete', array( $this, 'ajax_delete' ) );
	}

	/**
	 * Seed the option if absent (no-op when the row already exists).
	 *
	 * Used by both the FREE activation hook and the idempotent admin_init
	 * re-seed. `add_option()` is presence-keyed so re-running never clobbers
	 * saved profiles.
	 *
	 * @since 1.8.3.0
	 */
	public static function seed() {
		add_option(
			self::OPTION_NAME,
			array(
				'version'  => self::SCHEMA_VERSION,
				'profiles' => array(),
			),
			'',
			'no'
		);
	}

	// ---------------------------------------------------------------------
	// AJAX handlers (thin wrappers around the testable CRUD core below).
	// ---------------------------------------------------------------------

	/**
	 * AJAX: list all profiles (sorted by name).
	 *
	 * @since 1.8.3.0
	 */
	public function ajax_list() {
		$this->verify_request();
		wp_send_json_success( array( 'profiles' => $this->list_profiles() ) );
	}

	/**
	 * AJAX: create a new profile.
	 *
	 * @since 1.8.3.0
	 */
	public function ajax_save() {
		$this->verify_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verify_request() above runs check_ajax_referer(); raw values are unslashed + sanitized downstream (sanitize_name()/sanitize_filters_payload()), unslashing here would double-strip backslashes.
		$name_raw    = isset( $_POST['name'] ) ? $_POST['name'] : '';
		$filters_raw = isset( $_POST['filters'] ) ? $_POST['filters'] : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = $this->create_profile( $name_raw, $filters_raw );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'profile'  => $this->public_profile( $result ),
				'profiles' => $this->list_profiles(),
			)
		);
	}

	/**
	 * AJAX: rename and/or overwrite an existing profile.
	 *
	 * @since 1.8.3.0
	 */
	public function ajax_update() {
		$this->verify_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verify_request() above runs check_ajax_referer(); raw values are unslashed + sanitized downstream (sanitize_name()/sanitize_filters_payload()), unslashing here would double-strip backslashes.
		$id          = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name_raw    = array_key_exists( 'name', $_POST ) ? $_POST['name'] : null;
		$filters_raw = array_key_exists( 'filters', $_POST ) ? $_POST['filters'] : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = $this->update_profile( $id, $name_raw, $filters_raw );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'profile'  => $this->public_profile( $result ),
				'profiles' => $this->list_profiles(),
			)
		);
	}

	/**
	 * AJAX: delete a profile.
	 *
	 * @since 1.8.3.0
	 */
	public function ajax_delete() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() above runs check_ajax_referer().
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$result = $this->delete_profile( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'profiles' => $this->list_profiles() ) );
	}

	// ---------------------------------------------------------------------
	// CRUD core — no output/exit, unit-testable directly.
	// ---------------------------------------------------------------------

	/**
	 * Return every stored profile in a public shape, sorted by name.
	 *
	 * @since 1.8.3.0
	 * @return array[]
	 */
	public function list_profiles() {
		$store    = $this->get_store();
		$profiles = $store['profiles'];

		usort(
			$profiles,
			static function ( $a, $b ) {
				$an = isset( $a['name'] ) ? (string) $a['name'] : '';
				$bn = isset( $b['name'] ) ? (string) $b['name'] : '';
				return strcasecmp( $an, $bn );
			}
		);

		$out = array();
		foreach ( $profiles as $profile ) {
			$out[] = $this->public_profile( $profile );
		}
		return $out;
	}

	/**
	 * Create a new profile.
	 *
	 * @since 1.8.3.0
	 * @param mixed $name_raw    Raw (slashed) name from the request.
	 * @param mixed $filters_raw Raw (slashed) JSON filters payload.
	 * @return array|WP_Error The stored profile record, or an error.
	 */
	public function create_profile( $name_raw, $filters_raw ) {
		$name = $this->sanitize_name( $name_raw );
		if ( '' === $name ) {
			return new WP_Error( 'empty_name', __( 'Please enter a profile name.', 'opti-behavior' ) );
		}

		$filters = $this->sanitize_filters_payload( $filters_raw );

		$store = $this->get_store();

		if ( count( $store['profiles'] ) >= self::MAX_PROFILES ) {
			return new WP_Error(
				'limit_reached',
				sprintf(
					/* translators: %d: maximum number of saved profiles. */
					__( 'You can save up to %d filter profiles. Delete one to make room.', 'opti-behavior' ),
					self::MAX_PROFILES
				)
			);
		}

		if ( $this->name_exists( $store, $name ) ) {
			return new WP_Error( 'duplicate_name', __( 'A profile with this name already exists.', 'opti-behavior' ) );
		}

		$now     = time();
		$profile = array(
			'id'         => 'p_' . uniqid(),
			'name'       => $name,
			'filters'    => $filters,
			'created'    => $now,
			'updated'    => $now,
			'created_by' => get_current_user_id(),
		);

		$store['profiles'][] = $profile;
		$this->save_store( $store );

		return $profile;
	}

	/**
	 * Rename and/or overwrite a profile's payload.
	 *
	 * `null` for a field means "not provided" (leave unchanged). Passing an
	 * empty name is treated as a validation error.
	 *
	 * @since 1.8.3.0
	 * @param string $id          Profile id.
	 * @param mixed  $name_raw    Raw name, or null to keep the current name.
	 * @param mixed  $filters_raw Raw JSON filters, or null to keep the current payload.
	 * @return array|WP_Error The updated profile record, or an error.
	 */
	public function update_profile( $id, $name_raw, $filters_raw ) {
		if ( '' === (string) $id ) {
			return new WP_Error( 'missing_id', __( 'Missing profile id.', 'opti-behavior' ) );
		}

		$store = $this->get_store();
		$index = $this->find_index( $store, $id );
		if ( -1 === $index ) {
			return new WP_Error( 'not_found', __( 'Profile not found.', 'opti-behavior' ) );
		}

		$profile = $store['profiles'][ $index ];

		if ( null !== $name_raw ) {
			$name = $this->sanitize_name( $name_raw );
			if ( '' === $name ) {
				return new WP_Error( 'empty_name', __( 'Please enter a profile name.', 'opti-behavior' ) );
			}
			if ( $this->name_exists( $store, $name, $id ) ) {
				return new WP_Error( 'duplicate_name', __( 'A profile with this name already exists.', 'opti-behavior' ) );
			}
			$profile['name'] = $name;
		}

		if ( null !== $filters_raw ) {
			$profile['filters'] = $this->sanitize_filters_payload( $filters_raw );
		}

		$profile['updated']          = time();
		$store['profiles'][ $index ] = $profile;
		$this->save_store( $store );

		return $profile;
	}

	/**
	 * Delete a profile by id (idempotent — deleting an absent id is a no-op).
	 *
	 * @since 1.8.3.0
	 * @param string $id Profile id.
	 * @return true|WP_Error
	 */
	public function delete_profile( $id ) {
		if ( '' === (string) $id ) {
			return new WP_Error( 'missing_id', __( 'Missing profile id.', 'opti-behavior' ) );
		}

		$store = $this->get_store();
		$index = $this->find_index( $store, $id );
		if ( -1 !== $index ) {
			array_splice( $store['profiles'], $index, 1 );
			$this->save_store( $store );
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// Internal helpers.
	// ---------------------------------------------------------------------

	/**
	 * Verify nonce + capability, dying with a JSON error otherwise.
	 *
	 * @since 1.8.3.0
	 */
	private function verify_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage filter profiles.', 'opti-behavior' ) ) );
		}
	}

	/**
	 * Read the option, normalizing a missing/malformed value to an empty store.
	 *
	 * @since 1.8.3.0
	 * @return array{version:int,profiles:array}
	 */
	private function get_store() {
		$store = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $store ) || ! isset( $store['profiles'] ) || ! is_array( $store['profiles'] ) ) {
			return array(
				'version'  => self::SCHEMA_VERSION,
				'profiles' => array(),
			);
		}
		if ( ! isset( $store['version'] ) ) {
			$store['version'] = self::SCHEMA_VERSION;
		}
		return $store;
	}

	/**
	 * Persist the whole store (read-modify-write, last-write-wins).
	 *
	 * @since 1.8.3.0
	 * @param array $store Store array.
	 */
	private function save_store( $store ) {
		update_option( self::OPTION_NAME, $store, false );
	}

	/**
	 * Sanitize + clamp a profile name. Returns '' when empty after trim.
	 *
	 * @since 1.8.3.0
	 * @param mixed $raw Raw (slashed) name.
	 * @return string
	 */
	private function sanitize_name( $raw ) {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return '';
		}
		$name = sanitize_text_field( wp_unslash( (string) $raw ) );
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, self::NAME_MAX_LENGTH );
		} else {
			$name = substr( $name, 0, self::NAME_MAX_LENGTH );
		}
		return trim( $name );
	}

	/**
	 * Sanitize a raw filters payload through the shared allow-list trait.
	 *
	 * The trait method unslashes + JSON-decodes and drops any non-allow-listed
	 * key, enforcing the numeric/multi contract — do not re-implement it.
	 *
	 * @since 1.8.3.0
	 * @param mixed $raw_json Raw (slashed) JSON string (or array).
	 * @return array
	 */
	private function sanitize_filters_payload( $raw_json ) {
		return $this->sanitize_advanced_filters_from_request( array( 'advanced_filters' => $raw_json ) );
	}

	/**
	 * Case-insensitive duplicate-name check.
	 *
	 * @since 1.8.3.0
	 * @param array  $store      Store array.
	 * @param string $name       Candidate name.
	 * @param string $exclude_id Profile id to skip (for rename).
	 * @return bool
	 */
	private function name_exists( $store, $name, $exclude_id = '' ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		foreach ( $store['profiles'] as $profile ) {
			if ( '' !== $exclude_id && isset( $profile['id'] ) && $profile['id'] === $exclude_id ) {
				continue;
			}
			$existing = isset( $profile['name'] ) ? (string) $profile['name'] : '';
			$existing = function_exists( 'mb_strtolower' ) ? mb_strtolower( $existing ) : strtolower( $existing );
			if ( $existing === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find a profile's index by id, or -1.
	 *
	 * @since 1.8.3.0
	 * @param array  $store Store array.
	 * @param string $id    Profile id.
	 * @return int
	 */
	private function find_index( $store, $id ) {
		foreach ( $store['profiles'] as $index => $profile ) {
			if ( isset( $profile['id'] ) && $profile['id'] === $id ) {
				return (int) $index;
			}
		}
		return -1;
	}

	/**
	 * Reduce a stored profile record to its public (client-facing) shape.
	 *
	 * @since 1.8.3.0
	 * @param array $profile Stored profile record.
	 * @return array{id:string,name:string,filters:array,updated:int}
	 */
	private function public_profile( $profile ) {
		return array(
			'id'      => isset( $profile['id'] ) ? (string) $profile['id'] : '',
			'name'    => isset( $profile['name'] ) ? (string) $profile['name'] : '',
			'filters' => ( isset( $profile['filters'] ) && is_array( $profile['filters'] ) ) ? $profile['filters'] : array(),
			'updated' => isset( $profile['updated'] ) ? (int) $profile['updated'] : 0,
		);
	}
}
