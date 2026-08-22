/**
 * Opti-Behavior Anonymous ID Broker (shared, cookieless).
 *
 * Single source of the anonymous visitor id (vid) and 30-minute session id
 * (sid) for every Anonymous-Mode front-end tracker (heatmap reporter, session
 * recorder, funnel tracker, A/B tracker) across BOTH the Free and Pro plugins.
 *
 * Hard constraints (GDPR / UK PECR — see spec §4):
 *   - ZERO cookies written for identity.
 *   - ZERO sessionStorage / localStorage reads or writes.
 *   - Cross-tab continuity is achieved ONLY with an in-memory
 *     `BroadcastChannel('optibehavior_anon')` (runtime messaging, not "storage
 *     of / access to information on terminal equipment"), plus a deterministic
 *     server-derived session seed as the storage-free fallback.
 *
 * Identity resolution (Anonymous Mode) — spec §4.1:
 *   Visitor id : the server daily-rotating hash (`anon_vid`), localized into
 *                page config. JS never mints a random id.
 *   Session id : resolution order —
 *                  1. in-memory current value (this tab already has one),
 *                  2. BroadcastChannel adoption (a live sibling tab replies
 *                     with its sid inside a ~150 ms adoption window),
 *                  3. server-derived deterministic seed
 *                     `sess_<hash>_<floor(nowUTC / 1800000)>` (`anon_sid_seed`).
 *
 * The server seed is CANONICAL (avoids client-clock drift). JS only recomputes
 * the seed as a backup when the server could not resolve the hash and localized
 * an empty `anon_sid_seed` (spec §4.1 / §4.3).
 *
 * Public API — window.OptiBehaviorAnonBroker (relied on by downstream steps):
 *   init( options ) -> broker      Idempotent. options:
 *                                    { vid, sidSeed, hash, channelName }.
 *   getVisitorId() -> string       Current in-memory visitor id.
 *   getSessionId() -> string       Current in-memory session id.
 *   onSessionId( cb )              Subscribe to sid changes (fires when a
 *                                  sibling tab's sid is adopted after init).
 *   isInitialized() -> boolean
 *   cleanupStaleCookies()          Expire legacy optibehavior_anon_sid / _vid
 *                                  cookies (max-age=0). Deleting != storing.
 *   reset()                        Tear down (tests / re-init).
 *
 * Pure helpers are also exported on window.__optiBehaviorAnonBrokerTestables
 * for the Node/jsdom unit harness in tests/.
 *
 * @package Opti_Behavior
 */

(function( global ) {
    'use strict';

    // Milliseconds in a 30-minute UTC bucket — MUST match the server's
    // intdiv( time(), 1800 ) bucket (PHP seconds -> JS ms).
    var BUCKET_MS = 1800000;

    // Adoption window: how long after init this tab will adopt a sibling tab's
    // sid arriving over BroadcastChannel (spec §4.2). Kept short so a lone tab
    // is not blocked and the provisional server seed is used immediately.
    var ADOPTION_WINDOW_MS = 150;

    var DEFAULT_CHANNEL = 'optibehavior_anon';

    // Legacy anon cookies to actively expire on every Anonymous-Mode load
    // (spec §4.5). These are the pre-migration cookie names.
    var STALE_COOKIES = [ 'optibehavior_anon_sid', 'optibehavior_anon_vid' ];

    // ---------------------------------------------------------------------
    // Pure helpers (exported for unit tests — no DOM / channel side effects).
    // ---------------------------------------------------------------------

    /**
     * 30-minute UTC bucket index for a millisecond timestamp.
     *
     * Equivalent to the server's intdiv( time(), 1800 ): PHP works in whole
     * seconds, so floor( ms / 1800000 ) === floor( floor( ms / 1000 ) / 1800 ).
     *
     * @param {number} nowMs Milliseconds since epoch (e.g. Date.now()).
     * @return {number} Bucket index.
     */
    function bucketOf( nowMs ) {
        return Math.floor( nowMs / BUCKET_MS );
    }

    /**
     * Deterministic session seed, identical shape to the server's
     * `sess_<hash>_<utc30minbucket>` (spec §4.1 #3). Used only as the
     * client-side backup when the server localized an empty seed.
     *
     * @param {string} hash  Anonymous hash (server daily-rotating hash).
     * @param {number} nowMs Milliseconds since epoch.
     * @return {string} Seed, or '' when hash is empty (no deterministic id
     *                  possible without an identifier).
     */
    function computeFallbackSeed( hash, nowMs ) {
        if ( ! hash ) {
            return '';
        }
        // Truncate the embedded hash to 40 chars so the full seed
        // ('sess_' + 40 + '_' + ~7-digit bucket ≈ 53 chars) always fits the
        // session_id VARCHAR(64) DB columns. MUST match the server-side
        // truncation (substr( $anon_hash, 0, 40 )) so PHP- and JS-derived
        // seeds are byte-identical for the same visitor and bucket.
        return 'sess_' + String( hash ).slice( 0, 40 ) + '_' + bucketOf( nowMs );
    }

    /**
     * Extract the hash portion from a `sess_<hash>_<bucket>` seed. Tolerant of
     * hashes that themselves contain underscores — only the trailing
     * `_<bucket>` segment is stripped.
     *
     * @param {string} seed Session seed.
     * @return {string} Hash portion, or '' when the seed is not well-formed.
     */
    function hashFromSeed( seed ) {
        if ( typeof seed !== 'string' || seed.indexOf( 'sess_' ) !== 0 ) {
            return '';
        }
        var body = seed.slice( 5 ); // drop leading "sess_"
        var lastUnderscore = body.lastIndexOf( '_' );
        if ( lastUnderscore <= 0 ) {
            return '';
        }
        return body.slice( 0, lastUnderscore );
    }

    // ---------------------------------------------------------------------
    // Broker singleton state (in-memory only — nothing persists).
    // ---------------------------------------------------------------------

    var state = {
        initialized: false,
        vid: '',
        sid: '',
        hash: '',
        channelName: DEFAULT_CHANNEL,
        tabId: '',
        channel: null,
        adoptDeadline: 0, // timestamp until which sibling replies are adopted
        adopted: false,   // a sibling sid was adopted (stop adopting)
        listeners: []     // onSessionId subscribers
    };

    /**
     * Non-persistent per-tab id used only to tag BroadcastChannel messages so a
     * tab can ignore its own broadcasts. In-memory random — never stored.
     *
     * @return {string} Tab id.
     */
    function makeTabId() {
        return 't_' + Math.random().toString( 36 ).slice( 2 ) + '_' + Date.now().toString( 36 );
    }

    /**
     * Set the session id and notify subscribers when it actually changes.
     *
     * @param {string} sid New session id.
     */
    function setSid( sid ) {
        if ( ! sid || sid === state.sid ) {
            return;
        }
        state.sid = sid;
        var subs = state.listeners.slice();
        for ( var i = 0; i < subs.length; i++ ) {
            try {
                subs[ i ]( sid );
            } catch ( e ) {
                // A misbehaving subscriber must not break sid propagation.
            }
        }
    }

    /**
     * Post a message on the channel, swallowing errors (channel may be closing
     * or unavailable). Never throws.
     *
     * @param {Object} msg Message payload.
     */
    function postMessage( msg ) {
        if ( ! state.channel ) {
            return;
        }
        try {
            state.channel.postMessage( msg );
        } catch ( e ) {
            // Channel closed / serialization failure — ignore.
        }
    }

    /**
     * Handle an inbound BroadcastChannel message (spec §4.2 steps 5-6).
     *
     * @param {MessageEvent} event Channel message event.
     */
    function onChannelMessage( event ) {
        var data = event && event.data;
        if ( ! data || typeof data !== 'object' || data.from === state.tabId ) {
            return; // ignore malformed messages and our own echoes
        }

        if ( data.type === 'req_sid' ) {
            // A (re)loading sibling asks for the current sid — answer with ours.
            postMessage( { type: 'sid', from: state.tabId, sid: state.sid } );
            return;
        }

        if ( data.type === 'sid' && data.sid ) {
            // Adopt a sibling's sid only inside the adoption window and only
            // once (converge-on-first-reply). Re-broadcast so every tab still
            // inside its own window converges on the same value.
            if ( ! state.adopted && Date.now() <= state.adoptDeadline && data.sid !== state.sid ) {
                state.adopted = true;
                setSid( data.sid );
                postMessage( { type: 'sid', from: state.tabId, sid: state.sid } );
            }
        }
    }

    /**
     * Feature-detect BroadcastChannel and open the adoption channel. Old Safari
     * (<15.4) has no BroadcastChannel — gracefully skip; the deterministic
     * server seed still gives cross-tab continuity within a 30-min bucket
     * (spec §4.3).
     */
    function openChannel() {
        if ( typeof global.BroadcastChannel !== 'function' ) {
            return; // no channel — deterministic-seed-only mode
        }
        try {
            state.channel = new global.BroadcastChannel( state.channelName );
            state.channel.onmessage = onChannelMessage;
        } catch ( e ) {
            state.channel = null; // treat as unsupported
        }
    }

    /**
     * Start the adoption handshake: open the window, ask siblings for their
     * sid, and answer any inbound requests. Non-blocking — the provisional
     * server-seed sid is already live before this runs.
     */
    function startAdoption() {
        state.adoptDeadline = Date.now() + ADOPTION_WINDOW_MS;
        state.adopted = false;
        openChannel();
        if ( state.channel ) {
            postMessage( { type: 'req_sid', from: state.tabId } );
        }
    }

    // ---------------------------------------------------------------------
    // Public API.
    // ---------------------------------------------------------------------

    /**
     * Initialize the broker (idempotent). Safe to call from every tracker;
     * the first call wins and later calls return the same singleton.
     *
     * @param {Object} options
     *   - vid         {string} Server visitor id (optiBehaviorHeatmapConfig.anon_vid).
     *   - sidSeed     {string} Server session seed (…anon_sid_seed). Canonical.
     *   - hash        {string} Optional explicit anonymous hash for the client
     *                          backup seed (defaults to hash parsed from sidSeed,
     *                          else vid).
     *   - channelName {string} Optional BroadcastChannel name override.
     * @return {Object} The broker (this module).
     */
    function init( options ) {
        if ( state.initialized ) {
            return api;
        }
        options = options || {};

        state.vid = options.vid || '';
        state.channelName = options.channelName || DEFAULT_CHANNEL;
        state.tabId = makeTabId();

        // Resolve the hash used for the client-side backup seed. Prefer an
        // explicit hash, then the hash embedded in the server seed, then the
        // visitor id (a wp_user_<id> or the daily hash). Derived from the seed
        // — never from vid unless nothing else is available (spec context:
        // session seed is server-canonical).
        var seed = options.sidSeed || '';
        state.hash = options.hash || hashFromSeed( seed ) || state.vid || '';

        // Provisional sid: server seed if present, else deterministic client
        // backup (spec §4.1 / §4.3 — used when the server hash was unresolvable
        // and it localized an empty seed).
        if ( ! seed ) {
            seed = computeFallbackSeed( state.hash, Date.now() );
        }
        state.sid = seed || '';

        state.initialized = true;

        startAdoption();
        return api;
    }

    /**
     * @return {string} Current in-memory visitor id.
     */
    function getVisitorId() {
        return state.vid;
    }

    /**
     * @return {string} Current in-memory session id.
     */
    function getSessionId() {
        return state.sid;
    }

    /**
     * Subscribe to session-id changes. Fires when a sibling tab's sid is
     * adopted after init (so already-running trackers pick up the shared sid).
     *
     * @param {Function} cb Callback( sid ).
     */
    function onSessionId( cb ) {
        if ( typeof cb === 'function' ) {
            state.listeners.push( cb );
        }
    }

    /**
     * @return {boolean} Whether init() has run.
     */
    function isInitialized() {
        return state.initialized;
    }

    /**
     * Actively expire legacy anonymous cookies from earlier plugin versions
     * (spec §4.5). Deleting a cookie is not "storing" information, so this is
     * PECR-safe. No-op where document is unavailable.
     */
    function cleanupStaleCookies() {
        if ( typeof global.document === 'undefined' || typeof global.document.cookie !== 'string' ) {
            return;
        }
        for ( var i = 0; i < STALE_COOKIES.length; i++ ) {
            global.document.cookie = STALE_COOKIES[ i ] + '=; path=/; max-age=0; SameSite=Lax';
        }
    }

    /**
     * Tear down the broker (close channel, clear state). Primarily for tests
     * and re-initialization.
     */
    function reset() {
        if ( state.channel ) {
            try {
                state.channel.onmessage = null;
                state.channel.close();
            } catch ( e ) {
                // ignore
            }
        }
        state = {
            initialized: false,
            vid: '',
            sid: '',
            hash: '',
            channelName: DEFAULT_CHANNEL,
            tabId: '',
            channel: null,
            adoptDeadline: 0,
            adopted: false,
            listeners: []
        };
    }

    var api = {
        init: init,
        getVisitorId: getVisitorId,
        getSessionId: getSessionId,
        onSessionId: onSessionId,
        isInitialized: isInitialized,
        cleanupStaleCookies: cleanupStaleCookies,
        reset: reset
    };

    // Expose the broker globally so both Free and Pro trackers share ONE
    // instance (one BroadcastChannel, one converged sid) per page.
    global.OptiBehaviorAnonBroker = api;

    // Pure helpers for the unit-test harness (no side effects).
    global.__optiBehaviorAnonBrokerTestables = {
        bucketOf: bucketOf,
        computeFallbackSeed: computeFallbackSeed,
        hashFromSeed: hashFromSeed,
        BUCKET_MS: BUCKET_MS,
        ADOPTION_WINDOW_MS: ADOPTION_WINDOW_MS,
        DEFAULT_CHANNEL: DEFAULT_CHANNEL
    };

}( typeof window !== 'undefined' ? window : this ));
