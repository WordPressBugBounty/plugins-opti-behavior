<?php
/**
 * Pro endpoint graceful-degradation shim.
 *
 * When the Pro plugin is NOT active, cached HTML (page-cache / CDN) can still
 * embed the Pro tracker scripts (session-recorder.js, errors-tracker.js,
 * form-analytics-tracker.js) together with their inline config/nonces. Those
 * scripts POST to Pro-only admin-ajax actions whose handlers no longer exist,
 * so WordPress core answers with `wp_die( '0', 400 )`. Old cached tracker
 * builds (shipped before the client-side dead-endpoint guard) misread that
 * permanent 400 as transient, retry in a loop, and persist a growing payload —
 * flooding admin-ajax.php and the browser console.
 *
 * This shim registers minimal stub handlers for those Pro-only frontend actions
 * so a stale visitor receives a well-formed WordPress AJAX success response
 * (`{"success":true}`). The old tracker treats that as "saved", clears its
 * pending queue, and stops retrying — ending the flood for visitors we cannot
 * reach with a JS fix (they run week-old cached JS).
 *
 * Cache-safety: the stubs perform NO privileged or stateful action, so they
 * require NO nonce (a stale cached nonce must not matter) and present NO CSRF
 * surface. They read and process NOTHING from the request body, making them
 * cheap under a flood.
 *
 * Gating is by ACTUAL handler absence, not a Pro-active constant: for each
 * action the stub registers only when `has_action( 'wp_ajax_nopriv_<action>' )`
 * is false. This yields to Pro's real handler whenever it exists AND still
 * covers the Pro-active-but-unlicensed / partial-license case, where Pro loads
 * (constant defined) but its gated feature modules never register the save
 * handlers. Registration runs on `init` priority 999 — after Pro registers its
 * handlers on `init` priority 11 (`opti-behavior-pro.php:760`).
 *
 * @package opti-behavior
 * @since 1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Opti_Behavior_Pro_Ajax_Shim' ) ) {

	/**
	 * Registers no-op success stubs for Pro-only frontend AJAX actions.
	 *
	 * @since 1.7.1
	 */
	class Opti_Behavior_Pro_Ajax_Shim {

		/**
		 * Pro-only frontend (visitor-facing) AJAX action names that old cached
		 * tracker JS POSTs to. Each is registered by Pro with both a `wp_ajax_`
		 * and a `wp_ajax_nopriv_` hook, so the shim mirrors both.
		 *
		 * Sources (opti-behavior-pro):
		 *  - class-opti-behavior-session-recording.php:104,118
		 *  - class-opti-behavior-errors-tracking.php:76,78,80,82
		 *  - class-opti-behavior-form-analytics.php:71,73
		 *
		 * @since 1.7.1
		 * @var string[]
		 */
		const ACTIONS = array(
			'opti_behavior_save_recording',
			'opti_behavior_register_page_visit',
			'opti_behavior_save_error',
			'opti_behavior_save_friction',
			'opti_behavior_save_performance',
			'opti_behavior_save_broken_link',
			'opti_behavior_save_form_interaction',
			'opti_behavior_save_form_submission',
		);

		/**
		 * Register a stub for every listed action that has no live handler.
		 *
		 * Must run on `init` priority 999 — LATE enough that Pro (which registers
		 * its real handlers on `init` priority 11) has already bound them, so the
		 * per-action `has_action()` probe is authoritative. For each action:
		 *  - Real Pro nopriv handler present → skip (Pro wins).
		 *  - Absent (Pro off, OR Pro active-but-unlicensed/partial) → register the
		 *    `wp_ajax_` + `wp_ajax_nopriv_` stub pair for that action.
		 *
		 * @since 1.7.1
		 * @return void
		 */
		public static function maybe_register() {
			foreach ( self::ACTIONS as $action ) {
				// Probe the visitor-facing hook: if Pro bound a real handler for
				// this action, defer to it entirely.
				if ( has_action( 'wp_ajax_nopriv_' . $action ) ) {
					continue;
				}

				add_action( 'wp_ajax_' . $action, array( __CLASS__, 'handle' ) );
				add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'handle' ) );
			}
		}

		/**
		 * Stub handler: respond with the WordPress AJAX success shape and exit.
		 *
		 * Deliberately reads NOTHING from `$_POST` / `php://input` and stores
		 * nothing. It emits HTTP 200 with the full documented envelope
		 * `{"success":true,"data":null}` and terminates — the exact contract the
		 * Pro trackers treat as a confirmed save (they then clear their pending
		 * queue and stop retrying). No user input is ever echoed back.
		 *
		 * The envelope is built explicitly rather than via
		 * `wp_send_json_success()`: that helper omits the `data` key entirely
		 * when no value is passed (`isset( null )` is false), emitting the
		 * shorter `{"success":true}`. Older cached tracker builds parse the
		 * response with a strict `data` lookup, so the key must be present.
		 *
		 * @since 1.7.1
		 * @return void
		 */
		public static function handle() {
			wp_send_json(
				array(
					'success' => true,
					'data'    => null,
				)
			);
		}
	}
}
