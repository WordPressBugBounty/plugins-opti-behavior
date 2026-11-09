<?php
/**
 * Opti-Behavior technical signature (OPT-2952).
 *
 * Three marks a developer inspecting a site can see, nothing a visitor sees:
 *   1. one console line printed by the public tracker (mode-aware),
 *   2. one HTML comment in the <head> of public pages,
 *   3. the HTTP header `X-Analytics: Opti-Behavior` on public HTML pages.
 *
 * Never a version number (fingerprint for attackers), never a clickable link,
 * never on a page where the tracker does not run. The console line has a
 * setting (one autoloaded option, no query); the HTML comment and the header
 * are always on and come off with the filter `opti_behavior_signature_markup`.
 *
 * @package opti-behavior
 * @since 1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signature printer. All static: no instance, no state.
 *
 * @since 1.11.0
 */
class Opti_Behavior_Signature {

	/**
	 * Autoloaded option holding the administrator's console choice ('1' / '0').
	 *
	 * Absent = enabled: a new install AND a site updated from an older version
	 * both get the signature without any migration write.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const OPTION = 'opti_behavior_signature';

	/**
	 * The HTML comment printed in the head of public pages.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const COMMENT = '<!-- This site uses Opti-Behavior - https://optiuser.com -->';

	/**
	 * Register the public hooks.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'send_header' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_comment' ), 1 );
	}

	/**
	 * Whether the console line is switched on for this site.
	 *
	 * One read of an autoloaded option, then the developer filter. Drives the
	 * console line only: the HTML comment and the HTTP header are always on.
	 *
	 * @since 1.11.0
	 * @return bool True when the console line may be printed.
	 */
	public static function is_enabled() {
		$stored = get_option( self::OPTION, '1' );
		$on     = ( '0' !== (string) $stored && '' !== (string) $stored );

		return (bool) apply_filters( 'opti_behavior_signature', $on );
	}

	/**
	 * Whether the HTML comment and the HTTP header may be printed.
	 *
	 * No setting in the interface: they are always there on the public pages
	 * where the tracker runs. A developer removes them with one filter, and
	 * removing them changes nothing else in the plugin.
	 *
	 * @since 1.11.0
	 * @return bool True when the two markup marks may be printed.
	 */
	public static function markup_enabled() {
		return (bool) apply_filters( 'opti_behavior_signature_markup', true );
	}

	/**
	 * Whether THIS request is a public HTML page view.
	 *
	 * Checked before the header, which is sent long before any script is
	 * enqueued: admin, AJAX, REST, cron, feeds and XML-RPC never get it, and a
	 * visitor the tracker would not follow (an administrator who turned own-visit
	 * tracking off) gets nothing either.
	 *
	 * @since 1.11.0
	 * @return bool True when the request is a public page the tracker follows.
	 */
	public static function is_public_page_request() {
		if ( is_admin() ) {
			return false;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && ( is_feed() || is_robots() || is_trackback() ) ) {
			return false;
		}
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return false;
		}
		if ( class_exists( 'Opti_Behavior_Heatmap_Frontend' )
			&& ! Opti_Behavior_Heatmap_Frontend::admin_tracking_allowed() ) {
			return false;
		}

		return true;
	}

	/**
	 * Send the `X-Analytics` header on public HTML pages.
	 *
	 * A page cache that stores the body only will not replay this header; that
	 * is accepted (the HTML comment stays in the cached page).
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function send_header() {
		if ( headers_sent() ) {
			return;
		}
		if ( ! self::is_public_page_request() || ! self::markup_enabled() ) {
			return;
		}

		header( 'X-Analytics: Opti-Behavior' );
	}

	/**
	 * Print the HTML comment, once, in the head of a page the tracker runs on.
	 *
	 * `wp_enqueue_scripts` has already run at `wp_head`, so the reporter handle
	 * tells whether the tracker really runs on this page.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function print_comment() {
		static $printed = false;

		if ( $printed ) {
			return;
		}
		if ( ! self::tracker_enqueued() || ! self::markup_enabled() ) {
			return;
		}

		$printed = true;

		echo self::COMMENT . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed literal comment, no variable part.
	}

	/**
	 * Whether the public tracker is enqueued on this page view.
	 *
	 * @since 1.11.0
	 * @return bool True when the reporter script will be served.
	 */
	public static function tracker_enqueued() {
		if ( ! function_exists( 'wp_script_is' ) ) {
			return false;
		}

		return wp_script_is( 'opti-behavior-reporter', 'enqueued' );
	}

	/**
	 * The console line the tracker prints, as a tiny inline script.
	 *
	 * Printed next to the tracker config already on the page: no new request, no
	 * new file. Cookieless mode = one line. Consent mode = "waiting for consent"
	 * then "active" once the visitor accepted; a refusal prints nothing.
	 *
	 * @since 1.11.0
	 * @param string $privacy_mode Plugin privacy mode ('anonymous' / 'full').
	 * @return string JavaScript, or '' when the signature is off.
	 */
	public static function console_script( $privacy_mode ) {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$head = '(function(){var c=window.console;if(!c||typeof c.info!=="function"){return;}'
			. 'var p="[Opti-Behavior] tracker loaded ";';

		// Cookieless mode: one line, nothing to wait for.
		if ( 'full' !== $privacy_mode ) {
			return $head . 'c.info(p+"(cookieless mode)");})();';
		}

		// Consent mode: the waiting line only while no choice is stored, the
		// active line once accepted; a refusal prints nothing at all. The real
		// choice is the `optibehavior_consent` cookie, read first: this script
		// runs before the consent script, and that script also sets the global
		// `window.optiBehaviorConsentState` to 'rejected' for a visitor who has
		// not chosen yet (anonymous start), so the global alone would silence
		// the waiting line. The global is only read when no cookie is stored.
		return $head
			. 'var s={};function say(k,m){if(s[k]){return;}s[k]=true;c.info(m);}'
			. 'function cook(){var n="optibehavior_consent=",a=document.cookie?document.cookie.split(";"):[];'
			. 'for(var i=0;i<a.length;i++){var x=a[i];while(x.charAt(0)===" "){x=x.substring(1);}'
			. 'if(x.indexOf(n)===0){return decodeURIComponent(x.substring(n.length));}}return "";}'
			. 'function look(){var v=cook();'
			. 'if(!v&&window.optiBehaviorConsentState==="granted"){v="granted";}'
			. 'if(v==="granted"){say("a",p+"(consent mode: active)");return true;}'
			. 'if(v==="rejected"||v==="denied"){return true;}return false;}'
			. 'if(!look()){say("w",p+"(consent mode: waiting for consent)");'
			. 'document.addEventListener("optibehavior:consent_updated",function(){look();});}})();';
	}
}
