<?php
/**
 * Shared stats spam filter helpers.
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes the traffic exclusion rules used by reporting pages.
 */
class Opti_Behavior_Stats_Spam_Filter {

	/**
	 * Get normalized Traffic Behavior settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled'      => true,
				'spam_duration_threshold'     => 3,
				'spam_min_scrolls_threshold'  => 0,
				'spam_min_clicks_threshold'   => 1,
			)
		);

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args(
			$settings,
			array(
				'spam_detection_enabled'      => true,
				'spam_duration_threshold'     => 3,
				'spam_min_scrolls_threshold'  => 0,
				'spam_min_clicks_threshold'   => 1,
			)
		);
	}

	/**
	 * Return true when global spam exclusion is enabled.
	 *
	 * The Traffic Behavior settings page is the product-wide source of truth:
	 * enabled means reporting pages exclude spam according to the shared policy;
	 * disabled means reporting pages include all sessions.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::settings();

		return ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] );
	}

	/**
	 * Resolve reporting spam exclusion state.
	 *
	 * A null value means "follow the Traffic Behavior setting". Explicit true
	 * and false values are per-request reporting overrides.
	 *
	 * @param bool|null $exclude_spam Optional reporting override.
	 * @return bool
	 */
	public static function should_exclude( $exclude_spam = null ) {
		// When spam detection is globally disabled the feature is off everywhere:
		// exclusion is a no-op regardless of any per-request override, so reporting
		// includes every session. Without this guard a screen-level exclude_spam=1
		// toggle would still filter sessions while the product-wide setting is
		// disabled, diverging from surfaces that honor the disabled default.
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( null === $exclude_spam ) {
			return true;
		}

		return (bool) $exclude_spam;
	}

	/**
	 * Traffic types that should be excluded when spam filtering is enabled.
	 *
	 * @return array
	 */
	public static function excluded_traffic_types() {
		return array( 'spam', 'bot', 'automated' );
	}

	/**
	 * Configured session duration threshold in seconds.
	 *
	 * @return int
	 */
	public static function duration_threshold() {
		$settings = self::settings();
		return isset( $settings['spam_duration_threshold'] ) ? max( 0, intval( $settings['spam_duration_threshold'] ) ) : 3;
	}

	/**
	 * Configured minimum scroll threshold.
	 *
	 * @return int
	 */
	public static function min_scrolls_threshold() {
		$settings = self::settings();
		return isset( $settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $settings['spam_min_scrolls_threshold'] ) ) : 0;
	}

	/**
	 * Configured minimum click threshold.
	 *
	 * @return int
	 */
	public static function min_clicks_threshold() {
		$settings = self::settings();
		return isset( $settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $settings['spam_min_clicks_threshold'] ) ) : 1;
	}

	/**
	 * Build a stable cache-key fragment for the active spam policy.
	 *
	 * @return string
	 */
	public static function cache_key( $exclude_spam = null ) {
		if ( ! self::should_exclude( $exclude_spam ) ) {
			return 'spam_filter_disabled_include_all';
		}

		return 'spam_filter_enabled_' . self::duration_threshold() . '_' . self::min_scrolls_threshold() . '_' . self::min_clicks_threshold() . '_' . implode( '_', self::excluded_traffic_types() );
	}

	/**
	 * Build the duration expression for a sessions table alias.
	 *
	 * @param string $alias Sessions table alias.
	 * @return string SQL expression.
	 */
	public static function duration_sql( $alias = 's' ) {
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		$prefix = $alias ? $alias . '.' : '';

		return "GREATEST(COALESCE({$prefix}duration, 0), COALESCE(TIMESTAMPDIFF(SECOND, {$prefix}start_time, COALESCE({$prefix}end_time, {$prefix}start_time)), 0))";
	}

	/**
	 * Build the canonical recordings/report duration expression.
	 *
	 * Use the largest reliable duration source. Recording rows can lag behind
	 * the Free heartbeat/session duration when mobile unload or incremental
	 * saves arrive out of order, so a stale recording duration must not make a
	 * real engaged session look short to spam filtering.
	 *
	 * @param string $recording_alias Recordings table alias.
	 * @param string $session_alias   Sessions table alias.
	 * @return string SQL expression.
	 */
	public static function recording_duration_sql( $recording_alias = 'r', $session_alias = 's' ) {
		$recording_alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $recording_alias );
		$recording_prefix = $recording_alias ? $recording_alias . '.' : '';
		$session_duration = self::duration_sql( $session_alias );

		return "GREATEST(COALESCE({$recording_prefix}duration, 0), {$session_duration})";
	}

	/**
	 * Build a SQL fragment for excluding spam/bot/automated traffic.
	 *
	 * @param string $alias       Table alias.
	 * @param string $column      Traffic type column.
	 * @param string    $conjunction  SQL conjunction, usually AND.
	 * @param bool|null $exclude_spam Optional reporting override. Null follows the
	 *                                global Traffic Behavior toggle; an explicit
	 *                                boolean is a per-request override that wins
	 *                                even while the global toggle is off (used by
	 *                                the recordings-surface contract, RF-47F).
	 * @return string
	 */
	public static function traffic_type_sql( $alias = 's', $column = 'traffic_type', $conjunction = 'AND', $exclude_spam = null ) {
		$apply = ( null === $exclude_spam ) ? self::should_exclude() : (bool) $exclude_spam;

		if ( ! $apply ) {
			return '';
		}

		$alias       = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		$column      = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $column );
		$conjunction = strtoupper( trim( (string) $conjunction ) );
		$conjunction = in_array( $conjunction, array( 'AND', 'WHERE' ), true ) ? $conjunction : 'AND';
		$qualified   = $alias ? "{$alias}.{$column}" : $column;
		$values      = "'" . implode( "','", array_map( 'esc_sql', self::excluded_traffic_types() ) ) . "'";

		return " {$conjunction} ({$qualified} IS NULL OR {$qualified} = '' OR {$qualified} NOT IN ({$values}))";
	}

	/**
	 * Build a shared sessions-table spam policy fragment.
	 *
	 * This is the canonical report-page policy for session-grain metrics. Exclusion
	 * keys strictly on the STORED traffic_type that the classifier already persisted
	 * (ingest heartbeat / session-end beacon / post-event save / repair migration)
	 * using the robust multi-source engagement contract in classify_session(). It
	 * returns an empty fragment when spam detection is disabled so the caller
	 * includes all sessions.
	 *
	 * Reporting must honor that stored verdict and must NOT re-derive its own verdict
	 * at query time. The previous implementation re-evaluated duration/scroll/click
	 * here using an events-only (16/17, 32/33) signal that lagged behind the canonical
	 * classifier: a human-flagged session whose heatmap click events had not yet
	 * flushed — or that engaged via session_pages counters rather than raw events, or
	 * an admin's own browsing session — was silently excluded even though its stored
	 * traffic_type was 'human'. That produced the report divergence where the count
	 * tiles / funnels dropped sessions the traffic_classification widget still counted
	 * as human. Keying only on the stored flag guarantees every surface (count tiles,
	 * funnels, avg tiles, repository counts, traffic_classification widget, Pro
	 * surfaces) agrees on which sessions are excluded.
	 *
	 * @param string    $session_alias Sessions table alias.
	 * @param string    $conjunction   SQL conjunction, usually AND.
	 * @param bool|null $exclude_spam  Optional reporting override.
	 * @return string SQL fragment beginning with the requested conjunction.
	 */
	public static function session_sql( $session_alias = 's', $conjunction = 'AND', $exclude_spam = null ) {
		if ( ! self::should_exclude( $exclude_spam ) ) {
			return '';
		}

		return self::traffic_type_sql( $session_alias, 'traffic_type', $conjunction, true );
	}

	/**
	 * Build recordings-specific threshold SQL fragments.
	 *
	 * @param string $recording_alias Recordings table alias.
	 * @param string $session_alias   Sessions table alias.
	 * @param string    $scroll_alias    Derived scroll table alias.
	 * @param bool|null $exclude_spam    Optional reporting override.
	 * @return array
	 */
	public static function recordings_threshold_sql( $recording_alias = 'r', $session_alias = 's', $scroll_alias = 'spam_scrolls', $exclude_spam = null ) {
		global $wpdb;

		// Recordings-surface override contract: a null request follows the global
		// Traffic Behavior toggle (via should_exclude()), but an explicit per-request
		// boolean always wins. The recordings list exposes its own exclude_spam
		// control, so an admin who explicitly requests threshold filtering must get
		// it even while the product-wide spam_detection_enabled toggle is off
		// (RF-47F; this mirrors the Pro get_recordings_spam_filter_sql() fallback
		// semantics that this canonical helper replaced). Explicit false likewise
		// disables filtering. Session-grain reporting (session_sql) intentionally
		// keeps the stricter disabled-means-off-everywhere policy.
		$apply_thresholds = ( null === $exclude_spam ) ? self::should_exclude() : (bool) $exclude_spam;

		if ( ! $apply_thresholds ) {
			return array(
				'join'      => '',
				'where'     => '',
				'values'    => array(),
				'cache_key' => self::cache_key( $exclude_spam ),
			);
		}

		$recording_alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $recording_alias );
		$session_alias   = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $session_alias );
		$scroll_alias    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $scroll_alias );
		$scroll_alias    = $scroll_alias ? $scroll_alias : 'spam_scrolls';

		$recording_prefix  = $recording_alias ? $recording_alias . '.' : '';
		$recording_session = $recording_prefix . 'session_id';
		$recording_clicks  = $recording_prefix . 'click_count';
		$page_alias        = $scroll_alias . '_pages';
		$event_alias       = $scroll_alias . '_events';
		$spam_duration     = self::duration_threshold();
		$spam_scrolls      = self::min_scrolls_threshold();
		$spam_clicks       = self::min_clicks_threshold();
		$duration_sql      = "GREATEST(" . self::recording_duration_sql( $recording_alias, $session_alias ) . ", COALESCE({$page_alias}.duration, 0))";
		$traffic_where     = self::traffic_type_sql( $session_alias, 'traffic_type', 'AND', true );

		// Engagement gate must never misread a heatmap-only human session as spam.
		// The per-page beacon counters (session_pages.clicks_count / scroll_depth) can
		// read 0 for a session whose interactions were recorded only through the heatmap
		// tracker, while the real heatmap events (16/17 clicks, 32/33 scrolls) prove the
		// engagement. GREATEST(existing contract, real heatmap event count) keeps the
		// original counter-based value as the primary source and only ever RAISES it with
		// the observed events. It is monotonic upward, so the gate can admit more genuine
		// sessions but can never newly exclude one that passed before; working sessions
		// whose beacon counters already meet or exceed their event counts are unchanged.
		$scroll_sql        = "GREATEST(
			(CASE WHEN COALESCE({$page_alias}.page_count, 0) > 0 THEN COALESCE({$page_alias}.scroll_count, 0) ELSE COALESCE({$event_alias}.scroll_count, 0) END),
			COALESCE({$event_alias}.scroll_count, 0)
		)";
		$click_sql         = "GREATEST(
			(CASE WHEN COALESCE({$page_alias}.page_count, 0) > 0 THEN COALESCE({$page_alias}.click_count, 0) WHEN {$event_alias}.session_id IS NULL THEN COALESCE({$recording_clicks}, 0) ELSE COALESCE({$event_alias}.click_count, 0) END),
			COALESCE({$event_alias}.click_count, 0)
		)";

		// Ghost-session tolerance: the WHERE below must NOT require a sessions-table
		// row ({$session_id} IS NOT NULL). A recording's session row can be missing
		// (cleanup/retention deleted sessions while recordings + session_pages
		// remain, or the row was never ingested). The engagement contract is
		// already multi-source (recordings, session_pages, heatmap events), so a
		// missing session row must fall through to those fallbacks instead of
		// being treated as spam. Requiring s.id previously excluded EVERY
		// recording when the sessions table lost rows, zeroing the heatmap DETAIL
		// page (0 sessions / 0 clicks) while the heatmap LIST — which counts from
		// session_pages — still showed the sessions. The stored-verdict exclusion
		// still applies whenever the session row exists (traffic_type NOT IN
		// spam/bot/... — that clause is NULL-tolerant by construction).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Returns derived-table SQL fragments using fixed plugin table names and sanitized aliases.
		$fragments = array(
			'join'      => " LEFT JOIN (
					SELECT
						session_id,
						COUNT(*) AS page_count,
						MAX(COALESCE(duration, 0)) AS duration,
						SUM(CASE WHEN COALESCE(scroll_depth, 0) > 0 THEN 1 ELSE 0 END) AS scroll_count,
						SUM(COALESCE(clicks_count, 0)) AS click_count
					FROM {$wpdb->prefix}optibehavior_session_pages
					GROUP BY session_id
				) {$page_alias} ON {$recording_session} = {$page_alias}.session_id
				LEFT JOIN (
					SELECT
						session_id,
						SUM(CASE WHEN event IN (32,33) THEN 1 ELSE 0 END) AS scroll_count,
						SUM(CASE WHEN event IN (16,17) THEN 1 ELSE 0 END) AS click_count
					FROM {$wpdb->prefix}optibehavior_events
					WHERE event IN (16,17,32,33)
					GROUP BY session_id
				) {$event_alias} ON {$recording_session} = {$event_alias}.session_id",
			'where'     => "(1=1
				{$traffic_where}
				AND {$duration_sql} >= %d
				AND {$scroll_sql} >= %d
				AND {$click_sql} >= %d)",
			'values'    => array( $spam_duration, $spam_scrolls, $spam_clicks ),
			'cache_key' => self::cache_key( true ),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $fragments;
	}

	/**
	 * Read the canonical multi-source engagement metrics for one session.
	 *
	 * Single source of truth for both the ingest (heartbeat) and refresh
	 * classification paths. Prefers session-page timeline counts, then legacy
	 * heatmap event aggregates, then recording metadata, and always takes the
	 * largest reliable duration source (recording, session, wall-clock, page).
	 *
	 * @param string $session_id Session ID.
	 * @return array|null Normalized metrics { traffic_type, spam_reason, duration, scrolls, clicks }, or null when the session row is missing.
	 */
	public static function get_session_classification_metrics( $session_id ) {
		global $wpdb;

		$session_id = sanitize_text_field( (string) $session_id );
		if ( '' === $session_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses fixed plugin tables from $wpdb->prefix; session IDs are passed through placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					s.id,
					COALESCE(s.traffic_type, '') AS traffic_type,
					s.spam_reason,
					GREATEST(
						COALESCE(r.recording_duration, 0),
						COALESCE(s.duration, 0),
						COALESCE(TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)), 0),
						COALESCE(sp.page_duration, 0)
					) AS duration_seconds,
					COALESCE(sp.page_count, 0) AS page_count,
					COALESCE(sp.scroll_count, 0) AS page_scroll_count,
					COALESCE(sp.click_count, 0) AS page_click_count,
					COALESCE(ev.has_events, 0) AS has_event_counts,
					COALESCE(ev.scroll_count, 0) AS event_scroll_count,
					COALESCE(ev.click_count, 0) AS event_click_count,
					COALESCE(r.click_count, 0) AS recording_click_count
				FROM {$wpdb->prefix}optibehavior_sessions s
				LEFT JOIN (
					SELECT session_id, MAX(COALESCE(duration, 0)) AS recording_duration, MAX(COALESCE(click_count, 0)) AS click_count
					FROM {$wpdb->prefix}optibehavior_recordings
					WHERE session_id = %s
					GROUP BY session_id
				) r ON s.id = r.session_id
				LEFT JOIN (
					SELECT
						session_id,
						COUNT(*) AS page_count,
						MAX(COALESCE(duration, 0)) AS page_duration,
						SUM(CASE WHEN COALESCE(scroll_depth, 0) > 0 THEN 1 ELSE 0 END) AS scroll_count,
						SUM(COALESCE(clicks_count, 0)) AS click_count
					FROM {$wpdb->prefix}optibehavior_session_pages
					WHERE session_id = %s
					GROUP BY session_id
				) sp ON s.id = sp.session_id
				LEFT JOIN (
					SELECT
						session_id,
						COUNT(*) AS has_events,
						SUM(CASE WHEN event IN (32,33) THEN 1 ELSE 0 END) AS scroll_count,
						SUM(CASE WHEN event IN (16,17) THEN 1 ELSE 0 END) AS click_count
					FROM {$wpdb->prefix}optibehavior_events
					WHERE session_id = %s AND event IN (16,17,32,33)
					GROUP BY session_id
				) ev ON s.id = ev.session_id
				WHERE s.id = %s
				LIMIT 1",
				$session_id,
				$session_id,
				$session_id,
				$session_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$duration     = max( 0, (int) $row->duration_seconds );
		$scroll_count = ( (int) $row->page_count > 0 ) ? (int) $row->page_scroll_count : (int) $row->event_scroll_count;

		// Engagement-gate parity with recordings_threshold_sql() (2026-08-16
		// incident fix): the click count is the MAX across every reliable
		// source — session_pages beacon counters, heatmap click events (16/17),
		// and recording metadata — never an either/or fallback. The old
		// fallback chain read 0 clicks for sessions whose only click evidence
		// lived in a source the chain skipped (e.g. a session_pages row with
		// clicks_count = 0 masked real heatmap 16/17 events), so genuinely
		// engaged sessions were flagged `few_clicks` spam and then mass-deleted
		// by the spam cleanup tiers. max() is monotonic upward: it can only
		// ADMIT more sessions as human, never newly classify one as spam, so a
		// session with ANY real click can never be flagged `few_clicks`.
		$click_count = max(
			(int) $row->page_click_count,
			(int) $row->event_click_count,
			(int) $row->recording_click_count
		);

		// Extension point for click evidence that lives outside the core session
		// tables. A form engagement (field focus/typing/select + submission) is
		// proof of a real click, but that evidence lives in PRO-only form tables
		// this FREE filter must not hard-reference. PRO hooks this to admit form
		// sessions as human, keyed on the session's OWN unique id. Combined with
		// max() it is monotonic upward like the source fan-in above: it can only
		// ADMIT more sessions as human, never newly flag one as spam — so a
		// session that touched a form can never be classified `few_clicks`.
		$click_count = max(
			$click_count,
			(int) apply_filters( 'opti_behavior_session_click_evidence', 0, $session_id )
		);

		return array(
			'traffic_type' => (string) $row->traffic_type,
			'spam_reason'  => $row->spam_reason,
			'duration'     => $duration,
			'scrolls'      => $scroll_count,
			'clicks'       => $click_count,
		);
	}

	/**
	 * Evaluate the spam verdict for engagement metrics against active thresholds.
	 *
	 * Pure decision function (no I/O). A session is human only when it satisfies
	 * ALL configured duration, scroll, and click thresholds; otherwise it is spam
	 * and the failing criteria form the spam reason.
	 *
	 * @param int $duration     Duration in seconds.
	 * @param int $scroll_count Scroll count.
	 * @param int $click_count  Click count.
	 * @return array { type: 'human'|'spam', reason: string|null, reasons: string[] }
	 */
	public static function evaluate_classification( $duration, $scroll_count, $click_count ) {
		$reasons = array();

		if ( (int) $duration < self::duration_threshold() ) {
			$reasons[] = 'short_duration';
		}
		if ( (int) $scroll_count < self::min_scrolls_threshold() ) {
			$reasons[] = 'few_scrolls';
		}
		if ( (int) $click_count < self::min_clicks_threshold() ) {
			$reasons[] = 'few_clicks';
		}

		return array(
			'type'    => empty( $reasons ) ? 'human' : 'spam',
			'reason'  => empty( $reasons ) ? null : implode( ',', $reasons ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Canonical session classifier shared by the ingest and refresh paths.
	 *
	 * Reads the robust multi-source engagement contract, evaluates the verdict,
	 * and persists it. Bot/automated sessions are protected and never touched.
	 *
	 * The optional `allow_spam_verdict` argument supports the session lifecycle:
	 * when false, a *new* spam verdict is not persisted (it may be a transient
	 * pre-flush window before heatmap click/scroll events land) — only an eager
	 * downgrade to human is written, and finalizing the spam verdict is left to a
	 * later caller (session-end / post-event re-classification). It defaults to
	 * true so refresh/back-compat callers keep the original finalizing behavior.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $args       Optional { allow_spam_verdict: bool }.
	 * @return array Result metadata.
	 */
	public static function classify_session( $session_id, $args = array() ) {
		global $wpdb;

		$session_id = sanitize_text_field( (string) $session_id );
		if ( '' === $session_id ) {
			return array( 'changed' => false, 'reason' => 'empty_session_id' );
		}

		if ( ! self::is_enabled() ) {
			return array( 'changed' => false, 'reason' => 'spam_detection_disabled' );
		}

		$args = wp_parse_args(
			$args,
			array(
				'allow_spam_verdict' => true,
			)
		);

		$metrics = self::get_session_classification_metrics( $session_id );
		if ( null === $metrics ) {
			return array( 'changed' => false, 'reason' => 'session_not_found' );
		}

		if ( in_array( $metrics['traffic_type'], array( 'bot', 'automated' ), true ) ) {
			return array( 'changed' => false, 'reason' => 'protected_traffic_type' );
		}

		$verdict    = self::evaluate_classification( $metrics['duration'], $metrics['scrolls'], $metrics['clicks'] );
		$new_type   = $verdict['type'];
		$new_reason = $verdict['reason'];
		$old_type   = '' === $metrics['traffic_type'] ? 'human' : $metrics['traffic_type'];

		$result_metrics = array(
			'duration' => $metrics['duration'],
			'scrolls'  => $metrics['scrolls'],
			'clicks'   => $metrics['clicks'],
		);

		// Lifecycle guard: while a session is still active a fresh spam verdict may
		// be a transient window before heatmap click/scroll events flush. Callers
		// that pass allow_spam_verdict=false only apply an eager downgrade-to-human
		// and defer the spam verdict to a finalizing re-classification.
		if ( 'spam' === $new_type && empty( $args['allow_spam_verdict'] ) && 'spam' !== $old_type ) {
			return array(
				'changed' => false,
				'reason'  => 'spam_verdict_deferred',
				'type'    => $old_type,
				'metrics' => $result_metrics,
			);
		}

		if ( $old_type === $new_type && (string) $new_reason === (string) $metrics['spam_reason'] ) {
			return array(
				'changed' => false,
				'reason'  => 'classification_unchanged',
				'type'    => $new_type,
				'metrics' => $result_metrics,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Intentional analytics session classification update on a fixed plugin table.
		$wpdb->update(
			$wpdb->prefix . 'optibehavior_sessions',
			array(
				'traffic_type' => $new_type,
				'spam_reason'  => $new_reason,
			),
			array( 'id' => $session_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		self::clear_traffic_classification_caches();

		return array(
			'changed' => true,
			'type'    => $new_type,
			'reason'  => $new_reason,
			'metrics' => $result_metrics,
		);
	}

	/**
	 * Recalculate one session's spam classification (Pro back-compat alias).
	 *
	 * Thin alias for classify_session(); retained because the Pro session-recording
	 * save path calls this method name. New callers should use classify_session().
	 *
	 * @param string $session_id Session ID.
	 * @return array Result metadata.
	 */
	public static function refresh_recording_session_classification( $session_id ) {
		return self::classify_session( $session_id );
	}

	/**
	 * Finalize a session's spam verdict at session-end, honoring a short grace
	 * window for in-flight click/scroll evidence.
	 *
	 * The frontend tracker's finalizeSession() (bound to 'pagehide'/'beforeunload')
	 * fires the event-queue flush (sendData(true)) and the session-end heartbeat
	 * (sendSessionEnd(), which carries is_final=1 and lands here) via two
	 * independent sendBeacon/keepalive-fetch requests. The spec/beacon delivery
	 * guarantee covers eventual arrival, NOT ordering: the is_final heartbeat can
	 * reach the server and be processed before the sibling event-flush request's
	 * INSERTs have committed. Classifying immediately in that case can lock in a
	 * spam verdict for a session that was genuinely engaged.
	 *
	 * This method closes that window: when an immediate (pre-wait) verdict would
	 * be 'spam', it polls the live engagement metrics for up to $grace_seconds,
	 * re-checking every $poll_interval_seconds, and returns as soon as the
	 * evidence already satisfies every threshold (no need to keep waiting) or the
	 * grace window is exhausted — then delegates to classify_session() (which
	 * re-reads metrics itself) to persist the final verdict. Sessions that are
	 * already clearly human, already flagged spam, not found, or protected
	 * (bot/automated) short-circuit immediately with no wait. This request is a
	 * fire-and-forget beacon the browser does not wait on, so blocking the PHP
	 * worker for up to a few seconds here has no user-facing latency cost.
	 *
	 * Independent of and complementary to the unconditional self-heal already in
	 * classify_session()/ajax_opti_behavior_heatmap(): events that land even
	 * later than this grace window still trigger a downgrade-to-human
	 * reclassification the next time they're processed; this method only
	 * shortens the common case so the verdict is usually correct immediately at
	 * session end instead of relying on a later correction.
	 *
	 * @param string $session_id           Session ID.
	 * @param int    $grace_seconds        Max total wait, in seconds. Default 3.
	 * @param float  $poll_interval_seconds Wait between re-checks, in seconds. Default 0.25.
	 * @return array Result metadata (same shape as classify_session()).
	 */
	public static function finalize_session_with_grace_window( $session_id, $grace_seconds = 3, $poll_interval_seconds = 0.25 ) {
		if ( ! self::is_enabled() ) {
			return array( 'changed' => false, 'reason' => 'spam_detection_disabled' );
		}

		$metrics = self::get_session_classification_metrics( $session_id );
		if ( null === $metrics ) {
			return array( 'changed' => false, 'reason' => 'session_not_found' );
		}

		if ( in_array( $metrics['traffic_type'], array( 'bot', 'automated' ), true ) ) {
			return array( 'changed' => false, 'reason' => 'protected_traffic_type' );
		}

		$verdict = self::evaluate_classification( $metrics['duration'], $metrics['scrolls'], $metrics['clicks'] );

		if ( 'spam' === $verdict['type'] ) {
			$poll_interval_us = max( 1000, (int) round( $poll_interval_seconds * 1000000 ) );
			$deadline         = microtime( true ) + max( 0, (float) $grace_seconds );

			while ( microtime( true ) < $deadline ) {
				usleep( $poll_interval_us );

				$metrics = self::get_session_classification_metrics( $session_id );
				if ( null === $metrics ) {
					break;
				}

				$verdict = self::evaluate_classification( $metrics['duration'], $metrics['scrolls'], $metrics['clicks'] );
				if ( 'human' === $verdict['type'] ) {
					break;
				}
			}
		}

		return self::classify_session( $session_id, array( 'allow_spam_verdict' => true ) );
	}

	/**
	 * Re-classify a batch of previously flagged spam sessions (one-time repair).
	 *
	 * Data-repair helper for the permanent-stick case: a session whose last
	 * heartbeat preceded the final heatmap click/scroll flush keeps a stale
	 * `spam` flag because the Free ingest lifecycle never re-evaluates it. This
	 * re-runs the canonical classifier (finalizing verdict) over recently-flagged
	 * `spam` rows in start_time-descending, id-stable batches so it is safe on
	 * large tables. Only `spam` rows are scanned; bot/automated rows are never
	 * touched (classify_session also protects them). Genuinely-spam sessions stay
	 * spam because the classifier re-evaluates their real engagement metrics.
	 *
	 * The (start_time, id) compound cursor makes each batch strictly resume after
	 * the previous one, so equal-timestamp boundary rows are never skipped or
	 * double-counted across batches.
	 *
	 * Recovery targeting (2026-08-16 mass-deletion incident, Phase C): the
	 * optional `reason_like` argument restricts the scan to spam rows whose
	 * stored `spam_reason` contains the given token (e.g. `few_clicks`), so a
	 * Danger-Zone "Re-evaluate spam flags" pass only touches the population the
	 * broken click-count derivation could have mis-flagged, instead of paying
	 * a full-metrics re-read for every genuinely-spam row. The classifier
	 * itself stays canonical: rows that still fail the thresholds keep their
	 * spam verdict, rows that now pass are downgraded to human.
	 *
	 * @param array $args {
	 *     Optional.
	 *     @type int        $limit       Max sessions per batch. Default 100.
	 *     @type int        $window_days Only sessions started within N days (0 = no window). Default 30.
	 *     @type array|null $cursor      { time, id } lower bound from a prior batch.
	 *     @type string     $reason_like Only spam rows whose spam_reason contains this token ('' = all). Default ''.
	 * }
	 * @return array {
	 *     @type int         $processed Sessions scanned this batch.
	 *     @type int         $changed   Sessions whose classification flipped.
	 *     @type bool        $done      Whether the scan is exhausted.
	 *     @type array|null  $cursor    { time, id } to resume the next batch.
	 * }
	 */
	public static function reclassify_flagged_sessions_batch( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'       => 100,
				'window_days' => 30,
				'cursor'      => null,
				'reason_like' => '',
			)
		);

		$limit       = max( 1, (int) $args['limit'] );
		$window      = max( 0, (int) $args['window_days'] );
		$cursor      = is_array( $args['cursor'] ) ? $args['cursor'] : null;
		$reason_like = sanitize_text_field( (string) $args['reason_like'] );

		$sessions = $wpdb->prefix . 'optibehavior_sessions';

		// Guard: sessions table missing → nothing to repair.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before a data-repair scan.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) !== $sessions ) {
			return array( 'processed' => 0, 'changed' => 0, 'done' => true, 'cursor' => null );
		}

		$where  = "traffic_type = 'spam'";
		$params = array();

		if ( '' !== $reason_like ) {
			$where   .= ' AND spam_reason LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $reason_like ) . '%';
		}

		if ( $window > 0 ) {
			$where   .= ' AND start_time >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - $window * DAY_IN_SECONDS );
		}

		if ( $cursor && isset( $cursor['time'], $cursor['id'] ) ) {
			$where   .= ' AND (start_time < %s OR (start_time = %s AND id < %s))';
			$params[] = (string) $cursor['time'];
			$params[] = (string) $cursor['time'];
			$params[] = (string) $cursor['id'];
		}

		$params[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; the WHERE clause is composed of literal SQL plus %s/%d placeholders bound through $wpdb->prepare.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time data-repair scan; no caching needed.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, start_time FROM {$sessions} WHERE {$where} ORDER BY start_time DESC, id DESC LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $rows ) ) {
			return array( 'processed' => 0, 'changed' => 0, 'done' => true, 'cursor' => $cursor );
		}

		$changed = 0;
		$last    = $cursor;
		foreach ( $rows as $row ) {
			$res = self::classify_session( $row->id );
			if ( ! empty( $res['changed'] ) ) {
				++$changed;
			}
			$last = array(
				'time' => (string) $row->start_time,
				'id'   => (string) $row->id,
			);
		}

		$done = count( $rows ) < $limit;

		// classify_session() already clears caches per flip; ensure a final clear
		// on completion so any residual report transients are invalidated.
		if ( $done && $changed > 0 ) {
			self::clear_traffic_classification_caches();
		}

		return array(
			'processed' => count( $rows ),
			'changed'   => $changed,
			'done'      => $done,
			'cursor'    => $last,
		);
	}

	/**
	 * Finalize ended-but-never-classified sessions (server-side safety net).
	 *
	 * The classification lifecycle is client-driven: sessions are inserted with
	 * the column default `traffic_type='human'`, periodic heartbeats defer any
	 * fresh spam verdict (allow_spam_verdict=false), and the spam verdict is only
	 * persisted when the browser delivers the `is_final=1` session-end beacon.
	 * When that beacon is lost (background-tab timer throttling, tab discard or
	 * kill, browser crash, network loss — all common on mobile), the session is
	 * frozen on the default `human` forever and every reporting surface counts it
	 * as real traffic, even a zero-click session that the configured thresholds
	 * say is spam. reclassify_flagged_sessions_batch() cannot repair this: it
	 * scans `traffic_type='spam'` rows only (spam -> human direction).
	 *
	 * This sweep closes the gap. It scans sessions that are clearly over
	 * (COALESCE(end_time, start_time) older than the caller's `stale_before`
	 * cutoff, so live sessions keep their deferred-verdict behavior) and still
	 * carry an unfinalized type (NULL / '' / 'human'), and re-runs the canonical
	 * finalizing classifier on each. classify_session() is deterministic and
	 * idempotent over the stored engagement evidence: genuinely engaged sessions
	 * come back 'classification_unchanged' (no write) while click-less/short
	 * sessions flip to spam with their real reasons. Bot/automated rows are never
	 * selected (and classify_session() protects them anyway). Late-arriving
	 * events can still self-heal a swept session back to human via the existing
	 * downgrade-only ingest path.
	 *
	 * The optional `since` lower bound lets the caller sweep each ended session
	 * exactly once (watermark pattern): end_time only ever moves forward while a
	 * session is alive and freezes when it dies, so scanning
	 * [since, stale_before) windows never misses or double-processes a session.
	 *
	 * @param array $args {
	 *     Optional.
	 *     @type int         $limit        Max sessions per batch. Default 100.
	 *     @type int         $window_days  Only sessions started within N days (0 = no window). Default 30.
	 *     @type string|null $stale_before Only sessions ended strictly before this datetime
	 *                                     (current_time('mysql') clock basis). Defaults to now - 30 minutes.
	 *     @type string|null $since        Only sessions ended at/after this datetime (watermark). Default none.
	 *     @type array|null  $cursor       { time, id } lower bound from a prior batch.
	 * }
	 * @return array {
	 *     @type int        $processed Sessions scanned this batch.
	 *     @type int        $changed   Sessions whose classification flipped.
	 *     @type bool       $done      Whether the scan is exhausted.
	 *     @type array|null $cursor    { time, id } to resume the next batch.
	 * }
	 */
	public static function finalize_stale_sessions_batch( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'        => 100,
				'window_days'  => 30,
				'stale_before' => null,
				'since'        => null,
				'cursor'       => null,
			)
		);

		// Feature off: nothing to finalize, reporting includes every session anyway.
		if ( ! self::is_enabled() ) {
			return array( 'processed' => 0, 'changed' => 0, 'done' => true, 'cursor' => null );
		}

		$limit  = max( 1, (int) $args['limit'] );
		$window = max( 0, (int) $args['window_days'] );
		$cursor = is_array( $args['cursor'] ) ? $args['cursor'] : null;

		// Staleness cutoff uses the same current_time('mysql') clock basis that
		// stamps start_time/end_time at ingest, so PHP/DB clock skew cannot make
		// live sessions look stale (or stale sessions look live).
		$stale_before = is_string( $args['stale_before'] ) && '' !== $args['stale_before']
			? $args['stale_before']
			: gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 30 * MINUTE_IN_SECONDS );

		$sessions = $wpdb->prefix . 'optibehavior_sessions';

		// Guard: sessions table missing → nothing to sweep.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before a data-repair scan.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) !== $sessions ) {
			return array( 'processed' => 0, 'changed' => 0, 'done' => true, 'cursor' => null );
		}

		// Unfinalized types only: NULL / '' / the column-default 'human'.
		// spam/bot/automated verdicts are final for this sweep's purposes.
		$where  = "(traffic_type IS NULL OR traffic_type IN ('', 'human'))
			AND COALESCE(end_time, start_time) < %s";
		$params = array( (string) $stale_before );

		if ( is_string( $args['since'] ) && '' !== $args['since'] ) {
			$where   .= ' AND COALESCE(end_time, start_time) >= %s';
			$params[] = (string) $args['since'];
		}

		if ( $window > 0 ) {
			$where   .= ' AND start_time >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - $window * DAY_IN_SECONDS );
		}

		if ( $cursor && isset( $cursor['time'], $cursor['id'] ) ) {
			$where   .= ' AND (start_time < %s OR (start_time = %s AND id < %s))';
			$params[] = (string) $cursor['time'];
			$params[] = (string) $cursor['time'];
			$params[] = (string) $cursor['id'];
		}

		$params[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; the WHERE clause is composed of literal SQL plus %s/%d placeholders bound through $wpdb->prepare.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Data-repair sweep; no caching needed.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, start_time FROM {$sessions} WHERE {$where} ORDER BY start_time DESC, id DESC LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $rows ) ) {
			return array( 'processed' => 0, 'changed' => 0, 'done' => true, 'cursor' => $cursor );
		}

		$changed = 0;
		$last    = $cursor;
		foreach ( $rows as $row ) {
			// Finalizing verdict: the session is over, no more evidence is coming
			// through the normal lifecycle (late events still self-heal downward).
			$res = self::classify_session( $row->id, array( 'allow_spam_verdict' => true ) );
			if ( ! empty( $res['changed'] ) ) {
				++$changed;
			}
			$last = array(
				'time' => (string) $row->start_time,
				'id'   => (string) $row->id,
			);
		}

		$done = count( $rows ) < $limit;

		// classify_session() already clears caches per flip; ensure a final clear
		// on completion so any residual report transients are invalidated.
		if ( $done && $changed > 0 ) {
			self::clear_traffic_classification_caches();
		}

		return array(
			'processed' => count( $rows ),
			'changed'   => $changed,
			'done'      => $done,
			'cursor'    => $last,
		);
	}

	/**
	 * Clear traffic-classification report caches after a spam status change.
	 *
	 * @return void
	 */
	public static function clear_traffic_classification_caches() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk transient invalidation by fixed option-name patterns cannot be expressed via the transient API.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_opti_behavior_traffic_class_%'
			OR option_name LIKE '_transient_timeout_opti_behavior_traffic_class_%'
			OR option_name IN (
				'_transient_opti_behavior_frontend_stats_today',
				'_transient_timeout_opti_behavior_frontend_stats_today',
				'_transient_opti_behavior_frontend_stats_last7days',
				'_transient_timeout_opti_behavior_frontend_stats_last7days',
				'_transient_opti_behavior_frontend_stats_last30days',
				'_transient_timeout_opti_behavior_frontend_stats_last30days'
			)"
		);

		// Bug #3 (Part B) fix: the dashboard's Sessions/Visitors/Page Views KPI
		// cards read from a SEPARATE 900s-TTL transient family
		// (`dashboard_traffic_series`, see get_dashboard_traffic_timeseries() in
		// class-opti-behavior-heatmap-dashboard.php) that embeds the spam-exclusion
		// policy in its cache key but previously had NO invalidation path tied to
		// a session's classification changing — only its own 900s TTL ever expired
		// it. That let a just-corrected session (spam -> human, or vice versa)
		// keep showing stale counts on those KPI cards for up to 15 minutes while
		// the same payload's uncached Avg Session Time / Avg Scroll Depth cards
		// updated on the very next 30s refresh, producing a visibly inconsistent
		// dashboard. Every actual classification change reaches this method, so
		// clearing the dashboard caches here closes the gap for all callers
		// (ajax_heartbeat finalize, handle_heatmap_event self-heal downgrade,
		// reclassify_flagged_sessions_batch()) in one place.
		if ( function_exists( 'opti_behavior_invalidate_dashboard_caches' ) ) {
			opti_behavior_invalidate_dashboard_caches();
		}
	}
}
