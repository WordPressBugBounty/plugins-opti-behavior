/**
 * Opti-Behavior Consent Manager
 *
 * Manages cookie consent for the Opti-Behavior tracking plugin.
 * Loaded only when privacy_mode === 'full'.
 *
 * Supports built-in banner and 8 third-party consent plugins.
 * Fires 'optibehavior:consent_updated' CustomEvent when consent is resolved.
 *
 * @package opti-behavior
 * @since 1.0.3
 */
(function () {
    'use strict';

    var CONSENT_COOKIE   = 'optibehavior_consent';
    var EVENT_NAME       = 'optibehavior:consent_updated';
    // config is resolved lazily in init() to handle JS-optimization plugins
    // (LiteSpeed, WP Rocket, Autoptimize) that may move the wp_localize_script
    // inline data into a combined bundle loaded after this file.
    var config           = {};
    var PENDING_TIMEOUT  = 8000;
    var _pendingTimer    = null;
    var _bannerEl        = null;
    var _overlayEl       = null;
    var _customizeOpen   = false;
    var _resolved        = false;
    var _initialEventFired = false;

    // =========================================================================
    // Cookie helpers
    // =========================================================================

    /**
     * Read a cookie value by name.
     *
     * @param {string} name Cookie name.
     * @return {string|null} Cookie value or null if not found.
     */
    function getCookie( name ) {
        var nameEQ = name + '=';
        var parts  = document.cookie.split( ';' );
        for ( var i = 0; i < parts.length; i++ ) {
            var part = parts[ i ];
            while ( part.charAt( 0 ) === ' ' ) {
                part = part.substring( 1 );
            }
            if ( part.indexOf( nameEQ ) === 0 ) {
                return decodeURIComponent( part.substring( nameEQ.length ) );
            }
        }
        return null;
    }

    /**
     * Set a cookie with SameSite=Lax, path=/, optional Secure flag.
     *
     * @param {string} name  Cookie name.
     * @param {string} value Cookie value.
     * @param {number} days  Expiry in days.
     */
    function setCookie( name, value, days ) {
        var expires = '';
        if ( days ) {
            var d = new Date();
            d.setTime( d.getTime() + days * 24 * 60 * 60 * 1000 );
            expires = '; expires=' + d.toUTCString();
        }
        var secure = ( window.location.protocol === 'https:' ) ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent( value ) + expires +
            '; path=/' + secure + '; SameSite=Lax';
    }

    /**
     * Safely parse a cookie value as JSON.
     *
     * @param {string} name Cookie name.
     * @return {Object|null} Parsed object or null.
     */
    function parseCookieJson( name ) {
        var raw = getCookie( name );
        if ( ! raw ) { return null; }
        try {
            return JSON.parse( raw );
        } catch ( e ) {
            return null;
        }
    }

    // =========================================================================
    // Event dispatch
    // =========================================================================

    /**
     * Fire the consent_updated event and expose state on window.
     *
     * @param {string} status 'granted' or 'rejected'.
     */
    function fireConsentEvent( status ) {
        if ( _resolved ) { return; }
        _resolved = true;

        if ( _pendingTimer ) {
            clearTimeout( _pendingTimer );
            _pendingTimer = null;
        }

        window.optiBehaviorConsentState = status;

        var evt;
        if ( typeof CustomEvent === 'function' ) {
            evt = new CustomEvent( EVENT_NAME, { detail: { status: status }, bubbles: false } );
        } else {
            evt = document.createEvent( 'CustomEvent' );
            evt.initCustomEvent( EVENT_NAME, false, false, { status: status } );
        }
        document.dispatchEvent( evt );

        if ( window.OptiBehaviorDebug ) {
            window.OptiBehaviorDebug.debug( 'Consent event fired: ' + status, 'consent-manager' );
        }
    }

    /**
     * Fire an initial 'rejected' event to start anonymous tracking immediately.
     * Does NOT set _resolved = true, so the real consent event (granted/rejected)
     * can still fire later when the visitor interacts with the banner.
     *
     * GDPR Recital 26: anonymous data (no cookies, no IP, no PII) does not
     * require consent, so we can start collecting it right away.
     */
    function fireInitialAnonymous() {
        if ( _initialEventFired ) { return; }
        _initialEventFired = true;

        window.optiBehaviorConsentState = 'rejected';

        var evt;
        if ( typeof CustomEvent === 'function' ) {
            evt = new CustomEvent( EVENT_NAME, { detail: { status: 'rejected' }, bubbles: false } );
        } else {
            evt = document.createEvent( 'CustomEvent' );
            evt.initCustomEvent( EVENT_NAME, false, false, { status: 'rejected' } );
        }
        document.dispatchEvent( evt );

        if ( window.OptiBehaviorDebug ) {
            window.OptiBehaviorDebug.debug( 'Initial anonymous event fired - tracking starts immediately', 'consent-manager' );
        }
    }

    // =========================================================================
    // Consent actions
    // =========================================================================

    /**
     * Lifetime of the visitor's choice, in days (site setting; CNIL good
     * practice is 6 months).
     *
     * @return {number}
     */
    function consentDays() {
        var days = parseInt( config.consent_cookie_days, 10 );
        return days > 0 ? days : 180;
    }

    /**
     * Expire a cookie on every path / domain scope the plugin may have used
     * (JS trackers write on "/", the server-side A/B bucketer on COOKIEPATH).
     *
     * @param {string} name Cookie name.
     */
    function expireCookie( name ) {
        var secure  = window.location.protocol === 'https:' ? '; Secure' : '';
        var paths   = [ '/' ];
        var domains = [ '' ];
        if ( config.cookie_path && config.cookie_path !== '/' ) { paths.push( config.cookie_path ); }
        if ( config.cookie_domain ) { domains.push( config.cookie_domain ); }

        for ( var p = 0; p < paths.length; p++ ) {
            for ( var d = 0; d < domains.length; d++ ) {
                document.cookie = name + '=; path=' + paths[ p ] +
                    ( domains[ d ] ? '; domain=' + domains[ d ] : '' ) +
                    '; max-age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax' + secure;
            }
        }
    }

    /**
     * Remove every identifier the full-tracking mode stored on this device.
     *
     * Runs when consent is withdrawn and on every page load while the choice
     * is "rejected", so an identifier re-written by a tracker during the
     * withdrawal reload never survives. Deleting is not "storing" information
     * (PECR-safe). Cookieless Anonymous tracking keeps no client-side state, so
     * nothing it relies on is touched.
     *
     * @param {boolean} deep Also sweep the Pro recorder's local buffers.
     */
    function purgeTrackingStorage( deep ) {
        var cookieNames = [
            'optibehavior_sid', 'optibehavior_vid',
            'opti_behavior_session_id', 'opti_behavior_visitor_id', 'opti_behavior_vid',
            'opti_ab_v'
        ];
        var parts = document.cookie ? document.cookie.split( ';' ) : [];
        for ( var i = 0; i < parts.length; i++ ) {
            var cname = parts[ i ].split( '=' )[ 0 ].replace( /^\s+/, '' );
            if ( /^opti_ab_\d+$/.test( cname ) ) { cookieNames.push( cname ); }
        }
        for ( var c = 0; c < cookieNames.length; c++ ) {
            if ( getCookie( cookieNames[ c ] ) !== null ) { expireCookie( cookieNames[ c ] ); }
        }

        var keys   = [ 'optibehavior_vid', 'optibehavior_last_activity', 'optibehavior_session_start', 'opti_behavior_pro_session_data' ];
        var stores = [];
        try { stores.push( window.localStorage ); } catch ( e ) { /* storage blocked */ }
        try { stores.push( window.sessionStorage ); } catch ( e2 ) { /* storage blocked */ }

        for ( var s = 0; s < stores.length; s++ ) {
            var store = stores[ s ];
            if ( ! store ) { continue; }
            try {
                for ( var k = 0; k < keys.length; k++ ) { store.removeItem( keys[ k ] ); }
                if ( deep ) {
                    for ( var n = store.length - 1; n >= 0; n-- ) {
                        var key = store.key( n );
                        if ( key && key.indexOf( 'opti_behavior_pro_' ) === 0 ) { store.removeItem( key ); }
                    }
                }
            } catch ( e3 ) { /* storage blocked */ }
        }
    }

    /**
     * Record the visitor's decision: set cookie, fire event, hide banner.
     *
     * Also handles a CHANGED decision (banner reopened, preference centre,
     * third-party banner): the consent event fires again, and withdrawing a
     * previously granted consent wipes the identifiers and reloads the page so
     * every tracker restarts in cookieless anonymous mode.
     *
     * @param {string} status 'granted' or 'rejected'.
     */
    function applyConsent( status ) {
        var previous = getCookie( CONSENT_COOKIE );

        setCookie( CONSENT_COOKIE, status, consentDays() );
        hideBanner();

        if ( previous && previous !== status ) {
            _resolved = false;
        }
        fireConsentEvent( status );
        refreshPreferenceCards();

        if ( previous === 'granted' && status === 'rejected' ) {
            purgeTrackingStorage( true );
            window.location.reload();
        }
    }

    /**
     * Close button / Escape. First visit: closing without choosing = refusal.
     * Reopened banner ("manage my cookies"): closing keeps the stored choice —
     * it must never silently withdraw a consent the visitor already gave.
     */
    function dismissBanner() {
        if ( getStoredChoice() === 'pending' ) {
            rejectConsent();
        } else {
            hideBanner();
        }
    }

    /**
     * Grant consent.
     */
    function grantConsent() {
        applyConsent( 'granted' );
    }

    /**
     * Reject (or withdraw) consent.
     */
    function rejectConsent() {
        applyConsent( 'rejected' );
    }

    // =========================================================================
    // Preference centre ([opti_behavior_cookie_settings] shortcode)
    // =========================================================================

    /**
     * Visitor's stored choice.
     *
     * @return {string} 'granted' | 'rejected' | 'pending'.
     */
    function getStoredChoice() {
        var value = getCookie( CONSENT_COOKIE );
        return ( value === 'granted' || value === 'rejected' ) ? value : 'pending';
    }

    /**
     * Sync every preference card on the page with the stored choice.
     *
     * @param {string=} feedbackKey data-label-* key to announce (e.g. 'saved').
     */
    function refreshPreferenceCards( feedbackKey ) {
        var cards = document.querySelectorAll( '[data-ob-cookie-prefs]' );
        var choice = getStoredChoice();

        for ( var i = 0; i < cards.length; i++ ) {
            var card     = cards[ i ];
            var status   = card.querySelector( '[data-ob-status]' );
            var checkbox = card.querySelector( '[data-ob-analytics]' );
            var feedback = card.querySelector( '[data-ob-feedback]' );

            if ( status ) {
                status.className   = 'ob-cookie-prefs__status ob-cookie-prefs__status--' + choice;
                status.textContent = card.getAttribute( 'data-label-' + choice ) || choice;
            }
            if ( checkbox ) {
                checkbox.checked = ( choice === 'granted' );
            }
            if ( feedback && feedbackKey ) {
                feedback.textContent = card.getAttribute( 'data-label-' + feedbackKey ) || '';
            }
        }
    }

    /**
     * Wire the preference cards and the "manage my cookies" triggers.
     */
    function initPreferenceCards() {
        var cards = document.querySelectorAll( '[data-ob-cookie-prefs]' );

        for ( var i = 0; i < cards.length; i++ ) {
            ( function ( card ) {
                if ( card.getAttribute( 'data-ob-ready' ) ) { return; }
                card.setAttribute( 'data-ob-ready', '1' );

                var inactive  = card.querySelector( '[data-ob-inactive]' );
                var adminNote = card.querySelector( '[data-ob-admin-note]' );
                var checkbox  = card.querySelector( '[data-ob-analytics]' );
                var controls  = card.querySelectorAll( '[data-ob-action], [data-ob-analytics]' );

                if ( inactive ) { inactive.hidden = true; }

                // Administrators are always auto-granted: show why, keep it read-only.
                if ( config.is_admin_user ) {
                    if ( adminNote ) { adminNote.hidden = false; }
                    return;
                }

                for ( var c = 0; c < controls.length; c++ ) { controls[ c ].disabled = false; }

                card.addEventListener( 'click', function ( e ) {
                    var target = e.target;
                    while ( target && target !== card && ! ( target.getAttribute && target.getAttribute( 'data-ob-action' ) ) ) {
                        target = target.parentNode;
                    }
                    if ( ! target || target === card ) { return; }

                    var action = target.getAttribute( 'data-ob-action' );
                    var grant  = action === 'accept' || ( action === 'save' && checkbox && checkbox.checked );

                    if ( grant ) { grantConsent(); } else { rejectConsent(); }
                    refreshPreferenceCards( 'saved' );
                } );
            }( cards[ i ] ) );
        }

        refreshPreferenceCards();

        if ( ! document.documentElement.getAttribute( 'data-ob-consent-triggers' ) ) {
            document.documentElement.setAttribute( 'data-ob-consent-triggers', '1' );
            document.addEventListener( 'click', function ( e ) {
                var el = e.target;
                while ( el && el !== document ) {
                    if ( el.getAttribute && ( el.getAttribute( 'data-ob-consent-open' ) ||
                        ( el.classList && el.classList.contains( 'ob-consent-open' ) ) ) ) {
                        e.preventDefault();
                        openPreferences();
                        return;
                    }
                    el = el.parentNode;
                }
            } );
        }
    }

    /**
     * Let the visitor review their choice: reopen the built-in banner, or
     * scroll to a preference card when the banner is not ours to show.
     */
    function openPreferences() {
        if ( config.show_builtin_banner && ! config.is_admin_user ) {
            _customizeOpen = false;
            showBanner();
            return;
        }
        var card = document.querySelector( '[data-ob-cookie-prefs]' );
        if ( card && card.scrollIntoView ) {
            card.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        }
    }

    // Public API for themes / custom links: OptiBehaviorConsent.open().
    window.OptiBehaviorConsent = {
        open:      openPreferences,
        accept:    function () { grantConsent(); },
        reject:    function () { rejectConsent(); },
        getStatus: getStoredChoice
    };

    // =========================================================================
    // Third-party integrations
    // =========================================================================

    /**
     * CookieBot integration.
     * Events: CookiebotOnAccept, CookiebotOnDecline, CookiebotOnConsentReady
     */
    function integrateCookiebot() {
        function checkCookiebot() {
            if ( window.Cookiebot && window.Cookiebot.consent ) {
                if ( window.Cookiebot.consent.statistics ) {
                    grantConsent();
                } else if ( window.Cookiebot.consent.stamp === '0' ) {
                    // Consent explicitly declined
                    rejectConsent();
                }
            }
        }

        window.addEventListener( 'CookiebotOnAccept', function () {
            if ( window.Cookiebot && window.Cookiebot.consent && window.Cookiebot.consent.statistics ) {
                grantConsent();
            } else {
                rejectConsent();
            }
        } );
        window.addEventListener( 'CookiebotOnDecline', function () {
            rejectConsent();
        } );
        window.addEventListener( 'CookiebotOnConsentReady', function () {
            checkCookiebot();
        } );

        // Immediate check in case Cookiebot already fired
        checkCookiebot();
    }

    /**
     * Complianz integration.
     * Event: cmplz_event (type=analytics)
     */
    function integrateComplianz() {
        function checkComplianz() {
            if ( typeof window.cmplz_get_cookie_category === 'function' ) {
                var status = window.cmplz_get_cookie_category( 'analytics' );
                if ( status === 'allow' ) {
                    grantConsent();
                    return true;
                } else if ( status === 'deny' ) {
                    rejectConsent();
                    return true;
                }
            }
            return false;
        }

        document.addEventListener( 'cmplz_event', function ( e ) {
            // Always delegate to checkComplianz() which reads actual consent state.
            // Do NOT call grantConsent() directly here — the event fires on both
            // grant AND deny, so we must re-read the real state each time.
            checkComplianz();
        } );

        checkComplianz();
    }

    /**
     * CookieYes integration.
     * Event: cookieyes_consent_update
     */
    function integrateCookieYes() {
        function checkCookieYes() {
            if ( window.CookieLawInfo ) {
                var val = window.CookieLawInfo.readCookie( 'cookielawinfo-checkbox-analytics' );
                if ( val === 'yes' ) { grantConsent(); return true; }
                if ( val === 'no' ) { rejectConsent(); return true; }
            }
            if ( window.CookieYes ) {
                var state = window.CookieYes.getConsentFor && window.CookieYes.getConsentFor( 'analytics' );
                if ( state === true ) { grantConsent(); return true; }
                if ( state === false ) { rejectConsent(); return true; }
            }
            return false;
        }

        document.addEventListener( 'cookieyes_consent_update', function ( e ) {
            checkCookieYes();
        } );

        checkCookieYes();
    }

    /**
     * Moove GDPR Cookie Compliance integration (cookie-based only).
     * Cookie: moove_gdpr_popup — JSON with analytics_cookies
     */
    function integrateMoove() {
        var data = parseCookieJson( 'moove_gdpr_popup' );
        if ( data ) {
            if ( data.analytics_cookies === '1' || data.analytics_cookies === 1 ) {
                grantConsent();
            } else {
                rejectConsent();
            }
        }
        // No event-based API — cookie-only plugin
    }

    /**
     * Cookie Notice by TFC integration.
     * Globals: window.cn
     */
    function integrateCookieNotice() {
        function checkCN() {
            if ( window.cn ) {
                if ( typeof window.cn.getCategorized === 'function' ) {
                    var cats = window.cn.getCategorized();
                    if ( cats && cats.analytics ) { grantConsent(); return true; }
                }
            }
            return false;
        }

        document.addEventListener( 'cn.userAccepted', function () {
            grantConsent();
        } );
        document.addEventListener( 'cn.userDeclined', function () {
            rejectConsent();
        } );

        checkCN();
    }

    /**
     * Real Cookie Banner integration.
     * Event: RCBConsentSaved
     */
    function integrateRealCookieBanner() {
        function checkRCB() {
            // Try reading the RCB consent cookie (group UUID varies, so look for analytics in all rcb-consent-* cookies)
            var allCookies = document.cookie.split( ';' );
            for ( var i = 0; i < allCookies.length; i++ ) {
                var c = allCookies[ i ].trim();
                if ( c.indexOf( 'rcb-consent-' ) === 0 ) {
                    var jsonStr = decodeURIComponent( c.split( '=' ).slice( 1 ).join( '=' ) );
                    try {
                        var parsed = JSON.parse( jsonStr );
                        if ( parsed && parsed.statistics !== undefined ) {
                            if ( parsed.statistics ) { grantConsent(); return true; }
                            else { rejectConsent(); return true; }
                        }
                        if ( parsed && parsed.analytics !== undefined ) {
                            if ( parsed.analytics ) { grantConsent(); return true; }
                            else { rejectConsent(); return true; }
                        }
                    } catch ( e ) { /* ignore */ }
                }
            }
            return false;
        }

        document.addEventListener( 'RCBConsentSaved', function () {
            checkRCB();
        } );

        checkRCB();
    }

    /**
     * Borlabs Cookie integration.
     * Event: Borlabs:consentChanged
     */
    function integrateBorlabs() {
        function checkBorlabs() {
            if ( window.BorlabsCookie ) {
                if ( typeof window.BorlabsCookie.checkCookieConsent === 'function' ) {
                    var allowed = window.BorlabsCookie.checkCookieConsent( 'analyticsCategory' ) ||
                                  window.BorlabsCookie.checkCookieConsent( 'statistics' ) ||
                                  window.BorlabsCookie.checkCookieConsent( 'analytics' );
                    if ( allowed ) { grantConsent(); return true; }
                    rejectConsent(); return true;
                }
            }
            return false;
        }

        document.addEventListener( 'Borlabs:consentChanged', function () {
            checkBorlabs();
        } );

        window.addEventListener( 'Borlabs:consentChanged', function () {
            checkBorlabs();
        } );

        checkBorlabs();
    }

    /**
     * WP DSGVO Tools integration (cookie-based only).
     * Cookie: borlabs-cookie — JSON with consents
     */
    function integrateWpDsgvo() {
        var data = parseCookieJson( 'borlabs-cookie' );
        if ( data ) {
            var consents = data.consents || data.cookies || {};
            var analytics = consents.analytics || consents.statistics || false;
            if ( analytics ) {
                grantConsent();
            } else {
                rejectConsent();
            }
        }
        // No event-based API — cookie-only plugin
    }

    /** Map of detected plugin slug → integration function */
    var integrations = {
        'cookiebot':          integrateCookiebot,
        'complianz':          integrateComplianz,
        'cookieyes':          integrateCookieYes,
        'moove':              integrateMoove,
        'cookie-notice':      integrateCookieNotice,
        'real-cookie-banner': integrateRealCookieBanner,
        'borlabs':            integrateBorlabs,
        'wp-dsgvo':           integrateWpDsgvo,
    };

    // =========================================================================
    // Built-in banner — DOM rendering
    // =========================================================================

    /**
     * Build the banner HTML element.
     *
     * @return {HTMLElement} The banner element (not yet in DOM).
     */
    function buildBanner() {
        var position    = config.banner_position || 'bottom-bar';
        var accentColor = config.banner_accent_color || '#6c5ce7';
        var iconUrl     = config.banner_icon_url || '';
        var poweredLabel = config.banner_powered_by_label || '';
        var poweredUrl   = config.banner_powered_by_url || '';
        var bgColor     = config.banner_bg_color || '#ffffff';
        var textColor   = config.banner_text_color || '#333333';
        var title       = config.banner_title || 'We value your privacy';
        var message     = config.banner_message ||
            'We use analytics cookies to understand how you interact with our website. ' +
            'This helps us improve our content and user experience.';
        var acceptLabel     = config.banner_accept_label || 'Accept All';
        var rejectLabel     = config.banner_reject_label || 'Reject All';
        var customizeLabel  = config.banner_customize_label || 'Customize';
        var closeLabel      = config.banner_close_label || 'Close privacy banner';
        var policyUrl       = config.banner_policy_url || '';
        var policyLabel     = config.banner_policy_label || 'Cookie Policy';
        var isCompactCard   = ( position === 'compact-card' );

        // Banner wrapper
        var banner = document.createElement( 'div' );
        banner.id        = 'opti-behavior-consent-banner';
        banner.className = 'ob-consent-banner ob-consent-banner--' + position + ' ob-consent-banner--hidden';
        banner.setAttribute( 'role', 'dialog' );
        banner.setAttribute( 'aria-modal', 'true' );
        banner.setAttribute( 'aria-label', title );
        banner.setAttribute( 'tabindex', '-1' );

        // Apply CSS variables inline so they work before the CSS file is parsed
        banner.style.setProperty( '--ob-consent-accent', accentColor );
        banner.style.setProperty( '--ob-consent-bg', bgColor );
        banner.style.setProperty( '--ob-consent-text', textColor );

        if ( isCompactCard ) {
            var closeBtn = document.createElement( 'button' );
            closeBtn.type      = 'button';
            closeBtn.className = 'ob-consent-banner__close';
            closeBtn.innerHTML = '&times;';
            closeBtn.setAttribute( 'aria-label', closeLabel );
            closeBtn.addEventListener( 'click', function () { dismissBanner(); } );
            banner.appendChild( closeBtn );
        }

        // Inner container
        var inner = document.createElement( 'div' );
        inner.className = 'ob-consent-banner__inner';

        // Content area
        var content = document.createElement( 'div' );
        content.className = 'ob-consent-banner__content';

        var titleEl = document.createElement( 'p' );
        titleEl.className = 'ob-consent-banner__title';
        titleEl.textContent = title;

        var msgEl = document.createElement( 'p' );
        msgEl.className = 'ob-consent-banner__message';
        msgEl.textContent = message;

        if ( policyUrl ) {
            msgEl.appendChild( document.createTextNode( ' ' ) );

            var policyLink = document.createElement( 'a' );
            policyLink.className = 'ob-consent-banner__policy-link';
            policyLink.href = policyUrl;
            policyLink.target = '_blank';
            policyLink.rel = 'noopener noreferrer';
            policyLink.textContent = policyLabel;
            msgEl.appendChild( policyLink );
        }

        if ( iconUrl ) {
            var header = document.createElement( 'div' );
            header.className = 'ob-consent-banner__header';

            var iconEl = document.createElement( 'img' );
            iconEl.className = 'ob-consent-banner__icon';
            iconEl.src       = iconUrl;
            iconEl.alt       = '';
            iconEl.width     = 32;
            iconEl.height    = 32;
            iconEl.setAttribute( 'aria-hidden', 'true' );
            iconEl.addEventListener( 'error', function () { iconEl.parentNode && iconEl.parentNode.removeChild( iconEl ); } );

            header.appendChild( iconEl );
            header.appendChild( titleEl );
            content.appendChild( header );
        } else {
            content.appendChild( titleEl );
        }
        content.appendChild( msgEl );

        // Customize panel (initially hidden)
        var customizePanel = buildCustomizePanel( accentColor );

        // Buttons
        var btnRow = document.createElement( 'div' );
        btnRow.className = 'ob-consent-banner__buttons';

        var btnAccept = document.createElement( 'button' );
        btnAccept.type      = 'button';
        btnAccept.className = 'ob-consent-banner__btn ob-consent-banner__btn--accept';
        btnAccept.textContent = acceptLabel;
        btnAccept.addEventListener( 'click', function () { grantConsent(); } );

        var btnReject = document.createElement( 'button' );
        btnReject.type      = 'button';
        btnReject.className = 'ob-consent-banner__btn ob-consent-banner__btn--reject';
        btnReject.textContent = rejectLabel;
        btnReject.addEventListener( 'click', function () { rejectConsent(); } );

        var btnCustomize = document.createElement( 'button' );
        btnCustomize.type      = 'button';
        btnCustomize.className = 'ob-consent-banner__btn ob-consent-banner__btn--customize';
        btnCustomize.textContent = customizeLabel;
        btnCustomize.setAttribute( 'aria-expanded', 'false' );
        btnCustomize.addEventListener( 'click', function () {
            _customizeOpen = ! _customizeOpen;
            if ( _customizeOpen ) {
                customizePanel.classList.add( 'ob-consent-customize--open' );
            } else {
                customizePanel.classList.remove( 'ob-consent-customize--open' );
            }
            btnCustomize.setAttribute( 'aria-expanded', _customizeOpen ? 'true' : 'false' );
        } );

        if ( isCompactCard ) {
            btnRow.appendChild( btnCustomize );
            btnRow.appendChild( btnReject );
            btnRow.appendChild( btnAccept );
        } else {
            btnRow.appendChild( btnAccept );
            btnRow.appendChild( btnReject );
            btnRow.appendChild( btnCustomize );
        }

        inner.appendChild( content );
        inner.appendChild( customizePanel );
        inner.appendChild( btnRow );

        // "Powered by" credit — only present when branding is enabled (Pro).
        if ( poweredLabel && poweredUrl ) {
            var powered = document.createElement( 'a' );
            powered.className   = 'ob-consent-banner__powered';
            powered.href        = poweredUrl;
            powered.target      = '_blank';
            powered.rel         = 'nofollow noopener noreferrer';
            powered.textContent = poweredLabel;
            // Bars lay out side by side on desktop: keep the credit under the message.
            ( position === 'bottom-bar' || position === 'top-bar' ? content : inner ).appendChild( powered );
        }

        banner.appendChild( inner );

        // Keyboard: Escape = reject
        banner.addEventListener( 'keydown', function ( e ) {
            var key = e.key || e.keyCode;
            if ( key === 'Escape' || key === 27 ) {
                dismissBanner();
            }
            // Basic focus trap
            trapFocus( banner, e );
        } );

        return banner;
    }

    /**
     * Build the customize panel inside the banner.
     *
     * @param {string} accentColor Accent color for toggle styling.
     * @return {HTMLElement} Customize panel element.
     */
    function buildCustomizePanel( accentColor ) {
        var panel = document.createElement( 'div' );
        panel.className   = 'ob-consent-customize';
        // Visibility controlled via CSS class for accordion animation

        var analyticsRow = document.createElement( 'div' );
        analyticsRow.className = 'ob-consent-customize__row';

        var labelWrap = document.createElement( 'label' );
        labelWrap.className   = 'ob-consent-customize__label';
        labelWrap.htmlFor     = 'ob-consent-analytics-toggle';

        var toggleSpan = document.createElement( 'span' );
        toggleSpan.className = 'ob-consent-customize__toggle';

        var checkbox = document.createElement( 'input' );
        checkbox.type    = 'checkbox';
        checkbox.id      = 'ob-consent-analytics-toggle';
        checkbox.className = 'ob-consent-customize__checkbox';
        checkbox.setAttribute( 'aria-label', config.banner_analytics_aria || 'Allow analytics cookies' );
        // Reopened banner ("manage my cookies"): reflect the stored choice.
        checkbox.checked = ( getCookie( CONSENT_COOKIE ) === 'granted' );

        var slider = document.createElement( 'span' );
        slider.className = 'ob-consent-customize__slider';
        slider.setAttribute( 'aria-hidden', 'true' );

        toggleSpan.appendChild( checkbox );
        toggleSpan.appendChild( slider );

        var labelText = document.createElement( 'span' );
        labelText.className = 'ob-consent-customize__label-text';
        labelText.textContent = config.banner_analytics_label || 'Analytics cookies';

        labelWrap.appendChild( toggleSpan );
        labelWrap.appendChild( labelText );

        var descEl = document.createElement( 'p' );
        descEl.className  = 'ob-consent-customize__desc';
        descEl.textContent = config.banner_analytics_description ||
            'Help us understand how visitors interact with our site by collecting anonymous usage data.';

        var saveBtn = document.createElement( 'button' );
        saveBtn.type      = 'button';
        saveBtn.className = 'ob-consent-banner__btn ob-consent-banner__btn--save-choice';
        saveBtn.textContent = config.banner_save_label || 'Save my choice';
        saveBtn.addEventListener( 'click', function () {
            if ( checkbox.checked ) {
                grantConsent();
            } else {
                rejectConsent();
            }
        } );

        analyticsRow.appendChild( labelWrap );
        analyticsRow.appendChild( descEl );
        panel.appendChild( analyticsRow );
        panel.appendChild( saveBtn );

        return panel;
    }

    /**
     * Trap keyboard focus inside the banner dialog.
     *
     * @param {HTMLElement} container The dialog container.
     * @param {KeyboardEvent} e       The keydown event.
     */
    function trapFocus( container, e ) {
        var key = e.key || e.keyCode;
        if ( key !== 'Tab' && key !== 9 ) { return; }

        var focusable = container.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if ( ! focusable.length ) { return; }

        var first = focusable[ 0 ];
        var last  = focusable[ focusable.length - 1 ];

        if ( e.shiftKey ) {
            if ( document.activeElement === first ) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if ( document.activeElement === last ) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    /**
     * Show the built-in consent banner.
     */
    function showBanner() {
        var position = config.banner_position || 'bottom-bar';

        // Overlay for popup mode
        if ( position === 'popup' && ! _overlayEl ) {
            _overlayEl = document.createElement( 'div' );
            _overlayEl.id        = 'opti-behavior-consent-overlay';
            _overlayEl.className = 'ob-consent-overlay';
            document.body.appendChild( _overlayEl );
        }

        if ( ! _bannerEl ) {
            _bannerEl = buildBanner();
            document.body.appendChild( _bannerEl );
        }

        // Trigger transition: next tick removes hidden class.
        // Capture a local ref: hideBanner() may null out _bannerEl (and start
        // removing the node) before this double-rAF fires — a fast consent action
        // or re-entrant show/hide. Dereferencing the shared _bannerEl then throws
        // "Cannot read properties of null (reading 'classList')". Guard on the
        // local ref + isConnected so a superseded animation is a no-op. Mirrors
        // the local-ref pattern in hideBanner().
        var bannerEl  = _bannerEl;
        var overlayEl = _overlayEl;
        requestAnimationFrame( function () {
            requestAnimationFrame( function () {
                if ( ! bannerEl || ! bannerEl.isConnected ) { return; }
                bannerEl.classList.remove( 'ob-consent-banner--hidden' );
                bannerEl.classList.add( 'ob-consent-banner--visible' );
                if ( overlayEl && overlayEl.isConnected ) {
                    overlayEl.classList.add( 'ob-consent-overlay--visible' );
                }
                // Move focus into the dialog
                bannerEl.focus();
            } );
        } );
    }

    /**
     * Hide and remove the built-in consent banner.
     */
    function hideBanner() {
        if ( _bannerEl ) {
            _bannerEl.classList.remove( 'ob-consent-banner--visible' );
            _bannerEl.classList.add( 'ob-consent-banner--hidden' );

            var el = _bannerEl;
            setTimeout( function () {
                if ( el && el.parentNode ) {
                    el.parentNode.removeChild( el );
                }
            }, 400 );

            _bannerEl = null;
        }

        if ( _overlayEl ) {
            _overlayEl.classList.remove( 'ob-consent-overlay--visible' );
            var ov = _overlayEl;
            setTimeout( function () {
                if ( ov && ov.parentNode ) {
                    ov.parentNode.removeChild( ov );
                }
            }, 400 );
            _overlayEl = null;
        }
    }

    // =========================================================================
    // Pending timeout fallback
    // =========================================================================

    /**
     * Start the pending-consent timeout. After PENDING_TIMEOUT ms with no
     * resolution, fall back silently to anonymous mode (reject).
     */
    function startPendingTimeout() {
        _pendingTimer = setTimeout( function () {
            if ( ! _resolved ) {
                if ( window.OptiBehaviorDebug ) {
                    window.OptiBehaviorDebug.debug(
                        'Consent pending timeout reached — falling back to anonymous mode',
                        'consent-manager'
                    );
                }
                rejectConsent();
            }
        }, PENDING_TIMEOUT );
    }

    // =========================================================================
    // Main init
    // =========================================================================

    /**
     * Run the consent logic once config is resolved.
     * Separated from the polling wrapper so logic is easy to read.
     */
    function run() {
        if ( window.OptiBehaviorDebug ) {
            window.OptiBehaviorDebug.debug( 'Consent manager run', 'consent-manager', config );
        }

        // Update the module-level PENDING_TIMEOUT from resolved config.
        PENDING_TIMEOUT = parseInt( config.pending_timeout, 10 ) || 8000;

        // Preference centre ([opti_behavior_cookie_settings]) + "manage my
        // cookies" triggers work in every consent state.
        initPreferenceCards();

        // 0. Admin users: auto-grant consent without showing banner.
        //    Admins manage the site — showing them a consent banner is not logical.
        //    NOTE: We set the cookie and mark resolved, but do NOT dispatch the
        //    consent_updated event. The PHP inline script already set
        //    window.optiBehaviorConsentState = 'granted' before the tracker JS
        //    ran, so the tracker took the 'granted' path directly. Dispatching
        //    the event here would trigger the tracker's reload listener and
        //    cause an infinite reload loop.
        if ( config.is_admin_user ) {
            setCookie( CONSENT_COOKIE, 'granted', consentDays() );
            window.optiBehaviorConsentState = 'granted';
            _resolved = true;
            refreshPreferenceCards();
            return;
        }

        // 1. Check existing consent cookie → fire event immediately
        var existing = getCookie( CONSENT_COOKIE );
        if ( existing === 'granted' || existing === 'rejected' ) {
            // Refused / withdrawn: no full-tracking identifier may linger. Deep
            // sweep: the recorder re-writes session-keyed markers while the
            // withdrawal reload unloads the page, and once consent is refused
            // it runs on in-memory storage only, so nothing it needs is lost.
            if ( existing === 'rejected' ) {
                purgeTrackingStorage( true );
            }
            window.optiBehaviorConsentState = existing;
            fireConsentEvent( existing );
            return;
        }

        // 2. No existing cookie: start anonymous tracking IMMEDIATELY
        //    GDPR Recital 26: anonymous data does not require consent.
        //    The tracker/recorder will start in anonymous mode right away.
        //    If the visitor later accepts, the page reloads for full tracking.
        fireInitialAnonymous();

        // 3. Third-party plugin integration (banner handled by them)
        var detectedPlugin = config.detected_plugin || null;
        if ( detectedPlugin && integrations[ detectedPlugin ] ) {
            integrations[ detectedPlugin ]();
            return;
        }

        // No valid choice and no third-party banner that could still report one
        // (never chosen, or the stored choice expired after consent_cookie_days):
        // identifiers written under an earlier consent must not outlive it.
        purgeTrackingStorage( true );

        // 4. Built-in banner — visitor has unlimited time to decide
        //    Anonymous tracking is already running in the background.
        if ( config.show_builtin_banner ) {
            showBanner();
            return;
        }

        // 5. Banner disabled, no third-party — anonymous tracking already started above.
        //    Nothing more to do. Visitor stays anonymous.
    }

    /**
     * Initialize the consent manager.
     *
     * Polls for window.optiBehaviorConsentConfig to handle JS optimization
     * plugins (LiteSpeed, WP Rocket, Autoptimize) that may combine the
     * wp_localize_script inline data into a bundle loaded after this file.
     */
    function init() {
        var _waited = 0;

        function dispatch() {
            // Re-read config at dispatch time so we always get the resolved value.
            config = window.optiBehaviorConsentConfig || {};
            run();
        }

        function check() {
            if ( typeof window.optiBehaviorConsentConfig !== 'undefined' ) {
                dispatch();
            } else if ( _waited >= 10000 ) {
                // Config never appeared after 10s — fire anonymous fallback.
                if ( window.OptiBehaviorDebug ) {
                    window.OptiBehaviorDebug.error(
                        'optiBehaviorConsentConfig not found after 10s',
                        'consent-manager'
                    );
                }
                fireConsentEvent( 'rejected' );
            } else {
                _waited += 50;
                setTimeout( check, 50 );
            }
        }

        check();
    }

    // =========================================================================
    // Bootstrap
    // =========================================================================

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

}());
