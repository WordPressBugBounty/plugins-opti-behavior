/**
 * Opti-Behavior Funnel Tracker
 *
 * Tracks user progression through defined funnels on the frontend
 */

(function() {
    'use strict';

    // -------------------------------------------------------------------------
    // Dead-endpoint / stale-funnel guards — keep the tracker quiet and idle when
    // the server can no longer honour a request that a page-cache plugin baked
    // into stale HTML.
    //
    //  * _deadEndpoint: WordPress core answers an unknown admin-ajax action with
    //    wp_die('0', 400) (HTTP 400 + body exactly "0"). Permanent — stop firing.
    //  * _disabledFunnels: a funnel id that was later deactivated/deleted still
    //    lives in cached HTML; the server replies success:false "Funnel not
    //    found". Silently disable that funnel id for this page load (no retry, no
    //    console error) — per product decision, logging that is noise when the
    //    site simply has no such funnel anymore.
    // -------------------------------------------------------------------------
    var _deadEndpoint = false;
    var _disabledFunnels = {};

    /**
     * True only for WordPress core's "no such action" reply: HTTP 400 + body
     * exactly "0".
     *
     * @param {number} status   HTTP status.
     * @param {string} bodyText Raw response body.
     * @return {boolean}
     */
    function isDeadEndpoint( status, bodyText ) {
        return status === 400 && typeof bodyText === 'string' && bodyText.trim() === '0';
    }

    // -------------------------------------------------------------------------
    // Nonce refresh — one refresh per page load to handle cached-page expiry
    // -------------------------------------------------------------------------
    var _nonceRefreshed = false;

    /**
     * Request fresh nonces from the server (admin-ajax.php is never cached),
     * update the shared config nonce, and invoke retryFn with the fresh value.
     *
     * @param {string}   ajaxUrl  WordPress admin-ajax.php URL from config.
     * @param {Function} retryFn  Called with the fresh nonce_funnels string.
     */
    function refreshNonceAndRetry( ajaxUrl, retryFn ) {
        if ( _nonceRefreshed ) { return; } // Guard: only one refresh per page load
        _nonceRefreshed = true;

        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', ajaxUrl );
        xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
        xhr.onload = function() {
            if ( xhr.status === 200 ) {
                try {
                    var resp = JSON.parse( xhr.responseText );
                    if ( resp.success && resp.data && resp.data.nonce_funnels ) {
                        retryFn( resp.data.nonce_funnels );
                    }
                } catch ( e ) {
                    if ( window.OptiBehaviorDebug ) {
                        window.OptiBehaviorDebug.error( 'Nonce refresh parse error', 'funnel-tracker', e );
                    }
                }
            }
        };
        xhr.send( 'action=opti_behavior_refresh_nonces' );
    }

    // Wait for config variable to be available before dispatching init.
    // JS combine plugins (LiteSpeed Cache, WP Rocket, Autoptimize) may displace
    // the wp_localize_script inline data into a combined bundle that loads AFTER
    // this script. Poll up to 10 seconds; if config is already present the
    // check passes immediately with zero delay.
    (function() {
        var _waited = 0;
        function dispatch() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        }
        function check() {
            if (typeof window.optiBehaviorFunnelTracker !== 'undefined') {
                dispatch();
            } else if (_waited >= 10000) {
                // Config never appeared — optimization plugin may have stripped it
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('optiBehaviorFunnelTracker config not found after 10s', 'funnel-tracker');
            } else {
                _waited += 50;
                setTimeout(check, 50);
            }
        }
        check();
    }());

    function init() {
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Initializing...', 'funnel-tracker');

        // Check if we have the required configuration
        if (typeof window.optiBehaviorFunnelTracker === 'undefined') {
            // console.log('%c[Opti-Behavior Funnel] ❌ Tracker not loaded - No active funnels found', 'color: #ff6b6b; font-weight: bold;');
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Configuration not found', 'funnel-tracker');
            return;
        }

        const config = window.optiBehaviorFunnelTracker;
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Config loaded:', 'funnel-tracker', config);

        // Get session ID from cookie (full-consent mode) or the shared cookieless
        // broker (anonymous mode — in-memory sid, no cookie/sessionStorage).
        const sessionId = getCookie('optibehavior_sid')
            || getCookie('opti_behavior_session_id')
            || (function() {
                try {
                    if (window.OptiBehaviorAnonBroker && window.OptiBehaviorAnonBroker.isInitialized()) {
                        return window.OptiBehaviorAnonBroker.getSessionId() || null;
                    }
                } catch(e) {}
                return null;
            })();

        if (!sessionId) {
            // console.log('%c[Opti-Behavior Funnel] ⏳ Waiting for session to be created...', 'color: #ffa500; font-weight: bold;');
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('No session ID found, waiting for session to be created...', 'funnel-tracker');
            // Retry after 2 seconds (heatmap tracker should create session)
            setTimeout(init, 2000);
            return;
        }

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session ID:', 'funnel-tracker', sessionId);

        // Get current URL
        const currentUrl = window.location.href;
        // console.log('%c[Opti-Behavior Funnel] 🔍 Checking URL:', 'color: #4CAF50; font-weight: bold;', currentUrl);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Current URL:', 'funnel-tracker', currentUrl);

        // Check if current URL matches any funnel steps
        if (config.funnels && config.funnels.length > 0) {
            // console.log('%c[Opti-Behavior Funnel] 📊 Active funnels found:', 'color: #2196F3; font-weight: bold;', config.funnels.length);
            config.funnels.forEach(function(funnel) {
                checkFunnelMatch(funnel, sessionId, currentUrl, config.ajaxUrl, config.nonce);
            });
        } else {
            // console.log('%c[Opti-Behavior Funnel] ⚠️ No active funnels configured', 'color: #ffa500; font-weight: bold;');
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('No active funnels found', 'funnel-tracker');
        }

        // Track download button clicks (for redirecting download pages)
        trackDownloadClicks(config, sessionId);
    }

    /**
     * Check if current URL matches any step in the funnel
     */
    function checkFunnelMatch(funnel, sessionId, currentUrl, ajaxUrl, nonce) {
        // console.log('%c[Opti-Behavior Funnel] 🎯 Checking funnel:', 'color: #9C27B0; font-weight: bold;', funnel.name);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Checking funnel:', 'funnel-tracker', funnel.name);

        let matchedStep = null;
        let matchedStepName = null;

        // Check each step to see if URL matches — take the FIRST match only.
        // Sequential enforcement runs on the server; the client just reports the first hit.
        for (let index = 0; index < funnel.steps.length; index++) {
            const step = funnel.steps[index];
            const stepNumber = index + 1;
            // console.log('%c  Step ' + stepNumber + ':', 'color: #666;', step.name, '| Match Type:', step.match_type, '| Pattern:', step.url_pattern || 'N/A');

            if (urlMatchesPattern(currentUrl, step.url_pattern, step.match_type)) {
                matchedStep = stepNumber;
                matchedStepName = step.name;
                // console.log('%c  ✅ MATCH!', 'color: #4CAF50; font-weight: bold;', 'Step ' + stepNumber + ': ' + step.name);
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('URL matches step ' + stepNumber + ': ' + step.name, 'funnel-tracker');
                break; // Take the first match; sequential enforcement on the server handles the rest.
            } else {
                // console.log('%c  ❌ No match', 'color: #999;');
            }
        }

        if (matchedStep) {
            // console.log('%c[Opti-Behavior Funnel] ✨ Tracking step ' + matchedStep + ' in funnel "' + funnel.name + '"', 'color: #4CAF50; font-weight: bold; font-size: 12px;');
            // Track this funnel step
            trackFunnelStep(funnel.id, sessionId, currentUrl, ajaxUrl, nonce);
        } else {
            // console.log('%c[Opti-Behavior Funnel] ⚪ No matching steps in "' + funnel.name + '"', 'color: #999;');
        }
    }

    /**
     * Normalize a URL or pattern for 'exact' matching.
     *
     * Must stay in sync with the PHP `normalize_url_for_exact()` in
     * class-opti-behavior-funnel-page.php — see its docblock for the full
     * semantics. Summary: fragment never participates; query participates
     * only when the pattern itself contains one (keepQuery); trailing
     * slashes ignored; case-insensitive; scheme discarded; host kept only
     * for absolute URLs.
     *
     * @param {string}  value     URL or pattern.
     * @param {boolean} keepQuery Whether the query string participates.
     * @return {{host: string, path: string}} Normalized components.
     */
    function normalizeUrlForExact(value, keepQuery) {
        if (typeof value !== 'string') {
            value = '';
        }
        // Fragment never participates.
        value = value.split('#')[0];
        // Split off the query string.
        let query = '';
        const queryPos = value.indexOf('?');
        if (queryPos !== -1) {
            if (keepQuery) {
                query = value.substring(queryPos);
            }
            value = value.substring(0, queryPos);
        }
        // Absolute URL: separate host, keep path only. Scheme is discarded.
        let host = '';
        const abs = value.match(/^[a-z][a-z0-9+.-]*:\/\/([^\/]*)(\/.*)?$/i);
        if (abs) {
            host = abs[1].toLowerCase();
            value = abs[2] || '/';
        }
        value = value.toLowerCase();
        if (value === '') { value = '/'; }
        if (value.charAt(0) !== '/') { value = '/' + value; }
        if (value.length > 1) {
            value = value.replace(/\/+$/, '');
            if (value === '') { value = '/'; }
        }
        return { host: host, path: value + query.toLowerCase() };
    }

    /**
     * Check if URL matches the given pattern.
     *
     * Must stay in sync with the PHP `url_matches_pattern()`. Defensive
     * normalization: if matchType is 'any'/'pageview' but a real pattern is
     * provided (non-empty, not '*'), treat it as 'contains'. See the PHP
     * method's docblock for the full rationale.
     */
    function urlMatchesPattern(url, pattern, matchType) {
        if ((matchType === 'any' || matchType === 'pageview')
            && typeof pattern === 'string'
            && pattern !== ''
            && pattern !== '*') {
            matchType = 'contains';
        }

        switch (matchType) {
            case 'any':
                return true;
            case 'exact': {
                // Normalized comparison. Raw string equality could never match
                // the path-only patterns the admin UI stores (e.g. '/checkout/')
                // against the full href this tracker reports — the funnel then
                // silently recorded zero conversions (Bug #1, E2E 2026-07-08).
                const patternStr = (typeof pattern === 'string') ? pattern : '';
                const keepQuery = patternStr.indexOf('?') !== -1;
                const urlNorm = normalizeUrlForExact(url, keepQuery);
                const patternNorm = normalizeUrlForExact(patternStr, keepQuery);
                if (patternNorm.host !== '' && urlNorm.host !== patternNorm.host) {
                    return false;
                }
                return urlNorm.path === patternNorm.path;
            }
            case 'contains':
                return url.toLowerCase().indexOf(pattern.toLowerCase()) !== -1;
            case 'starts_with':
                return url.toLowerCase().indexOf(pattern.toLowerCase()) === 0;
            case 'ends_with':
                const urlLower = url.toLowerCase();
                const patternLower = pattern.toLowerCase();
                return urlLower.substring(urlLower.length - patternLower.length) === patternLower;
            case 'regex':
                try {
                    const regex = new RegExp(pattern);
                    return regex.test(url);
                } catch(e) {
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Invalid regex pattern:', 'funnel-tracker', pattern);
                    return false;
                }
            case 'pageview':
                return true; // Any page view counts
            default:
                return false;
        }
    }

    /**
     * Send tracking data to server
     */
    function trackFunnelStep(funnelId, sessionId, currentUrl, ajaxUrl, nonce) {
        // Endpoint is permanently unavailable this page load (handler unregistered
        // on a cached page) — send nothing.
        if (_deadEndpoint) { return; }
        // This funnel id was already reported "not found" (stale/deleted, still
        // baked into cached HTML) — don't fire again for it this page load.
        if (_disabledFunnels[funnelId]) { return; }

        // console.log('%c[Opti-Behavior Funnel] 📡 Sending tracking data to server...', 'color: #FF9800; font-weight: bold;');
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Tracking funnel step - Funnel ID:', 'funnel-tracker', funnelId);

        const data = new FormData();
        data.append('action', 'optibehavior_track_funnel');
        data.append('funnel_id', funnelId);
        data.append('session_id', sessionId);
        data.append('current_url', currentUrl);
        data.append('nonce', nonce);

        fetch(ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(function(response) {
            // Handle nonce expiry on cached pages: refresh once and retry
            if ( response.status === 403 && !_nonceRefreshed ) {
                if ( window.OptiBehaviorDebug ) {
                    window.OptiBehaviorDebug.debug( 'Nonce expired (403), refreshing and retrying', 'funnel-tracker' );
                }
                refreshNonceAndRetry( ajaxUrl, function( freshNonce ) {
                    nonce = freshNonce; // update local param used by FormData
                    if ( window.optiBehaviorFunnelTracker ) {
                        window.optiBehaviorFunnelTracker.nonce = freshNonce;
                    }
                    trackFunnelStep( funnelId, sessionId, currentUrl, ajaxUrl, freshNonce );
                } );
                return null; // Skip JSON parsing for this expired-nonce response
            }
            // Permanent dead endpoint (handler unregistered on a cached page):
            // HTTP 400 + body exactly "0". Stop firing for this page load, quietly.
            if ( response.status === 400 ) {
                return response.text().then(function(text) {
                    if ( isDeadEndpoint( 400, text ) ) {
                        _deadEndpoint = true;
                        if ( window.OptiBehaviorDebug ) {
                            window.OptiBehaviorDebug.debug( 'Funnel AJAX endpoint unavailable (HTTP 400 "0"); disabling funnel tracking for this page', 'funnel-tracker' );
                        }
                    }
                    return null;
                });
            }
            return response.json();
        })
        .then(function(result) {
            if ( !result ) { return; } // Nonce refresh / dead endpoint handled; skip
            if (result.success) {
                // console.log('%c[Opti-Behavior Funnel] ✅ Tracking successful!', 'color: #4CAF50; font-weight: bold; font-size: 13px;', result.data);
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Tracking successful:', 'funnel-tracker', result.data);
            } else {
                // A stale/deleted funnel id baked into cached HTML: the server
                // replies success:false "Funnel not found". This is expected on a
                // cached page after a funnel was deactivated — silently disable
                // this funnel id (no retry) and log at DEBUG level, not error.
                // Product decision: "why display this log if we don't have a
                // funnel set — not logic".
                var msg = result.data && result.data.message ? result.data.message : result.data;
                if ( typeof msg === 'string' && msg.toLowerCase().indexOf('not found') !== -1 ) {
                    _disabledFunnels[funnelId] = true;
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Funnel not found (stale/deleted id ' + funnelId + '); funnel tracking disabled for this page', 'funnel-tracker');
                    return;
                }
                // console.log('%c[Opti-Behavior Funnel] ❌ Tracking failed:', 'color: #f44336; font-weight: bold;', result.data);
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Tracking failed:', 'funnel-tracker', result.data);
            }
        })
        .catch(function(error) {
            // console.log('%c[Opti-Behavior Funnel] ⚠️ Network error:', 'color: #ff6b6b; font-weight: bold;', error);
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Network error:', 'funnel-tracker', error);
        });
    }

    /**
     * Track clicks on download links (for pages that redirect immediately)
     */
    function trackDownloadClicks(config, sessionId) {
        if (!config.funnels || config.funnels.length === 0) {
            return;
        }

        // Listen for clicks on all links
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a, button');
            if (!link) return;

            // Get the URL this link points to
            var targetUrl = link.href || link.getAttribute('data-href') || '';
            if (!targetUrl) return;

            // console.log('%c[Opti-Behavior Funnel] 🖱️ Link clicked:', 'color: #9E9E9E;', targetUrl);

            // Check if this URL matches any funnel step
            config.funnels.forEach(function(funnel) {
                funnel.steps.forEach(function(step, index) {
                    if (urlMatchesPattern(targetUrl, step.url_pattern, step.match_type)) {
                        // console.log('%c[Opti-Behavior Funnel] 🎯 Download link matches step ' + (index + 1) + '!', 'color: #FF9800; font-weight: bold;');

                        // Track this funnel step immediately (before redirect)
                        trackFunnelStep(funnel.id, sessionId, targetUrl, config.ajaxUrl, config.nonce);
                    }
                });
            });
        }, true); // Use capture phase to catch click before navigation
    }

    /**
     * Get cookie value by name
     */
    function getCookie(name) {
        const value = '; ' + document.cookie;
        const parts = value.split('; ' + name + '=');
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }

    // Test hook — lets QA harnesses exercise the URL matcher without booting
    // the tracker (see tests/funnel-tracker-match-test.js). Not a public API.
    if (typeof window !== 'undefined') {
        window.__optiBehaviorTestables = window.__optiBehaviorTestables || {};
        window.__optiBehaviorTestables.urlMatchesPattern = urlMatchesPattern;
        window.__optiBehaviorTestables.funnelTracker = {
            isDeadEndpoint: isDeadEndpoint,
            trackFunnelStep: trackFunnelStep,
            isDeadEndpointTripped: function() { return _deadEndpoint; },
            isFunnelDisabled: function( id ) { return !!_disabledFunnels[ id ]; }
        };
    }
})();
