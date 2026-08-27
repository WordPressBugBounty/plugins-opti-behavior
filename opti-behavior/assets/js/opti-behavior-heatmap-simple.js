/**
 * Opti-Behavior Simple Heatmap Tracker
 * A lightweight replacement for the broken minified heatmap script
 * 
 * This script tracks click events and sends them to the server for heatmap visualization
 */

(function() {
    'use strict';

    // -------------------------------------------------------------------------
    // Dead-endpoint guard — permanent stop when the admin-ajax handler is NOT
    // registered but a page-cache plugin still serves cached HTML that loads this
    // tracker. WordPress core answers an unknown admin-ajax action with
    // wp_die('0', 400): HTTP 400 + body exactly "0". Permanent (not transient):
    // stop the send/heartbeat timers so cached pages can't flood the endpoint.
    // Kept strict (400 + trimmed "0") so real handler 400s / 403 nonce rejections
    // don't trip it. Nonce-independent: identical on cached and fresh pages.
    // -------------------------------------------------------------------------
    var _deadEndpoint = false;

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
    // Nonce refresh — ONE network refresh per page load to heal cached-page
    // nonce expiry. A full-page-cache TTL can outlive the WordPress nonce
    // lifetime, so cached HTML ships a nonce the ingest endpoints reject with
    // HTTP 403. On that 403 the tracker refreshes the nonce and RE-SENDS its
    // own payload (page-view / click events), so no visit is dropped.
    //
    // This is a small state machine, NOT a single boolean latch. The old latch
    // (`_nonceRefreshed = true` set at refresh start) caused a race: any send
    // that 403'd WHILE the refresh was in flight saw the latch already set and
    // silently dropped its payload instead of re-sending it. Here the network
    // refresh still runs at most once, but EVERY caller that 403s — before,
    // during, or after the refresh — registers a callback that fires once the
    // fresh nonce is available, so each caller re-sends/re-queues its own
    // payload. A failed refresh notifies waiters with ok=false so they give up
    // (bounded — no retry loop).
    // -------------------------------------------------------------------------
    var _nonceRefreshStarted = false; // network refresh launched (at most once)
    var _nonceRefreshDone = false;    // refresh settled (success OR failure)
    var _nonceRefreshOk = false;      // refresh settled successfully
    var _nonceWaiters = [];           // callbacks awaiting the fresh nonce

    /**
     * Run the server nonce refresh at most once per page load and notify every
     * caller when the fresh nonce is ready.
     *
     * The callback is invoked exactly once with a boolean `ok` (true when a
     * fresh nonce was obtained and written to trackerConfig.nonce):
     *   - immediately, if a refresh already settled this page load;
     *   - on completion, if a refresh is currently in flight;
     *   - after launching a new refresh, otherwise.
     * Callers perform their own re-send / re-queue inside the callback, so a
     * 403 that lands during an in-flight refresh is never dropped.
     *
     * @param {Object}   trackerConfig The HeatmapTracker instance's config object.
     * @param {Function} cb            Called once with (ok) when the nonce is fresh.
     */
    function ensureFreshNonce( trackerConfig, cb ) {
        if ( _nonceRefreshDone ) {
            if ( cb ) { cb( _nonceRefreshOk ); }
            return;
        }
        if ( cb ) { _nonceWaiters.push( cb ); }
        if ( _nonceRefreshStarted ) { return; } // refresh already in flight
        _nonceRefreshStarted = true;

        var settle = function( ok ) {
            _nonceRefreshOk = ok;
            _nonceRefreshDone = true;
            var waiters = _nonceWaiters;
            _nonceWaiters = [];
            for ( var i = 0; i < waiters.length; i++ ) {
                try { waiters[ i ]( ok ); } catch ( e ) {}
            }
        };

        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', trackerConfig.ajax_url );
        xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
        xhr.onload = function() {
            var ok = false;
            if ( xhr.status === 200 ) {
                try {
                    var resp = JSON.parse( xhr.responseText );
                    if ( resp.success && resp.data && resp.data.nonce_heatmap ) {
                        trackerConfig.nonce = resp.data.nonce_heatmap;
                        ok = true;
                        if ( window.OptiBehaviorDebug ) {
                            window.OptiBehaviorDebug.debug( 'Nonce refreshed on cached page', 'heatmap-simple' );
                        }
                    }
                } catch ( e ) {
                    if ( window.OptiBehaviorDebug ) {
                        window.OptiBehaviorDebug.error( 'Nonce refresh parse error', 'heatmap-simple', e );
                    }
                }
            }
            settle( ok );
        };
        xhr.onerror = function() { settle( false ); };
        xhr.send( 'action=opti_behavior_refresh_nonces' );
    }

    /**
     * Map a raw buffered event captured by the wp_head early-event-queue stub
     * ({ x, y, width, height }) onto the EXISTING click-event payload schema
     * built by HeatmapTracker.prototype.handleClick — same shape, same field
     * names, `time: 0` like every live click.
     *
     * Kept as a pure, side-effect-free, top-level function (no schema
     * duplication: it mirrors handleClick's eventData literal exactly) so it
     * is directly unit-testable via the __optiBehaviorTestables hook below,
     * independently of whether init() ever runs (e.g. config never appears).
     *
     * @param {Object}  bufferedEvent Raw captured fields: { x, y, width, height }.
     * @param {boolean} isMobile      Tracker's device classification.
     * @return {Object} Click event object ready for HeatmapTracker.eventQueue.
     */
    function mapReplayEventToClickPayload( bufferedEvent, isMobile ) {
        return {
            event: 'click',
            device: isMobile ? 'mobile' : 'pc',
            x: bufferedEvent.x,
            y: bufferedEvent.y,
            width: bufferedEvent.width,
            height: bufferedEvent.height,
            time: 0
        };
    }

    // -------------------------------------------------------------------------
    // Top Clicked Elements — element identification for click heatmap stats.
    //
    // Pure, dependency-free helpers that classify the clicked DOM element so the
    // admin "AI Insights → Top Clicked Elements" panel can aggregate clicks per
    // element with a human-readable label, a semantic type (button/menu/image/…)
    // and the page-builder ecosystem that rendered it (Elementor, Divi, Beaver
    // Builder, WPBakery, Brizy, SiteOrigin, Visual Composer, Oxygen, Breakdance,
    // Live Composer, MotoPress, Themify, Gutenberg, GenerateBlocks, Sandwich,
    // form plugins, WooCommerce, …). Unknown themes/builders degrade gracefully
    // to pure semantic detection, so any WordPress ecosystem is handled.
    //
    // Privacy: labels come ONLY from aria-label / title / alt / placeholder /
    // visible text nodes. Form input values are NEVER read.
    // -------------------------------------------------------------------------

    var MAX_ANCESTOR_WALK = 15;

    /**
     * Get an element's class attribute as a plain string.
     * SVG elements expose className as SVGAnimatedString (object), not string.
     *
     * @param {Element} el Element.
     * @return {string} Space-separated class list ('' when none).
     */
    function getClassString( el ) {
        if ( ! el ) { return ''; }
        var cls = el.className || '';
        if ( typeof cls === 'object' && cls.baseVal !== undefined ) {
            cls = cls.baseVal || '';
        }
        if ( typeof cls !== 'string' ) {
            cls = ( el.getAttribute && el.getAttribute( 'class' ) ) || '';
        }
        return cls;
    }

    /**
     * Resolve the "meaningful" element for a click: the closest interactive
     * ancestor (link, button, field, menu item…) or the raw target itself
     * (containers/backgrounds are valid click targets too).
     *
     * @param {Element} target Raw event.target.
     * @return {Element|null}
     */
    function getMeaningfulElement( target ) {
        if ( ! target || target.nodeType !== 1 ) { return null; }
        var interactive = null;
        if ( typeof target.closest === 'function' ) {
            try {
                interactive = target.closest(
                    'a, button, input, select, textarea, label, summary, ' +
                    '[role="button"], [role="menuitem"], [role="tab"], [role="link"], [onclick]'
                );
            } catch ( e ) { interactive = null; }
        }
        return interactive || target;
    }

    // Ordered builder/ecosystem signatures. First match on the element or any of
    // its first MAX_ANCESTOR_WALK ancestors wins. Order matters: specific
    // plugin ecosystems before broad ones.
    var BUILDER_RULES = [
        // Forms plugins (checked first: a CF7 form inside Elementor should be attributed to CF7)
        { name: 'Contact Form 7',   re: /(^|\s|-)wpcf7/ },
        { name: 'WPForms',          re: /(^|\s)wpforms/ },
        { name: 'Gravity Forms',    re: /(^|\s)gform|(^|\s)gfield/ },
        { name: 'Ninja Forms',      re: /(^|\s)nf-|(^|\s)ninja-forms/ },
        { name: 'Fluent Forms',     re: /(^|\s)ff-el|(^|\s)frm-fluent|(^|\s)fluentform/ },
        { name: 'Formidable',       re: /(^|\s)frm_|(^|\s)frm-/ },
        { name: 'Forminator',       re: /(^|\s)forminator-/ },
        { name: 'Everest Forms',    re: /(^|\s)evf-|(^|\s)everest-forms/ },
        { name: 'MetForm',          re: /(^|\s)mf-input|(^|\s)metform/ },
        { name: 'SureForms',        re: /(^|\s)srfm-/ },
        { name: 'Gutenverse Form',  re: /(^|\s)guten-form/ },
        { name: 'weForms',          re: /(^|\s)weforms/ },
        { name: 'HappyForms',       re: /(^|\s)happyforms/ },
        // Popup / opt-in plugins (before builders: an Elementor button inside a
        // Popup Maker modal is attributed to Popup Maker)
        { name: 'Popup Maker',      re: /(^|\s)pum-|(^|\s)popmake/ },
        { name: 'Popup Builder',    re: /(^|\s)sgpb-|(^|\s)sg-popup/ },
        { name: 'OptinMonster',     re: /(^|\s)optin-?monster|(^|\s)om-element|(^|\s)omapi/ },
        { name: 'Hustle',           re: /(^|\s)hustle-/ },
        { name: 'Bloom',            re: /(^|\s)et_bloom/ },
        // Newsletter / marketing
        { name: 'Mailchimp for WP', re: /(^|\s)mc4wp-/ },
        { name: 'MailPoet',         re: /(^|\s)mailpoet_|(^|\s)mailpoet-/ },
        // Community / membership / discussion
        { name: 'Ultimate Member',  re: /(^|\s)um-[a-z]/ },
        { name: 'BuddyPress',      re: /(^|\s)bp-[a-z]|(^|\s)buddypress/ },
        { name: 'bbPress',          re: /(^|\s)bbp-[a-z]|(^|\s)bbpress/ },
        { name: 'wpDiscuz',         re: /(^|\s)wpd-[a-z]|(^|\s)wpdiscuz/ },
        { name: 'MemberPress',      re: /(^|\s)mepr-|(^|\s)mepr_/ },
        // LMS
        { name: 'LearnDash',        re: /(^|\s)learndash|(^|\s)ld-[a-z]/ },
        { name: 'Tutor LMS',        re: /(^|\s)tutor-/ },
        { name: 'LifterLMS',        re: /(^|\s)llms-/ },
        // Events / commerce
        { name: 'The Events Calendar', re: /(^|\s)tribe-/ },
        { name: 'Easy Digital Downloads', re: /(^|\s)edd-|(^|\s)edd_/ },
        { name: 'WooCommerce',      re: /(^|\s)woocommerce|(^|\s)wc-|(^|\s)add_to_cart_button|(^|\s)single_add_to_cart_button|(^|\s)product_type_/ },
        // Sliders
        { name: 'Slider Revolution', re: /(^|\s)rev_slider|(^|\s)rs-(layer|slide|module|fullwidth)|(^|\s)tp-(caption|bullet)/ },
        { name: 'Smart Slider',     re: /(^|\s)n2-ss-|(^|\s)n2_ss/ },
        { name: 'MetaSlider',       re: /(^|\s)metaslider/ },
        // Mega menus
        { name: 'Max Mega Menu',    re: /(^|\s)max-mega-menu|(^|\s)mega-menu-(item|link|row|column|wrap)/ },
        { name: 'UberMenu',         re: /(^|\s)ubermenu/ },
        // Page builders — addon libraries before their host builder.
        { name: 'JetPlugins',       re: /(^|\s)jet-[a-z]/ },
        { name: 'Essential Addons', re: /(^|\s)eael-/ },
        { name: 'Elementor',        re: /(^|\s)elementor/ },
        { name: 'Divi',             re: /(^|\s)et_pb_|(^|\s)et-db|(^|\s)et_smooth/ },
        { name: 'Beaver Builder',   re: /(^|\s)fl-(builder|module|row|col|node|button|menu|photo|html)/ },
        { name: 'WPBakery',         re: /(^|\s)vc_|(^|\s)wpb_/ },
        { name: 'Visual Composer',  re: /(^|\s)vce-|(^|\s)vcv-/ },
        { name: 'Brizy',            re: /(^|\s)brz-/ },
        { name: 'SiteOrigin',       re: /(^|\s)so-widget|(^|\s)panel-grid|(^|\s)siteorigin-/ },
        { name: 'Oxygen',           re: /(^|\s)ct-(section|div|link|text|image|span|headline)|(^|\s)oxy-/ },
        { name: 'Bricks',           re: /(^|\s)brxe-|(^|\s)bricks-/ },
        { name: 'Breakdance',       re: /(^|\s)bde-|(^|\s)breakdance/ },
        { name: 'Live Composer',    re: /(^|\s)dslc-/ },
        { name: 'MotoPress',        re: /(^|\s)motopress|(^|\s)mp-wrap|(^|\s)mpce-/ },
        { name: 'Themify Builder',  re: /(^|\s)themify_builder|(^|\s)tb_[a-z0-9]/ },
        { name: 'Page Builder Sandwich', re: /(^|\s)pbs-|(^|\s)pbsandwich/ },
        { name: 'Avada Builder',    re: /(^|\s)fusion-/ },
        { name: 'Thrive Architect', re: /(^|\s)thrv_|(^|\s)tve_|(^|\s)tcb-/ },
        { name: 'SeedProd',         re: /(^|\s)seedprod/ },
        { name: 'Zion Builder',     re: /(^|\s)zb-el-|(^|\s)znpb-/ },
        // Block libraries (before plain Gutenberg)
        { name: 'GenerateBlocks',   re: /(^|\s)gb-(container|button|headline|grid|element)/ },
        { name: 'Spectra',          re: /(^|\s)uagb-/ },
        { name: 'Kadence Blocks',   re: /(^|\s)kb-[a-z]|(^|\s)kt-(btn|blocks|inside|row)/ },
        { name: 'Stackable',        re: /(^|\s)stk-/ },
        { name: 'Gutenverse',       re: /(^|\s)guten-[a-z]/ },
        // Themes (after builders: builder content inside theme wrappers wins)
        { name: 'GeneratePress',    re: /(^|\s)generate-|(^|\s)gp-/ },
        { name: 'Astra',            re: /(^|\s)ast-/ },
        { name: 'OceanWP',          re: /(^|\s)oceanwp|(^|\s)ocean-[a-z]/ },
        { name: 'Blocksy',          re: /(^|\s)ct-(container|header|footer|panel|drawer|toggle|menu|media)/ },
        { name: 'Kadence Theme',    re: /(^|\s)kadence-/ },
        { name: 'Flatsome',         re: /(^|\s)flatsome|(^|\s)ux-[a-z]/ },
        { name: 'Enfold',           re: /(^|\s)avia-|(^|\s)av_/ },
        { name: 'Neve',             re: /(^|\s)nv-[a-z]|(^|\s)neve-/ },
        { name: 'Kirki',            re: /(^|\s)kirki-/ },
        { name: 'Jetpack',          re: /(^|\s)jetpack-/ },
        // Gutenberg LAST among builders: builder widgets often live inside block wrappers.
        { name: 'Gutenberg',        re: /(^|\s)wp-block-|(^|\s)wp-element-button/ }
    ];

    /**
     * Detect which WordPress ecosystem (page builder / form plugin / Woo)
     * rendered the clicked element, by scanning class signatures on the element
     * and its ancestors.
     *
     * @param {Element} el Resolved element.
     * @return {string} Builder name or '' when unknown (generic theme output).
     */
    function detectBuilder( el ) {
        var node = el;
        var depth = 0;
        while ( node && node.nodeType === 1 && depth < MAX_ANCESTOR_WALK ) {
            var nodeTag = ( node.tagName || '' ).toLowerCase();
            // Never read body/html: plugins add site-wide marker classes there
            // (everest-forms-js, elementor-kit-*, …) which would misattribute
            // every unmatched element on the page.
            if ( nodeTag === 'body' || nodeTag === 'html' ) { break; }
            var haystack = getClassString( node ) + ' ' + ( node.id || '' );
            for ( var i = 0; i < BUILDER_RULES.length; i++ ) {
                if ( BUILDER_RULES[ i ].re.test( haystack ) ) {
                    return BUILDER_RULES[ i ].name;
                }
            }
            node = node.parentElement;
            depth++;
        }
        return '';
    }

    /**
     * True when the element sits inside a navigation/menu structure
     * (any theme or builder).
     *
     * @param {Element} el Element.
     * @return {boolean}
     */
    function isInsideMenu( el ) {
        if ( ! el || typeof el.closest !== 'function' ) { return false; }
        try {
            return !! el.closest(
                'nav, [role="navigation"], [role="menubar"], .menu, .nav-menu, .navbar, .sub-menu, .menu-item, ' +
                '.wp-block-navigation, .wp-block-page-list, .elementor-nav-menu, .et_pb_menu, .fl-menu, ' +
                '.dropdown-menu, .mega-menu, .max-mega-menu, .ubermenu, .main-navigation, .site-navigation, ' +
                '.primary-menu, .footer-navigation, .mobile-menu, .off-canvas-menu'
            );
        } catch ( e ) { return false; }
    }

    /**
     * Classify the semantic type of the clicked element.
     * Works for native HTML, any theme, and any page builder markup.
     *
     * @param {Element} el Resolved element.
     * @return {string} One of: captcha, search, login, comment, button, menu,
     *                  pagination, breadcrumb, link, image, video, form-field,
     *                  checkbox, radio, dropdown, accordion, tab, slider, list,
     *                  heading, text, popup, section, element.
     */
    function detectElementType( el ) {
        if ( ! el || el.nodeType !== 1 ) { return 'element'; }
        var tag = ( el.tagName || '' ).toLowerCase();
        var cls = getClassString( el ).toLowerCase();
        var role = ( el.getAttribute && el.getAttribute( 'role' ) ) || '';
        var closest = function( sel ) {
            if ( typeof el.closest !== 'function' ) { return null; }
            try { return el.closest( sel ); } catch ( e ) { return null; }
        };

        // ---- Context-aware site features (highest priority) ----

        // Captcha widgets (reCAPTCHA, hCaptcha, Cloudflare Turnstile, plugin captchas).
        if (
            /(^|\s)(g-recaptcha|h-captcha|cf-turnstile|grecaptcha-badge)(\s|$)/.test( cls ) ||
            closest( '.g-recaptcha, .h-captcha, .cf-turnstile, .grecaptcha-badge, ' +
                     '.wpcf7-recaptcha, .wpforms-recaptcha-container, .frm-captcha, .ff-el-recaptcha' )
        ) {
            return 'captcha';
        }

        // Search UI: anything inside a search form/widget (field, submit, icon, toggle).
        if (
            ( tag === 'input' &&
              ( ( el.getAttribute( 'type' ) || '' ).toLowerCase() === 'search' ||
                ( el.getAttribute( 'name' ) || '' ) === 's' ) ) ||
            closest( '[role="search"], .search-form, .searchform, .wp-block-search, ' +
                     '.elementor-search-form, .et_pb_search, .fl-search, .woocommerce-product-search, ' +
                     '.search-toggle, .search-modal, .astra-search-menu-icon' )
        ) {
            return 'search';
        }

        // Login / registration UI (core, WooCommerce, Ultimate Member, Theme My Login…).
        if ( closest(
            '#loginform, #registerform, #lostpasswordform, .login-form, .register-form, ' +
            '.wp-block-loginout, .woocommerce-form-login, .woocommerce-form-register, ' +
            '.woocommerce-MyAccount-navigation, .um-login, .um-register, .um-password, ' +
            '.tml-login, .tml-register, .elementor-widget-login'
        ) ) {
            return 'login';
        }

        // Comments UI (native, block, wpDiscuz, Jetpack…).
        if ( closest(
            '#comments, #respond, .comment-respond, .comments-area, .comment-form, ' +
            '.wp-block-comments, .wp-block-post-comments-form, #wpdiscuz, .wpd-comment, ' +
            '.comment-list, .comment-reply-link'
        ) ) {
            return 'comment';
        }

        // Form controls first (most specific).
        if ( tag === 'input' ) {
            var itype = ( el.getAttribute( 'type' ) || 'text' ).toLowerCase();
            if ( itype === 'checkbox' ) { return 'checkbox'; }
            if ( itype === 'radio' ) { return 'radio'; }
            if ( itype === 'submit' || itype === 'button' || itype === 'image' ) { return 'button'; }
            return 'form-field';
        }
        if ( tag === 'textarea' ) { return 'form-field'; }
        if ( tag === 'select' ) { return 'dropdown'; }
        if ( tag === 'label' ) { return 'form-field'; }

        // Buttons — native, role-based, and builder button classes.
        if (
            tag === 'button' || role === 'button' ||
            /(^|\s)(btn|button|elementor-button|et_pb_button|fl-button|wp-block-button__link|wp-element-button|vc_btn|brz-btn|gb-button|oxy-button|bde-button|dslc-button|pbs-button)(\s|$|-|_)/.test( cls )
        ) {
            return 'button';
        }

        // Pagination / breadcrumbs beat menu: WP renders both inside <nav>.
        if ( closest( '.pagination, .page-numbers, .wp-block-query-pagination, ' +
                      '.woocommerce-pagination, .paging-navigation, .nav-links' ) ) {
            return 'pagination';
        }
        if ( closest( '.breadcrumb, .breadcrumbs, .woocommerce-breadcrumb, .rank-math-breadcrumb, ' +
                      '#breadcrumbs, [aria-label="Breadcrumb"], [aria-label="breadcrumb"], [aria-label="Breadcrumbs"]' ) ) {
            return 'breadcrumb';
        }

        // Menu context beats plain link: a link inside nav is a menu item.
        if ( isInsideMenu( el ) ) { return 'menu'; }

        if ( tag === 'a' || role === 'link' ) { return 'link'; }

        // Media.
        if ( tag === 'img' || tag === 'svg' || tag === 'picture' || tag === 'canvas' ||
             closest( 'figure, .wp-block-image, .wp-block-gallery, .elementor-widget-image, .et_pb_image, .fl-photo' ) ) {
            return 'image';
        }
        if ( tag === 'video' || tag === 'audio' ||
             closest( '.wp-block-embed, .elementor-widget-video, .et_pb_video, .fl-video, .mejs-container, .wp-video' ) ) {
            return 'video';
        }

        // Interactive composite widgets.
        if ( tag === 'summary' || tag === 'details' ||
             closest( '.accordion, .elementor-accordion, .elementor-toggle, .et_pb_toggle, .et_pb_accordion, .wp-block-details, .fl-accordion, .vc_tta-panel' ) ) {
            return 'accordion';
        }
        if ( role === 'tab' ||
             closest( '[role="tablist"], .elementor-tab-title, .et_pb_tabs, .fl-tabs, .vc_tta-tab, .wp-block-tabs' ) ) {
            return 'tab';
        }
        if ( closest( '.swiper, .swiper-container, .slick-slider, .carousel, .elementor-swiper, .et_pb_slider, .fl-slider, .owl-carousel, .splide' ) ) {
            return 'slider';
        }

        // Content semantics.
        if ( tag === 'li' || ( ( tag === 'ul' || tag === 'ol' ) ) ) { return 'list'; }
        if ( /^h[1-6]$/.test( tag ) ) { return 'heading'; }
        if ( tag === 'p' || tag === 'span' || tag === 'blockquote' || tag === 'em' || tag === 'strong' ) { return 'text'; }

        // Popup / modal surface click (overlay, dialog chrome). Interactive
        // children (buttons, links, fields) were already classified above.
        if ( closest(
            '.pum-overlay, .pum-container, .sgpb-popup, .sgpb-popup-dialog-main-div, ' +
            '.elementor-popup-modal, [role="dialog"], .mfp-wrap, .mfp-container, .modal, ' +
            '.fancybox-container, .lity, .hustle-popup, .brz-popup2, .et_pb_section--fullscreen'
        ) ) {
            return 'popup';
        }

        // Plain containers = background / section click.
        if ( tag === 'section' || tag === 'div' || tag === 'main' || tag === 'article' ||
             tag === 'aside' || tag === 'header' || tag === 'footer' || tag === 'body' ) {
            return 'section';
        }

        return 'element';
    }

    /**
     * Privacy-safe human label for the element.
     * Sources: aria-label → title → alt → placeholder → own text nodes.
     * NEVER reads form input values. Max 100 chars, whitespace-collapsed.
     *
     * @param {Element} el Resolved element.
     * @return {string}
     */
    function getElementLabel( el ) {
        if ( ! el || el.nodeType !== 1 || ! el.getAttribute ) { return ''; }
        var text = el.getAttribute( 'aria-label' ) ||
                   el.getAttribute( 'title' ) ||
                   el.getAttribute( 'alt' ) ||
                   el.getAttribute( 'placeholder' ) || '';

        if ( ! text ) {
            var tag = ( el.tagName || '' ).toLowerCase();
            // Never read values of form fields (privacy).
            if ( tag !== 'input' && tag !== 'textarea' && tag !== 'select' ) {
                // Full textContent for small interactive elements (covers
                // <a><span>Label</span></a>); own text nodes for containers so a
                // section click doesn't swallow the whole page text.
                var full = ( el.textContent || '' ).trim();
                var isInteractive = /^(a|button|summary|label|li)$/.test( tag );
                if ( isInteractive && full.length > 0 && full.length <= 120 ) {
                    text = full;
                } else {
                    var own = '';
                    var nodes = el.childNodes || [];
                    for ( var i = 0; i < nodes.length; i++ ) {
                        if ( nodes[ i ].nodeType === 3 ) { own += nodes[ i ].textContent + ' '; }
                    }
                    text = own;
                }
            }
        }

        return String( text ).replace( /\s+/g, ' ' ).trim().substring( 0, 100 );
    }

    /**
     * Pick up to `max` stable CSS classes for the grouping selector.
     * Filters out state/utility classes (active/hover/current-…) that would
     * split one logical element into several stat rows.
     *
     * @param {Element} el  Element.
     * @param {number}  max Max classes.
     * @return {Array<string>}
     */
    function getStableClasses( el, max ) {
        var out = [];
        var classes = getClassString( el ).split( /\s+/ );
        var stateRe = /^(active|current|hover|focus|open|opened|show|shown|visible|selected|checked|disabled|is-|has-|animated|wow|aos-)/i;
        for ( var i = 0; i < classes.length && out.length < max; i++ ) {
            var c = classes[ i ];
            if ( ! c || c.length > 40 || stateRe.test( c ) ) { continue; }
            if ( ! /^[a-zA-Z_][\w-]*$/.test( c ) ) { continue; }
            out.push( c );
        }
        return out;
    }

    /**
     * Build a short, stable selector used as the aggregation key.
     * Priority: #id → tag.class1.class2 → ancestor#id tag → tag.
     * Max 191 chars (DB column size).
     *
     * @param {Element} el Resolved element.
     * @return {string}
     */
    function buildStableSelector( el ) {
        if ( ! el || el.nodeType !== 1 ) { return ''; }
        var tag = ( el.tagName || '' ).toLowerCase();
        var selector;

        if ( el.id && /^[a-zA-Z_][\w-]*$/.test( el.id ) ) {
            selector = '#' + el.id;
        } else {
            var stable = getStableClasses( el, 2 );
            if ( stable.length > 0 ) {
                selector = tag + '.' + stable.join( '.' );
            } else {
                // Anchor to nearest identified ancestor for stability.
                var node = el.parentElement;
                var depth = 0;
                var anchor = '';
                while ( node && node.nodeType === 1 && depth < MAX_ANCESTOR_WALK ) {
                    if ( node.id && /^[a-zA-Z_][\w-]*$/.test( node.id ) ) {
                        anchor = '#' + node.id;
                        break;
                    }
                    var ancStable = getStableClasses( node, 1 );
                    if ( ! anchor && ancStable.length > 0 ) {
                        anchor = ( node.tagName || '' ).toLowerCase() + '.' + ancStable[ 0 ];
                    }
                    node = node.parentElement;
                    depth++;
                }
                selector = anchor ? anchor + ' ' + tag : tag;
            }
        }

        return selector.substring( 0, 191 );
    }

    /**
     * Build an absolute XPath for an element (from `<html>`), using
     * tag-name + same-tag sibling index at each level. Used as a precise,
     * unambiguous secondary anchor for click reprojection (D1) — alongside
     * (never instead of) `buildStableSelector()`'s resilient CSS selector.
     * Capped to 512 chars (DB column size, D3).
     *
     * @param {Element} el Resolved element.
     * @return {string} XPath string, '' when unresolvable.
     */
    function buildElementXPath( el ) {
        if ( ! el || el.nodeType !== 1 ) { return ''; }
        try {
            var parts = [];
            var node = el;
            var docEl = document.documentElement;
            while ( node && node.nodeType === 1 && node !== docEl ) {
                var tag = ( node.tagName || '' ).toLowerCase();
                var index = 1;
                var sibling = node.previousElementSibling;
                while ( sibling ) {
                    if ( ( sibling.tagName || '' ).toLowerCase() === tag ) { index++; }
                    sibling = sibling.previousElementSibling;
                }
                parts.unshift( tag + '[' + index + ']' );
                node = node.parentElement;
            }
            if ( parts.length === 0 ) { return ''; }
            return ( '/html/' + parts.join( '/' ) ).substring( 0, 512 );
        } catch ( e ) {
            return '';
        }
    }

    /**
     * Element-anchored data for a click: absolute XPath + click offset
     * relative to the resolved element's box (0..1, clamped). Clicks only
     * (D2) — never called for move/scroll/attention events. Purely additive:
     * never touches the existing absolute x/y capture (fallback + legacy
     * parity, D4). Must never break click tracking — always caught.
     *
     * @param {Element} rawTarget event.target of the click.
     * @param {number} pageX Absolute (unadjusted) click page X.
     * @param {number} pageY Absolute (unadjusted) click page Y — i.e. NOT the
     *                        admin-bar-offset-corrected y used for storage/x/y.
     * @return {Object} { element_xpath, element_rel_x, element_rel_y } —
     *                   '' / null when unresolvable.
     */
    function computeElementAnchor( rawTarget, pageX, pageY ) {
        var empty = { element_xpath: '', element_rel_x: null, element_rel_y: null };
        try {
            var el = getMeaningfulElement( rawTarget );
            if ( ! el || typeof el.getBoundingClientRect !== 'function' ) { return empty; }

            var rect = el.getBoundingClientRect();
            if ( ! rect || rect.width <= 0 || rect.height <= 0 ) { return empty; }

            var scrollX = window.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft || 0;
            var scrollY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;

            var relX = ( pageX - ( rect.left + scrollX ) ) / rect.width;
            var relY = ( pageY - ( rect.top + scrollY ) ) / rect.height;

            relX = Math.min( 1, Math.max( 0, relX ) );
            relY = Math.min( 1, Math.max( 0, relY ) );

            return {
                element_xpath: buildElementXPath( el ),
                element_rel_x: relX,
                element_rel_y: relY
            };
        } catch ( e ) {
            if ( window.OptiBehaviorDebug ) {
                window.OptiBehaviorDebug.error( 'Element anchor failed:', 'heatmap-simple', e );
            }
            return empty;
        }
    }

    /**
     * URL context for the clicked element (Top Clicked Elements panel).
     *
     * Anchors (or elements inside one): the raw `href` attribute as authored
     * in the markup — relative URLs, mailto:, tel: and #anchors kept as-is.
     * Otherwise, for image clicks: the image `src` path (origin stripped for
     * same-site images). `javascript:` and `data:` URLs are never captured.
     * Href/src are site markup (author content), not visitor data.
     *
     * @param {Element} el Resolved element.
     * @return {string} URL string ('' when none). Max 191 chars (DB column).
     */
    function getElementHref( el ) {
        if ( ! el || el.nodeType !== 1 ) { return ''; }
        var href = '';
        try {
            var tag = ( el.tagName || '' ).toLowerCase();

            // Anchor href: the element itself or its closest anchor ancestor.
            var anchor = null;
            if ( tag === 'a' ) {
                anchor = el;
            } else if ( typeof el.closest === 'function' ) {
                try { anchor = el.closest( 'a[href]' ); } catch ( e ) { anchor = null; }
            }
            if ( anchor && anchor.getAttribute ) {
                href = anchor.getAttribute( 'href' ) || '';
            }

            // Image src: the clicked image itself or the one inside the
            // resolved wrapper (picture/figure). Only when no anchor context.
            if ( ! href ) {
                var img = null;
                if ( tag === 'img' ) {
                    img = el;
                } else if ( ( tag === 'picture' || tag === 'figure' ) && el.querySelector ) {
                    img = el.querySelector( 'img' );
                }
                if ( img ) {
                    href = img.currentSrc || ( img.getAttribute && img.getAttribute( 'src' ) ) || '';
                    // Same-origin images: keep the path only (shorter, stable).
                    var origin = ( window.location && window.location.origin ) || '';
                    if ( origin && href.indexOf( origin + '/' ) === 0 ) {
                        href = href.substring( origin.length );
                    }
                }
            }
        } catch ( e ) {
            return '';
        }

        href = String( href ).replace( /\s+/g, ' ' ).trim();
        // Never capture executable or inline-data URLs.
        if ( /^(javascript|data|vbscript):/i.test( href ) ) { return ''; }
        return href.substring( 0, 191 );
    }

    /**
     * Full element info payload for a click event.
     *
     * @param {Element} rawTarget event.target of the click.
     * @return {Object} { element_tag, element_id, element_class, element_text,
     *                    element_type, element_builder, element_selector,
     *                    element_href } — string values, '' when unknown.
     */
    function getClickedElementInfo( rawTarget ) {
        var empty = {
            element_tag: '', element_id: '', element_class: '', element_text: '',
            element_type: '', element_builder: '', element_selector: '', element_href: ''
        };
        try {
            var el = getMeaningfulElement( rawTarget );
            if ( ! el ) { return empty; }
            return {
                element_tag:      ( el.tagName || '' ).toLowerCase().substring( 0, 50 ),
                element_id:       ( el.id || '' ).substring( 0, 100 ),
                element_class:    getClassString( el ).substring( 0, 200 ),
                element_text:     getElementLabel( el ),
                element_type:     detectElementType( el ).substring( 0, 40 ),
                element_builder:  detectBuilder( el ).substring( 0, 50 ),
                element_selector: buildStableSelector( el ),
                element_href:     getElementHref( el )
            };
        } catch ( e ) {
            // Element identification must never break click tracking.
            if ( window.OptiBehaviorDebug ) {
                window.OptiBehaviorDebug.error( 'Element info failed:', 'heatmap-simple', e );
            }
            return empty;
        }
    }

    // Test hook — lets QA harnesses exercise the replay mapping without
    // booting the full tracker (see tests/phase-c-early-queue-test.js). Not a
    // public API.
    if ( typeof window !== 'undefined' ) {
        window.__optiBehaviorTestables = window.__optiBehaviorTestables || {};
        window.__optiBehaviorTestables.mapReplayEventToClickPayload = mapReplayEventToClickPayload;
        window.__optiBehaviorTestables.heatmapIsDeadEndpoint = isDeadEndpoint;
        window.__optiBehaviorTestables.getClickedElementInfo = getClickedElementInfo;
        window.__optiBehaviorTestables.detectElementType = detectElementType;
        window.__optiBehaviorTestables.detectBuilder = detectBuilder;
        window.__optiBehaviorTestables.buildStableSelector = buildStableSelector;
        window.__optiBehaviorTestables.getElementLabel = getElementLabel;
        window.__optiBehaviorTestables.getElementHref = getElementHref;
        window.__optiBehaviorTestables.buildElementXPath = buildElementXPath;
        window.__optiBehaviorTestables.computeElementAnchor = computeElementAnchor;
    }

    // Wait for config variable to be available before dispatching init.
    // JS combine plugins (LiteSpeed Cache, WP Rocket, Autoptimize) may displace
    // the wp_localize_script inline data into a combined bundle that loads AFTER
    // this script. Poll up to 10 seconds to handle that displacement scenario;
    // if config is already present (normal case) initialization is immediate.
    //
    // IMPORTANT: the first check is deferred to the next macrotask
    // (setTimeout 0) instead of running synchronously. When a defer/delay-JS
    // optimizer (WP-Optimize, WP Rocket delay-JS, Flying Scripts...) executes
    // this file AFTER DOMContentLoaded, document.readyState is no longer
    // 'loading', so dispatch() would call init() synchronously RIGHT HERE —
    // before the HeatmapTracker.prototype.* method assignments further down
    // this file have been evaluated. HeatmapTracker itself is a hoisted
    // function declaration, so `new HeatmapTracker(config)` succeeds but
    // `tracker.start()` throws "tracker.start is not a function". Deferring
    // one tick guarantees the whole file is evaluated before init() can run,
    // regardless of how any optimizer loads this script.
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
            if (typeof window.opti_behavior_heatmap !== 'undefined') {
                dispatch();
            } else if (_waited >= 10000) {
                // Config never appeared — optimization plugin may have stripped it
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('opti_behavior_heatmap config not found after 10s', 'heatmap-simple');
            } else {
                _waited += 50;
                setTimeout(check, 50);
            }
        }
        setTimeout(check, 0);
    }());
    
    function init() {
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('init() called', 'heatmap-simple');

        // Check if we have the required configuration
        if (typeof window.opti_behavior_heatmap === 'undefined') {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Configuration not found', 'heatmap-simple');
            return;
        }

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Config found:', 'heatmap-simple', window.opti_behavior_heatmap);

        const config = window.opti_behavior_heatmap;

        // Only initialize in reporter mode
        if (config._mode !== 'reporter') {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Not in reporter mode, mode is:', 'heatmap-simple', config._mode);
            return;
        }

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Reporter mode confirmed', 'heatmap-simple');

        // Check if click tracking is enabled
        const reports = Array.isArray(config.reports) ? config.reports : config.reports.split(',');
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        const clickEvent = isMobile ? 'click_mobile' : 'click_pc';

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Reports:', 'heatmap-simple', reports);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('isMobile:', 'heatmap-simple', isMobile);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Looking for event:', 'heatmap-simple', clickEvent);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Event included?', 'heatmap-simple', reports.includes(clickEvent));

        if (!reports.includes(clickEvent)) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Click tracking not enabled for this device type', 'heatmap-simple');
            return;
        }

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Initializing tracker...', 'heatmap-simple');

        /**
         * Start the tracker, optionally overriding privacy_mode to anonymous
         * when full-mode consent has been rejected by the visitor.
         *
         * @param {string} consentStatus 'granted' | 'rejected' | 'anonymous'
         */
        function startTracker( consentStatus ) {
            if ( consentStatus === 'rejected' ) {
                // Visitor declined full tracking — fall back to anonymous behaviour.
                config.privacy_mode = 'anonymous';
                if ( window.optiBehaviorHeatmapConfig ) {
                    window.optiBehaviorHeatmapConfig.privacy_mode = 'anonymous';
                }
                if ( window.OptiBehaviorDebug ) {
                    window.OptiBehaviorDebug.debug( 'Consent rejected — switching to anonymous mode', 'heatmap-simple' );
                }
            }
            var tracker = new HeatmapTracker( config );
            tracker.start();

            // Phase C: replay any clicks buffered by the wp_head early-event-queue
            // stub (window._obq / window._obReplay) before this tracker booted —
            // insurance against unknown optimizers delaying this script itself.
            // HeatmapTracker.start() sets window._obLive = true synchronously as
            // its first statement, so the stub has already stopped buffering by
            // the time we get here; this only drains what was captured pre-boot.
            if ( typeof window._obReplay === 'function' ) {
                window._obReplay( function( bufferedEvent ) {
                    tracker.eventQueue.push( mapReplayEventToClickPayload( bufferedEvent, tracker.isMobile ) );
                } );
            }

            if ( window.OptiBehaviorDebug ) {
                window.OptiBehaviorDebug.debug( 'Tracker initialized (consent: ' + consentStatus + ')', 'heatmap-simple', config );
            }
        }

        var privacyMode = config.privacy_mode || 'anonymous';

        /**
         * Read the raw optibehavior_consent cookie value.
         * Used as a fallback when window.optiBehaviorConsentState is not yet set
         * (e.g. the consent JS hasn't executed before this script on a reload).
         *
         * @return {string|null} 'granted', 'rejected', or null if cookie absent.
         */
        function getConsentCookie() {
            var nameEQ = 'optibehavior_consent=';
            var parts  = document.cookie.split( ';' );
            for ( var i = 0; i < parts.length; i++ ) {
                var part = parts[ i ];
                while ( part.charAt( 0 ) === ' ' ) { part = part.substring( 1 ); }
                if ( part.indexOf( nameEQ ) === 0 ) {
                    return decodeURIComponent( part.substring( nameEQ.length ) );
                }
            }
            return null;
        }

        // Resolve effective consent state: prefer the window global (set by
        // consent.js when it executes first), fall back to the cookie directly
        // (handles the reload case where this script runs before consent.js).
        var effectiveConsent = window.optiBehaviorConsentState || getConsentCookie();

        if ( privacyMode !== 'full' ) {
            // Anonymous mode: start immediately — no consent required.
            startTracker( 'anonymous' );
        } else if ( effectiveConsent === 'granted' ) {
            // Returning visitor with granted consent — full tracking immediately.
            startTracker( 'granted' );
        } else {
            // Full mode: start anonymous tracking IMMEDIATELY (GDPR Recital 26).
            // Anonymous data (no cookies, no IP) does not require consent.
            startTracker( 'rejected' );

            if ( window.OptiBehaviorDebug ) {
                window.OptiBehaviorDebug.debug( 'Full mode: anonymous tracking started. Listening for consent upgrade...', 'heatmap-simple' );
            }

            // Listen for consent upgrade: if visitor accepts, reload page for full tracking.
            document.addEventListener( 'optibehavior:consent_updated', function( e ) {
                if ( e.detail && e.detail.status === 'granted' ) {
                    if ( window.OptiBehaviorDebug ) {
                        window.OptiBehaviorDebug.debug( 'Consent granted — reloading page for full tracking', 'heatmap-simple' );
                    }
                    window.location.reload();
                }
            } );
        }
    }
    
    /**
     * Heatmap Tracker Class
     */
    function HeatmapTracker(config) {
        this.config = config;
        // GDPR: flag anonymous mode so every method can gate individual-record creation.
        // privacy_mode is forced to 'anonymous' by startTracker() when consent is absent.
        this.isAnonymous = ( config.privacy_mode !== 'full' );
        this.eventQueue = [];
        this.isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        this.lastSendTime = Date.now();
        this.sendTimer = null;
        this.sessionStartTime = Date.now();
        this.maxScrollDepth = 0;
        this.heartbeatTimer = null;
        this.lastScrollUpdate = 0;
        // Store session and visitor IDs in memory for reliable access
        this.sessionId = null;
        this.visitorId = null;
        // Dedup guard for finalizeSession() — 'pagehide' and 'beforeunload' can
        // both fire for the same navigation on some browsers; only flush/end
        // the session once per page load.
        this._finalized = false;
        // Track last activity time for inactivity timeout (30 minutes like Pro version)
        this.lastActivityTime = Date.now();
        this.inactivityTimeout = 30 * 60 * 1000; // 30 minutes in milliseconds
        this.maxSessionDuration = 60 * 60 * 1000; // Cap session at 1 hour max
        // Heartbeat adaptive backoff (perf fix: a fixed 5s interval hammered
        // admin-ajax.php with up to 720 full-WP-bootstrap requests per session
        // and slowed down whole sites). Start at 15s, double after each send,
        // cap at 120s. The final unload heartbeat (finalizeSession) carries the
        // authoritative duration/scroll values, so coarser periodic updates
        // lose no data.
        this.heartbeatBaseDelay = 15000;   // 15 seconds
        this.heartbeatMaxDelay = 120000;   // 2 minutes cap
        this.heartbeatDelay = this.heartbeatBaseDelay;
        this.heartbeatStopped = false;
        // No-change guard: skip a heartbeat when the user produced no activity
        // and no new scroll depth since the previous one (idle foreground tab).
        this.lastHeartbeatSentAt = 0;
        this.lastHeartbeatScrollDepth = -1;
        // Mouse move tracking state
        this.lastMoveTime = 0;
        this.lastMoveX = 0;
        this.lastMoveY = 0;
        this.moveThrottleMs = 300; // Throttle: record at most every 300ms
        this.moveMinDistance = 30;  // Minimum 30px movement to record
        // Admin bar offset: when admin bar is present, it shifts the page content down.
        // We subtract this offset from Y coordinates so heatmap clicks align with the
        // page as rendered without the admin bar (which is how the heatmap iframe displays).
        var adminBar = document.getElementById('wpadminbar');
        this.adminBarOffset = adminBar ? adminBar.offsetHeight : 0;
    }
    
    /**
     * Get cookie value by name
     */
    HeatmapTracker.prototype.getCookie = function(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    };

    HeatmapTracker.prototype.start = function() {
        // Phase C dedup flag: flip BEFORE attaching our own click listener below
        // so the wp_head early-event-queue stub (window._obq) stops buffering at
        // the exact moment this tracker takes over — no double-count window.
        // Synchronous JS: no click can occur between this line and the
        // addEventListener call further down.
        window._obLive = true;

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('start() called', 'heatmap-simple');

        // Send initial page view event immediately to create session
        this.sendPageView();

        // Attach click event listener
        document.addEventListener('click', this.handleClick.bind(this), true);

        // Attach outbound link click tracker
        document.addEventListener('click', this.handleOutboundClick.bind(this), true);

        // Attach mouse move event listener for move/attention heatmap data
        document.addEventListener('mousemove', this.handleMouseMove.bind(this), { passive: true });

        // Attach scroll event listener for scroll depth tracking
        window.addEventListener('scroll', this.handleScroll.bind(this), { passive: true });

        // Calculate initial scroll depth
        this.updateScrollDepth();

        // Record initial viewport position as first breakaway event
        // This captures the above-the-fold area even if user never scrolls
        // Subtract admin bar offset so coordinates match the page without admin bar
        var initialWindowHeight = window.innerHeight - this.adminBarOffset;
        var initialWidth = Math.max(
            document.documentElement.scrollWidth,
            document.body.scrollWidth,
            document.documentElement.clientWidth
        );
        var initialHeight = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight,
            document.documentElement.clientHeight
        );
        this.eventQueue.push({
            event: 'breakaway',
            device: this.isMobile ? 'mobile' : 'pc',
            x: 0,
            y: Math.floor(initialWindowHeight),
            width: Math.floor(initialWidth),
            height: Math.floor(initialHeight),
            windowHeight: Math.floor(initialWindowHeight),
            time: 0
        });
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Initial breakaway event recorded at y=' + initialWindowHeight, 'heatmap-simple');

        // Start periodic send timer
        this.startSendTimer();

        // Start heartbeat timer to update session duration and scroll depth
        this.startHeartbeat();

        // Flush pending click/scroll/move events AND send the final session-end
        // heartbeat before page unload / tab close / navigation, via the
        // guaranteed-delivery transport (sendBeacon, falling back to a keepalive
        // fetch()) — see finalizeSession(). Bound to BOTH 'pagehide' (fires
        // reliably on mobile Safari / bfcache navigations, where 'beforeunload'
        // is not guaranteed) and 'beforeunload' (kept for maximum browser
        // coverage); finalizeSession() itself is dedup-guarded so binding both
        // never double-sends.
        var finalizeSession = this.finalizeSession.bind(this);
        window.addEventListener('pagehide', finalizeSession);
        window.addEventListener('beforeunload', finalizeSession);

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Tracking started (clicks, moves, scroll, duration)', 'heatmap-simple');
    };
    
    /**
     * Handle mouse move events for move/attention heatmap data.
     * Throttled to avoid excessive data: records at most every 300ms and only
     * when the mouse has moved at least 30px from the last recorded position.
     */
    HeatmapTracker.prototype.handleMouseMove = function(event) {
        var now = Date.now();

        // Throttle by time
        if (now - this.lastMoveTime < this.moveThrottleMs) {
            return;
        }

        // Get coordinates, adjusting for admin bar offset
        var x = event.pageX || (event.clientX + (document.documentElement.scrollLeft || document.body.scrollLeft));
        var y = (event.pageY || (event.clientY + (document.documentElement.scrollTop || document.body.scrollTop))) - this.adminBarOffset;

        // Throttle by distance (minimum movement threshold)
        var dx = x - this.lastMoveX;
        var dy = y - this.lastMoveY;
        if (Math.sqrt(dx * dx + dy * dy) < this.moveMinDistance) {
            return;
        }

        // Update tracking state
        this.lastMoveTime = now;
        this.lastMoveX = x;
        this.lastMoveY = y;
        this.lastActivityTime = now;
        try {
            // Skip localStorage writes in anonymous mode (no storage persistence allowed)
            if ( window.optiBehaviorHeatmapConfig && window.optiBehaviorHeatmapConfig.privacy_mode === 'full' ) {
                localStorage.setItem('optibehavior_last_activity', now.toString());
            }
        } catch (e) {}

        // Get page dimensions
        var width = Math.max(
            document.documentElement.scrollWidth,
            document.body.scrollWidth,
            document.documentElement.clientWidth
        );
        var height = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight,
            document.documentElement.clientHeight
        );

        // Create event data - sent as 'attention' type which maps to 'moves' folder
        var eventData = {
            event: 'attention',
            device: this.isMobile ? 'mobile' : 'pc',
            x: Math.floor(x),
            y: Math.floor(y),
            width: Math.floor(width),
            height: Math.floor(height),
            time: 0
        };

        // Add to queue
        this.eventQueue.push(eventData);
    };

    HeatmapTracker.prototype.handleClick = function(event) {
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Click detected!', 'heatmap-simple', event);

        // Update activity timestamp (in memory; localStorage only in full tracking mode)
        this.lastActivityTime = Date.now();
        try {
            // Skip localStorage writes in anonymous mode (no storage persistence allowed)
            if ( window.optiBehaviorHeatmapConfig && window.optiBehaviorHeatmapConfig.privacy_mode === 'full' ) {
                localStorage.setItem('optibehavior_last_activity', this.lastActivityTime.toString());
            }
        } catch (e) {}

        // Get click coordinates, adjusting for admin bar offset so heatmap
        // clicks align with the page as viewed without the admin bar
        const x = event.pageX || (event.clientX + (document.documentElement.scrollLeft || document.body.scrollLeft));
        const y = (event.pageY || (event.clientY + (document.documentElement.scrollTop || document.body.scrollTop))) - this.adminBarOffset;

        // Get page dimensions
        const width = Math.max(
            document.documentElement.scrollWidth,
            document.body.scrollWidth,
            document.documentElement.clientWidth
        );
        const height = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight,
            document.documentElement.clientHeight
        );

        // Identify the clicked element (Top Clicked Elements stats).
        const elementInfo = getClickedElementInfo(event.target);

        // Element anchor + relative click offset (clicks only, D2). Uses the
        // raw (non-admin-bar-adjusted) page position so it lines up with
        // getBoundingClientRect(), which the admin bar offset never affects.
        // Additive only — never touches the absolute x/y above (D4 fallback).
        const anchor = computeElementAnchor(event.target, x, y + this.adminBarOffset);

        // Create event data
        const eventData = {
            event: 'click', // Send just 'click', PHP will add device suffix
            device: this.isMobile ? 'mobile' : 'pc',
            x: Math.floor(x),
            y: Math.floor(y),
            width: Math.floor(width),
            height: Math.floor(height),
            time: 0, // Will be calculated relative to send time
            element_tag: elementInfo.element_tag,
            element_id: elementInfo.element_id,
            element_class: elementInfo.element_class,
            element_text: elementInfo.element_text,
            element_type: elementInfo.element_type,
            element_builder: elementInfo.element_builder,
            element_selector: elementInfo.element_selector,
            element_href: elementInfo.element_href,
            element_xpath: anchor.element_xpath,
            element_rel_x: anchor.element_rel_x,
            element_rel_y: anchor.element_rel_y
        };

        // Add to queue
        this.eventQueue.push(eventData);

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Click recorded:', 'heatmap-simple', eventData);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Queue length:', 'heatmap-simple', this.eventQueue.length);

        // Check if we should send immediately
        const timeSinceLastSend = Date.now() - this.lastSendTime;
        const shouldSendNow = this.eventQueue.length >= this.config.ajax_bulk || 
                             timeSinceLastSend >= this.config.ajax_interval;
        
        if (shouldSendNow) {
            this.sendData(false);
        }
    };

    HeatmapTracker.prototype.handleOutboundClick = function(event) {
        // Check if click was on a link or within a link
        let target = event.target;
        let link = null;

        // Traverse up the DOM tree to find a link element
        while (target && target !== document) {
            if (target.tagName === 'A' && target.href) {
                link = target;
                break;
            }
            target = target.parentElement;
        }

        // If no link found, return
        if (!link) {
            return;
        }

        // Get the target URL
        const targetUrl = link.href;
        const sourceUrl = window.location.href;

        // Get click coordinates, adjusting for admin bar offset
        const x = event.pageX || (event.clientX + (document.documentElement.scrollLeft || document.body.scrollLeft));
        const y = (event.pageY || (event.clientY + (document.documentElement.scrollTop || document.body.scrollTop))) - this.adminBarOffset;

        // Get element details
        const elementTag = link.tagName.toLowerCase();
        const elementId = link.id || null;
        const elementClass = link.className || null;
        const elementText = link.textContent ? link.textContent.trim().substring(0, 500) : null;

        // Prepare outbound click data
        const outboundData = {
            type: 'outbound_click',
            sourceUrl: sourceUrl,
            targetUrl: targetUrl,
            elementTag: elementTag,
            elementId: elementId,
            elementClass: elementClass,
            elementText: elementText,
            x: Math.floor(x),
            y: Math.floor(y)
        };

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Outbound click detected:', 'heatmap-simple', outboundData);

        // Send outbound click data immediately
        this.sendOutboundClick(outboundData);
    };

    HeatmapTracker.prototype.sendOutboundClick = function(data) {
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Sending outbound click:', 'heatmap-simple', data);

        // Use in-memory IDs (set by sendPageView) — works in both anonymous and full modes.
        const sessionId = this.sessionId || '';
        const visitorId = this.visitorId || '';

        // Prepare JSON data for session recording endpoint
        const payload = {
            action: 'outbound_click',
            session_id: sessionId,
            visitor_id: visitorId,
            sourceUrl: data.sourceUrl,
            targetUrl: data.targetUrl,
            elementTag: data.elementTag,
            elementId: data.elementId || null,
            elementClass: data.elementClass || null,
            elementText: data.elementText || null,
            x: data.x,
            y: data.y
        };

        // Prepare form data for session recording endpoint
        const formData = new FormData();
        formData.append('action', 'opti_behavior_heatmap_record');
        formData.append('nonce', this.config.nonce);
        formData.append('data', JSON.stringify(payload));

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Outbound click payload:', 'heatmap-simple', payload);

        // Use sendBeacon for outbound clicks (more reliable when navigating away)
        // Fallback to fetch if sendBeacon is not supported
        if (navigator.sendBeacon) {
            try {
                const sent = navigator.sendBeacon(this.config.ajax_url, formData);
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Outbound click sent via sendBeacon:', 'heatmap-simple', sent);
            } catch(e) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('sendBeacon error, falling back to fetch:', 'heatmap-simple', e);
                // Fallback to fetch
                fetch(this.config.ajax_url, {
                    method: 'POST',
                    body: formData,
                    mode: 'same-origin',
                    cache: 'no-cache',
                    keepalive: true
                }).catch(function(error) {
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Outbound click fetch error:', 'heatmap-simple', error);
                });
            }
        } else {
            // Fallback to fetch with keepalive
            fetch(this.config.ajax_url, {
                method: 'POST',
                body: formData,
                mode: 'same-origin',
                cache: 'no-cache',
                keepalive: true
            }).then(function(response) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Outbound click sent via fetch, status:', 'heatmap-simple', response.status);
                return response.json();
            }).then(function(responseData) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Outbound click response:', 'heatmap-simple', responseData);
            }).catch(function(error) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Outbound click error:', 'heatmap-simple', error);
            });
        }
    };

    HeatmapTracker.prototype.handleScroll = function() {
        // Update activity timestamp (in memory; localStorage only in full tracking mode)
        this.lastActivityTime = Date.now();
        try {
            // Skip localStorage writes in anonymous mode (no storage persistence allowed)
            if ( window.optiBehaviorHeatmapConfig && window.optiBehaviorHeatmapConfig.privacy_mode === 'full' ) {
                localStorage.setItem('optibehavior_last_activity', this.lastActivityTime.toString());
            }
        } catch (e) {}
        this.updateScrollDepth();

        // Queue a breakaway (scroll) event for scroll heatmap data
        // Debounce: only record once every 2 seconds to avoid flooding
        var now = Date.now();
        if (now - this.lastScrollUpdate < 2000) {
            return;
        }
        this.lastScrollUpdate = now;

        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var windowHeight = window.innerHeight;
        var width = Math.max(
            document.documentElement.scrollWidth,
            document.body.scrollWidth,
            document.documentElement.clientWidth
        );
        var height = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight,
            document.documentElement.clientHeight
        );

        // Record the bottom of the visible viewport as the scroll position
        // This represents how far down the user has seen
        // Subtract admin bar offset so coordinates match the page without admin bar
        var scrollY = Math.floor(scrollTop + windowHeight) - this.adminBarOffset;

        var eventData = {
            event: 'breakaway',
            device: this.isMobile ? 'mobile' : 'pc',
            x: 0,
            y: scrollY,
            width: Math.floor(width),
            height: Math.floor(height),
            windowHeight: Math.floor(windowHeight - this.adminBarOffset),
            time: 0
        };

        this.eventQueue.push(eventData);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Scroll breakaway event recorded at y=' + scrollY, 'heatmap-simple');
    };

    HeatmapTracker.prototype.updateScrollDepth = function() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const windowHeight = window.innerHeight;
        const documentHeight = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight
        );

        const currentScrollDepth = Math.min(100, Math.round(
            ((scrollTop + windowHeight) / documentHeight) * 100
        ));

        if (currentScrollDepth > this.maxScrollDepth) {
            this.maxScrollDepth = currentScrollDepth;
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Max scroll depth updated:', 'heatmap-simple', this.maxScrollDepth + '%');
        }
    };

    /**
     * React to a dead admin-ajax endpoint (heatmap handler unregistered on a
     * cached page). Stops the periodic send + heartbeat timers and drops the
     * queue so no further requests are made. Idempotent.
     */
    HeatmapTracker.prototype.handleDeadEndpoint = function() {
        if (_deadEndpoint) { return; }
        _deadEndpoint = true;
        if (this.sendTimer) { clearInterval(this.sendTimer); this.sendTimer = null; }
        this.heartbeatStopped = true;
        if (this.heartbeatTimer) { clearTimeout(this.heartbeatTimer); this.heartbeatTimer = null; }
        this.eventQueue = [];
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Heatmap AJAX endpoint unavailable (HTTP 400 "0"); stopped heatmap tracker', 'heatmap-simple');
    };

    HeatmapTracker.prototype.startSendTimer = function() {
        // Clear existing timer
        if (this.sendTimer) {
            clearInterval(this.sendTimer);
        }

        // Start new timer
        this.sendTimer = setInterval(function() {
            if (this.eventQueue.length > 0) {
                this.sendData(false);
            }
        }.bind(this), this.config.ajax_interval);
    };

    HeatmapTracker.prototype.startHeartbeat = function() {
        // Clear existing timer
        if (this.heartbeatTimer) {
            clearTimeout(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }

        // Adaptive backoff: 15s -> 30s -> 60s -> 120s (cap). Uses a setTimeout
        // chain instead of setInterval so the delay can grow between beats.
        this.heartbeatStopped = false;
        this.heartbeatDelay = this.heartbeatBaseDelay;
        this.scheduleNextHeartbeat();
    };

    HeatmapTracker.prototype.scheduleNextHeartbeat = function() {
        this.heartbeatTimer = setTimeout(function() {
            this.sendHeartbeat();
            if (this.heartbeatStopped || _deadEndpoint) {
                this.heartbeatTimer = null;
                return;
            }
            // Exponential backoff, capped.
            this.heartbeatDelay = Math.min(this.heartbeatDelay * 2, this.heartbeatMaxDelay);
            this.scheduleNextHeartbeat();
        }.bind(this), this.heartbeatDelay);
    };

    HeatmapTracker.prototype.sendHeartbeat = function() {
        const now = Date.now();
        const timeSinceLastActivity = now - this.lastActivityTime;
        const totalSessionTime = now - this.sessionStartTime;

        // Stop heartbeat if user has been inactive for 30 minutes
        if (timeSinceLastActivity > this.inactivityTimeout) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session inactive for 30+ minutes, stopping heartbeat', 'heatmap-simple');
            this.heartbeatStopped = true;
            return;
        }

        // Stop heartbeat if session exceeds 1 hour (prevents inflated durations)
        if (totalSessionTime > this.maxSessionDuration) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session exceeded 1 hour max, stopping heartbeat', 'heatmap-simple');
            this.heartbeatStopped = true;
            return;
        }

        const sessionDuration = Math.round(totalSessionTime / 1000);

        // Use stored IDs instead of reading cookies
        if (!this.sessionId || !this.visitorId) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Heartbeat skipped - no session/visitor ID', 'heatmap-simple');
            return;
        }

        // No-change guard: nothing happened since the last heartbeat (no user
        // activity, no new scroll depth) — skip this beat entirely. The final
        // unload heartbeat still records the exact duration, so idle tabs cost
        // the server zero requests instead of one every few seconds.
        if (this.lastHeartbeatSentAt > 0 &&
            this.lastActivityTime <= this.lastHeartbeatSentAt &&
            this.maxScrollDepth === this.lastHeartbeatScrollDepth) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Heartbeat skipped - no activity since last beat', 'heatmap-simple');
            return;
        }
        this.lastHeartbeatSentAt = now;
        this.lastHeartbeatScrollDepth = this.maxScrollDepth;

        const sessionId = this.sessionId;
        const visitorId = this.visitorId;

        const formData = new FormData();
        formData.append('action', 'opti_behavior_heatmap_heartbeat');
        formData.append('nonce', this.config.nonce);
        formData.append('session_id', sessionId);
        formData.append('visitor_id', visitorId);
        formData.append('url', window.location.href);
        formData.append('title', document.title);
        formData.append('duration', sessionDuration);
        formData.append('scroll_depth', this.maxScrollDepth);

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Sending heartbeat - Duration:', 'heatmap-simple', sessionDuration + 's', 'Scroll:', this.maxScrollDepth + '%');

        fetch(this.config.ajax_url, {
            method: 'POST',
            body: formData,
            mode: 'same-origin',
            cache: 'no-cache',
            keepalive: true
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Heartbeat response:', 'heatmap-simple', data);
        }).catch(function(error) {
            // A heartbeat fetch in flight when the user navigates away is aborted by
            // the browser ("TypeError: Failed to fetch" / AbortError). With keepalive:true
            // the request itself is still delivered - only reading the response fails -
            // so this is expected noise, not an error. Heartbeats also self-heal: the
            // next one fires on the backoff schedule. Log quietly instead of red console errors.
            if (!window.OptiBehaviorDebug) { return; }
            const navigatingAway = this._finalized || document.visibilityState === 'hidden';
            const isAbort = error && (error.name === 'AbortError' ||
                (error.name === 'TypeError' && String(error.message).indexOf('fetch') !== -1));
            if (navigatingAway || isAbort) {
                window.OptiBehaviorDebug.debug('Heartbeat aborted by navigation (harmless, keepalive delivered):', 'heatmap-simple', error && error.message);
            } else {
                window.OptiBehaviorDebug.warning('Heartbeat failed (will retry on next beat):', 'heatmap-simple', error && error.message);
            }
        }.bind(this));
    };

    /**
     * Flush any pending click/scroll/move events and send the final
     * session-end heartbeat, both via guaranteed-delivery transport
     * (navigator.sendBeacon, falling back to a keepalive fetch()) so
     * engagement data is not lost to the classic "plain fetch() aborted by
     * unload" race. Bound to 'pagehide'/'beforeunload' by start(); idempotent
     * via the _finalized guard so it only runs once per page load even if
     * both events fire.
     *
     * Event flush is sent first: this.sendData(true) exercises the same
     * sendBeacon/keepalive-fetch path as the heartbeat below, but per-request
     * delivery order between the two beacons is NOT guaranteed by the spec.
     * That's fine — if the heartbeat's is_final=1 verdict is computed before
     * the click/scroll events land, the server self-heals: any events that
     * DO land afterwards re-trigger a downgrade-only reclassification (see
     * ajax_opti_behavior_heatmap() in class-opti-behavior-heatmap-ajax-handler.php),
     * which can only correct spam -> human, never the reverse.
     */
    HeatmapTracker.prototype.finalizeSession = function() {
        if (this._finalized) { return; }
        this._finalized = true;

        this.sendData(true);
        this.sendSessionEnd();
    };

    HeatmapTracker.prototype.sendSessionEnd = function() {

        const sessionDuration = Math.round((Date.now() - this.sessionStartTime) / 1000);

        // Use stored IDs instead of reading cookies
        if (!this.sessionId || !this.visitorId) {
            return;
        }

        const sessionId = this.sessionId;
        const visitorId = this.visitorId;

        const formData = new FormData();
        formData.append('action', 'opti_behavior_heatmap_heartbeat');
        formData.append('nonce', this.config.nonce);
        formData.append('session_id', sessionId);
        formData.append('visitor_id', visitorId);
        formData.append('url', window.location.href);
        formData.append('title', document.title);
        formData.append('duration', sessionDuration);
        formData.append('scroll_depth', this.maxScrollDepth);
        // Mark this heartbeat as the session-end beacon so the server finalizes the
        // spam verdict. Periodic heartbeats omit this flag and only ever downgrade a
        // transiently-flagged session to human, never mark it spam mid-session.
        formData.append('is_final', '1');

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session end - Duration:', 'heatmap-simple', sessionDuration + 's', 'Scroll:', this.maxScrollDepth + '%');

        // Use sendBeacon for reliable delivery on page unload; fall back to a
        // keepalive fetch() if sendBeacon is unavailable or rejects the
        // payload (e.g. the browser's per-origin beacon quota is exceeded) —
        // a keepalive fetch() also survives the originating document being
        // discarded/navigated away from, unlike a plain, non-keepalive fetch().
        var beaconSent = false;
        if (navigator.sendBeacon) {
            try {
                beaconSent = navigator.sendBeacon(this.config.ajax_url, formData);
            } catch (e) {
                beaconSent = false;
            }
        }
        if (!beaconSent) {
            fetch(this.config.ajax_url, {
                method: 'POST',
                body: formData,
                mode: 'same-origin',
                cache: 'no-cache',
                keepalive: true
            }).catch(function(error) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Session-end fallback fetch error:', 'heatmap-simple', error);
            });
        }
    };
    
    HeatmapTracker.prototype.sendData = function(isUnload) {
        // Dead endpoint detected earlier this page load: send nothing.
        if (_deadEndpoint) { this.eventQueue = []; return; }
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('sendData() called, isUnload:', 'heatmap-simple', isUnload);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Queue length:', 'heatmap-simple', this.eventQueue.length);

        if (this.eventQueue.length === 0) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('No events to send', 'heatmap-simple');
            return;
        }

        // Get events to send
        const eventsToSend = this.eventQueue.splice(0);

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Sending events:', 'heatmap-simple', eventsToSend);

        // Update last send time
        this.lastSendTime = Date.now();

        // Use in-memory IDs (set by sendPageView) — works in both anonymous and full modes.
        const sessionId = this.sessionId || null;
        const visitorId = this.visitorId || null;

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session ID:', 'heatmap-simple', sessionId);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Visitor ID:', 'heatmap-simple', visitorId);

        // Prepare form data
        const formData = new FormData();
        formData.append('action', this.config.action);
        formData.append('nonce', this.config.nonce);
        formData.append('url', window.location.href);
        formData.append('title', document.title);

        // Add session and visitor IDs (sendData already returns early in anonymous mode).
        if ( sessionId ) {
            formData.append('session_id', sessionId);
        }
        if ( visitorId ) {
            formData.append('visitor_id', visitorId);
        }

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('AJAX config:', 'heatmap-simple', {
            action: this.config.action,
            nonce: this.config.nonce,
            ajax_url: this.config.ajax_url,
            url: window.location.href,
            title: document.title
        });

        // Add screen resolution data for visitor tracking
        formData.append('screen_width', window.screen.width);
        formData.append('screen_height', window.screen.height);

        // Pass logged-in state from page-render config (reliable)
        // so the server can correctly mark heatmap files as L/G
        if ( this.config.is_logged_in ) {
            formData.append('is_logged_in', '1');
        }

        // Add referrer information from config or document.referrer
        // Use optiBehaviorHeatmapConfig if available, otherwise use config
        const trackingConfig = window.optiBehaviorHeatmapConfig || this.config;

        // Send referrer - filter out internal referrers (same domain = not an external referral)
        let referrer = document.referrer || trackingConfig.referrer || '';
        if (referrer) {
            try {
                var referrerHost = new URL(referrer).hostname;
                var currentHost = window.location.hostname;
                // Strip www. prefix for robust comparison
                if (referrerHost.replace(/^www\./i, '') === currentHost.replace(/^www\./i, '')) {
                    referrer = ''; // Internal referrer, treat as Direct
                }
            } catch (e) {
                // Invalid URL, keep as-is
            }
        }

        if (referrer) {
            formData.append('referrer', referrer);
        }

        // Send UTM parameters if available
        if (trackingConfig.utm_source) {
            formData.append('utm_source', trackingConfig.utm_source);
        }
        if (trackingConfig.utm_medium) {
            formData.append('utm_medium', trackingConfig.utm_medium);
        }
        if (trackingConfig.utm_campaign) {
            formData.append('utm_campaign', trackingConfig.utm_campaign);
        }
        if (trackingConfig.utm_term) {
            formData.append('utm_term', trackingConfig.utm_term);
        }
        if (trackingConfig.utm_content) {
            formData.append('utm_content', trackingConfig.utm_content);
        }

        // Include A/B test variant info if visitor is in an active test.
        // window.optiBehaviorAB is set by the A/B test renderer on the frontend.
        if ( typeof window.optiBehaviorAB !== 'undefined' && window.optiBehaviorAB.length > 0 ) {
            formData.append('ab_test_id', window.optiBehaviorAB[0].test_id);
            formData.append('variant_id', window.optiBehaviorAB[0].variant_id);
        }

        // Add event data
        eventsToSend.forEach(function(event, index) {
            formData.append('data[' + index + '][event]', event.event);
            formData.append('data[' + index + '][device]', event.device); // FIX: Add device field
            formData.append('data[' + index + '][x]', event.x);
            formData.append('data[' + index + '][y]', event.y);
            formData.append('data[' + index + '][width]', event.width);
            formData.append('data[' + index + '][height]', event.height);
            formData.append('data[' + index + '][time]', event.time);
            if (event.windowHeight) {
                formData.append('data[' + index + '][windowHeight]', event.windowHeight);
            }
            // Element identification (Top Clicked Elements). Only click events
            // carry these; server ignores absent fields (old cached JS safe).
            if (event.element_selector) {
                formData.append('data[' + index + '][element_tag]', event.element_tag || '');
                formData.append('data[' + index + '][element_id]', event.element_id || '');
                formData.append('data[' + index + '][element_class]', event.element_class || '');
                formData.append('data[' + index + '][element_text]', event.element_text || '');
                formData.append('data[' + index + '][element_type]', event.element_type || '');
                formData.append('data[' + index + '][element_builder]', event.element_builder || '');
                formData.append('data[' + index + '][element_selector]', event.element_selector);
                if (event.element_href) {
                    formData.append('data[' + index + '][element_href]', event.element_href);
                }
            }
            // Element anchor (element-anchored heatmap reprojection). Only
            // click events carry these; the server validates presence + range
            // and legacy servers simply ignore the extra fields.
            if (event.element_xpath &&
                typeof event.element_rel_x === 'number' && isFinite(event.element_rel_x) &&
                typeof event.element_rel_y === 'number' && isFinite(event.element_rel_y)) {
                formData.append('data[' + index + '][element_xpath]', event.element_xpath);
                formData.append('data[' + index + '][element_rel_x]', String(event.element_rel_x));
                formData.append('data[' + index + '][element_rel_y]', String(event.element_rel_y));
            }
        });

        // if (this.config.debug) {
        //     window.OptiBehaviorDebug.debug('Sending ', 'heatmap-simple'+ eventsToSend.length + ' events to server');
        //     window.OptiBehaviorDebug.debug('Referrer:', 'heatmap-simple', referrer);
        // }

        // Send data
        if (isUnload) {
            // Guaranteed-delivery path for unload/navigation flushes (see
            // finalizeSession()): try sendBeacon first — per spec it is
            // delivered even as the document unloads, unlike a plain,
            // non-keepalive fetch(), which the Fetch spec allows the browser
            // to abort when its originating document is discarded. Fall
            // through to the keepalive fetch() below (isUnload=true =>
            // keepalive: true) if sendBeacon is unavailable, throws, or
            // rejects the payload (e.g. per-origin beacon size quota
            // exceeded on an unusually large queue) — that keepalive fetch()
            // also survives the page being torn down.
            var beaconSent = false;
            if (navigator.sendBeacon) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Using sendBeacon', 'heatmap-simple');
                try {
                    beaconSent = navigator.sendBeacon(this.config.ajax_url, formData);
                } catch (e) {
                    beaconSent = false;
                }
            }
            if (beaconSent) {
                return;
            }
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('sendBeacon unavailable/failed, falling back to keepalive fetch', 'heatmap-simple');
        }

        // Normal (non-unload) sends, and the isUnload fallback above when
        // sendBeacon isn't available/failed: keepalive: isUnload means this
        // fetch() survives page unload/navigation for the fallback case,
        // exactly like the normal-case fetch behaves unchanged (keepalive:
        // false) — zero regression for the common non-unload path.
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Using fetch to send data', 'heatmap-simple');
        fetch(this.config.ajax_url, {
            method: 'POST',
            body: formData,
            mode: 'same-origin',
            cache: 'no-cache',
            keepalive: isUnload
        }).then(function(response) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Server response status:', 'heatmap-simple', response.status);
            // Handle nonce expiry on cached pages: refresh nonce and re-queue
            // events. Re-queue runs inside the ensureFreshNonce callback so a
            // click batch that 403s WHILE a page-view refresh is already in
            // flight is preserved (not dropped) — it is put back on the next
            // fresh nonce and re-sent by the next periodic timer interval.
            if ( response.status === 403 ) {
                if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug( 'Nonce expired (403), refreshing nonce and re-queuing events', 'heatmap-simple' );
                var _selfReq = this;
                ensureFreshNonce( this.config, function( ok ) {
                    if ( ok && eventsToSend && eventsToSend.length > 0 ) {
                        // Re-add the unsent events to the front of the queue so
                        // the next periodic timer interval re-sends them with
                        // the fresh nonce.
                        Array.prototype.unshift.apply( _selfReq.eventQueue, eventsToSend );
                        if ( window.OptiBehaviorDebug ) {
                            window.OptiBehaviorDebug.debug( 'Nonce refreshed; re-queued ' + eventsToSend.length + ' events', 'heatmap-simple' );
                        }
                    }
                } );
                return null; // Skip JSON parsing; events re-sent on next interval
            }
            // Permanent dead endpoint (handler unregistered on a cached page):
            // HTTP 400 + body exactly "0". Stop the tracker, no retry.
            if ( response.status === 400 ) {
                var _self400 = this;
                return response.text().then(function(text) {
                    if ( isDeadEndpoint( 400, text ) ) { _self400.handleDeadEndpoint(); }
                    return null;
                });
            }
            return response.json();
        }.bind(this)).then(function(data) {
            if ( !data ) { return; } // Nonce refresh was triggered; skip handler
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Server response data:', 'heatmap-simple', data);
        }.bind(this)).catch(function(error) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Send error:', 'heatmap-simple', error);
        });
    };

    /**
     * Send initial page view to create session immediately on page load
     * This ensures sessions are tracked even if user doesn't click anything
     */
    /**
     * Detect browser from user agent
     * Order matters! Check specific browsers before generic ones
     * Chromium-based browsers must be checked before Chrome since they include "Chrome" in UA
     */
    HeatmapTracker.prototype.detectBrowser = function(userAgent) {
        if (!userAgent) return 'Other';

        if (navigator.brave && typeof navigator.brave.isBrave === 'function') return 'Brave';
        if (/Edg|Edge/i.test(userAgent)) return 'Edge';
        if (/OPR|Opera|OPiOS/i.test(userAgent)) return 'Opera';
        if (/Vivaldi/i.test(userAgent)) return 'Vivaldi';
        if (/YaBrowser/i.test(userAgent)) return 'Yandex';
        if (/SamsungBrowser/i.test(userAgent)) return 'Samsung Internet';
        if (/UCBrowser|UCWEB/i.test(userAgent)) return 'UC Browser';
        if (/DuckDuckGo/i.test(userAgent)) return 'DuckDuckGo';
        if (/HuaweiBrowser/i.test(userAgent)) return 'Huawei Browser';
        if (/MiuiBrowser/i.test(userAgent)) return 'Mi Browser';
        if (/QQBrowser/i.test(userAgent)) return 'QQ Browser';
        if (/Whale/i.test(userAgent)) return 'Naver Whale';
        if (/Maxthon/i.test(userAgent)) return 'Maxthon';
        if (/PaleMoon/i.test(userAgent)) return 'Pale Moon';
        if (/Waterfox/i.test(userAgent)) return 'Waterfox';
        if (/FxiOS|Firefox/i.test(userAgent)) return 'Firefox';
        if (/CriOS|Chrome|Chromium/i.test(userAgent)) return 'Chrome';
        if (/MSIE|Trident/i.test(userAgent)) return 'Internet Explorer';
        if (/Instagram/i.test(userAgent)) return 'Instagram In-App Browser';
        if (/FBAN|FBAV/i.test(userAgent)) return 'Facebook In-App Browser';
        if (/Safari/i.test(userAgent)) return 'Safari';

        return 'Other';
    };

    /**
     * Detect operating system from user agent
     * Order matters - check specific patterns before generic ones
     */
    HeatmapTracker.prototype.detectOS = function(userAgent) {
        if (!userAgent) return 'Other';

        // Check iOS first (iPhone/iPad contain "Mac" in some cases)
        if (userAgent.indexOf('iPhone') > -1 || userAgent.indexOf('iPad') > -1 || userAgent.indexOf('iPod') > -1) return 'iOS';
        // Android before Linux (Android contains "Linux")
        if (userAgent.indexOf('Android') > -1) return 'Android';
        // Windows
        if (userAgent.indexOf('Windows NT') > -1 || userAgent.indexOf('Windows Phone') > -1) return 'Windows';
        // macOS (after iOS check)
        if (userAgent.indexOf('Mac OS X') > -1 || userAgent.indexOf('Macintosh') > -1) return 'macOS';
        // Chrome OS
        if (userAgent.indexOf('CrOS') > -1) return 'Chrome OS';
        // HarmonyOS (Huawei)
        if (userAgent.indexOf('HarmonyOS') > -1) return 'HarmonyOS';
        // Linux (generic, after Android)
        if (userAgent.indexOf('Linux') > -1) return 'Linux';
        // Detect bots
        if (/bot|crawl|spider|slurp|googlebot|bingbot|yandex|baidu/i.test(userAgent)) return 'Bot';

        return 'Other';
    };

    /**
     * Detect device type from user agent and screen width
     */
    HeatmapTracker.prototype.detectDeviceType = function(userAgent, screenWidth) {
        if (!userAgent) return 'desktop';

        // Check for mobile indicators in user agent
        const mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 'IEMobile', 'Opera Mini'];
        const isMobileUA = mobileKeywords.some(function(keyword) {
            return userAgent.indexOf(keyword) > -1;
        });

        // Check for tablet indicators
        const isTablet = userAgent.indexOf('iPad') > -1 ||
                        (userAgent.indexOf('Android') > -1 && userAgent.indexOf('Mobile') === -1);

        if (isTablet) return 'tablet';
        if (isMobileUA) return 'mobile';

        // Fallback to screen width detection
        if (screenWidth <= 768) return 'mobile';
        if (screenWidth <= 1024) return 'tablet';

        return 'desktop';
    };

    HeatmapTracker.prototype.sendPageView = function() {
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('sendPageView() called - creating session', 'heatmap-simple');

        const now = Date.now();
        const SESSION_TIMEOUT = 1800000; // 30 minutes in milliseconds

        // Resolve privacy mode from the localized config object.
        // Default to 'anonymous' (GDPR-safe) when the key is absent.
        const trackingConfig = window.optiBehaviorHeatmapConfig || this.config;
        const privacyMode = (trackingConfig && trackingConfig.privacy_mode) ? trackingConfig.privacy_mode : 'anonymous';
        const isAnonymous = privacyMode !== 'full';

        let sessionId = null;
        let visitorId = null;

        if (isAnonymous) {
            // Anonymous mode: ZERO cookies, ZERO sessionStorage/localStorage for
            // identity (GDPR / UK PECR — see spec §4). Cross-tab continuity comes
            // from the shared in-memory broker (BroadcastChannel adoption + a
            // deterministic server-derived session seed). Visitor id is the server
            // daily-rotating hash (or wp_user_<id>), localized into the config —
            // JS never mints a random, storable id.
            var broker = window.OptiBehaviorAnonBroker;
            if (broker) {
                // One-time: expire any legacy anon cookies from older plugin
                // versions. Deleting a cookie is not "storing" information (PECR-safe).
                broker.cleanupStaleCookies();
                broker.init({
                    vid: (trackingConfig && trackingConfig.anon_vid) ? trackingConfig.anon_vid : '',
                    sidSeed: (trackingConfig && trackingConfig.anon_sid_seed) ? trackingConfig.anon_sid_seed : '',
                    channelName: 'optibehavior_anon'
                });
                visitorId = broker.getVisitorId();
                sessionId = broker.getSessionId();
                // Adopt a sibling tab's sid if one replies inside the adoption
                // window, so events sent after page-view carry the shared sid.
                var selfRef = this;
                broker.onSessionId(function(newSid) { selfRef.sessionId = newSid; });
            }
            // Storage-free fallbacks when the broker script is unavailable — still
            // never mint a random storable id; keep the server hash authoritative.
            if (!visitorId) {
                var wpUserId = (trackingConfig && trackingConfig.wp_user_id) ? trackingConfig.wp_user_id : '';
                visitorId = (trackingConfig && trackingConfig.anon_vid) ? trackingConfig.anon_vid : (wpUserId || '');
            }
            if (!sessionId) {
                sessionId = (trackingConfig && trackingConfig.anon_sid_seed) ? trackingConfig.anon_sid_seed : '';
            }
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Anonymous mode — cookieless broker identity (cross-tab, GDPR-safe)', 'heatmap-simple', { sessionId: sessionId, visitorId: visitorId });
        } else {
            // Full tracking mode: existing cookie/localStorage behavior (unchanged).

            // -----------------------------------------------------------------------
            // Consent-transition migration: anonymous → full
            // When a visitor browsed in anonymous mode and then accepted consent, the
            // page reloads in full mode.  The anonymous tracker stores IDs in
            // GDPR-safe session cookies plus sessionStorage.  Full mode normally
            // reads different cookie/localStorage keys and can otherwise create
            // brand-new IDs, splitting one visitor flow across two sessions.
            //
            // Fix: before touching cookies/localStorage, check the anonymous cookie
            // keys first, then sessionStorage. If found, migrate them into the full
            // keys so that the existing code below picks them up.
            // -----------------------------------------------------------------------
            (function migrateAnonIds() {
                function readCookie( name ) {
                    var nameEQ = name + '=';
                    var parts = document.cookie.split( ';' );
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

                function expireCookie( name ) {
                    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
                    document.cookie = name + '=; path=/; max-age=0; SameSite=Lax' + secure;
                }

                // Anonymous Mode no longer writes anon cookies or sessionStorage,
                // so there is nothing to migrate for current-version anonymous
                // sessions (their identity is the server hash, which full mode
                // re-derives). We still READ legacy anon cookies left by older
                // plugin versions and fold them into the full-mode keys. This runs
                // ONLY after consent is granted (full branch), so cookie access is
                // permitted. No sessionStorage access — zero anon client storage.
                var anonSid = readCookie( 'optibehavior_anon_sid' );
                var anonVid = readCookie( 'optibehavior_anon_vid' );

                if ( anonSid ) {
                    // Migrate anonymous session ID → cookie (same format full mode uses).
                    document.cookie = 'optibehavior_sid=' + anonSid + '; path=/; max-age=1800; SameSite=Lax';
                    try {
                        localStorage.setItem( 'optibehavior_last_activity', now.toString() );
                        if ( ! localStorage.getItem( 'optibehavior_session_start' ) ) {
                            localStorage.setItem( 'optibehavior_session_start', now.toString() );
                        }
                    } catch(e) {}
                    expireCookie( 'optibehavior_anon_sid' );
                    if ( window.OptiBehaviorDebug ) {
                        window.OptiBehaviorDebug.debug( 'Consent transition: migrated anonymous session ID to cookie', 'heatmap-simple', anonSid );
                    }
                }

                if ( anonVid ) {
                    // Migrate anonymous visitor ID → localStorage (same location full mode uses).
                    try {
                        localStorage.setItem( 'optibehavior_vid', anonVid );
                    } catch(e) {
                        // localStorage unavailable — fall back to cookie.
                        document.cookie = 'optibehavior_vid=' + anonVid + '; path=/; max-age=31536000; SameSite=Lax';
                    }
                    expireCookie( 'optibehavior_anon_vid' );
                    if ( window.OptiBehaviorDebug ) {
                        window.OptiBehaviorDebug.debug( 'Consent transition: migrated anonymous visitor ID to localStorage', 'heatmap-simple', anonVid );
                    }
                }
            }());

            // Get session ID from cookie (short-lived, per session)
            sessionId = this.getCookie('optibehavior_sid') || this.getCookie('opti_behavior_session_id');

            // CRITICAL FIX: Check session activity timeout from localStorage
            // Cookie max-age alone doesn't track inactivity - need to validate against last activity
            let lastActivityTime = null;
            try {
                lastActivityTime = parseInt(localStorage.getItem('optibehavior_last_activity') || '0');
            } catch (e) {
                lastActivityTime = 0;
            }

            // Also check Pro's session data for activity (unified activity tracking)
            let proLastActivity = 0;
            try {
                const proSessionData = localStorage.getItem('opti_behavior_pro_session_data');
                if (proSessionData) {
                    const parsed = JSON.parse(proSessionData);
                    proLastActivity = parsed.lastActivity || 0;
                    // If Pro has a session, use the same session ID for consistency
                    if (parsed.sessionId && !sessionId) {
                        sessionId = parsed.sessionId;
                        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Using Pro session ID:', 'heatmap-simple', sessionId);
                    }
                }
            } catch (e) {}

            // Use the most recent activity time from either source
            lastActivityTime = Math.max(lastActivityTime, proLastActivity);

            // If session exists but last activity was more than 30 min ago, expire the session
            if (sessionId && lastActivityTime > 0) {
                const timeSinceLastActivity = now - lastActivityTime;
                if (timeSinceLastActivity >= SESSION_TIMEOUT) {
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session timed out after 30 min inactivity, creating new session', 'heatmap-simple');
                    sessionId = null; // Force new session creation
                    // Clear old session data
                    try {
                        localStorage.removeItem('optibehavior_session_start');
                        // Also clear Pro session data for consistency
                        localStorage.removeItem('opti_behavior_pro_session_data');
                    } catch (e) {}
                }
            }

            // Logged-in WP user: canonical visitor id is always wp_user_<id>.
            // Mirror the anonymous-mode block so the AB tracker (which reads
            // localStorage first) ships the same stable id regardless of privacy mode.
            // This prevents Cause A (JS full-mode race) from inflating visitor counts.
            const wpUserIdFull = (trackingConfig && trackingConfig.wp_user_id) ? trackingConfig.wp_user_id : '';
            if (wpUserIdFull) {
                visitorId = wpUserIdFull;
                // Write canonical id to localStorage so ab-test-tracker.js reads it too.
                try { localStorage.setItem('optibehavior_vid', visitorId); } catch (e) {}
            } else {
                // Get visitor ID from localStorage (persistent, survives bounce tracker protection)
                // Fallback to cookie for backwards compatibility
                try {
                    visitorId = localStorage.getItem('optibehavior_vid') || this.getCookie('optibehavior_vid') || this.getCookie('opti_behavior_visitor_id');
                } catch (e) {
                    // localStorage not available (privacy mode, old browsers), fallback to cookie
                    visitorId = this.getCookie('optibehavior_vid') || this.getCookie('opti_behavior_visitor_id');
                }
            }

            // Generate IDs if they don't exist
            if (!sessionId) {
                sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substring(7);
                document.cookie = 'optibehavior_sid=' + sessionId + '; path=/; max-age=1800; SameSite=Lax'; // 30 min
                // Store session start time for accurate duration calculation
                try {
                    localStorage.setItem('optibehavior_session_start', now.toString());
                } catch (e) {}
            }

            // Update last activity timestamp (used by both Free and Pro trackers)
            try {
                localStorage.setItem('optibehavior_last_activity', now.toString());
            } catch (e) {}

            if (!visitorId) {
                visitorId = 'visitor_' + Date.now() + '_' + Math.random().toString(36).substring(7);
                // Store visitor ID in localStorage (immune to bounce tracker protection)
                try {
                    localStorage.setItem('optibehavior_vid', visitorId);
                } catch (e) {
                    // localStorage not available, fallback to cookie
                    document.cookie = 'optibehavior_vid=' + visitorId + '; path=/; max-age=31536000; SameSite=Lax'; // 1 year
                }
            }
        }

        // Store IDs in memory for reliable access throughout the session
        this.sessionId = sessionId;
        this.visitorId = visitorId;

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Page view - Session ID:', 'heatmap-simple', sessionId);
        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Page view - Visitor ID:', 'heatmap-simple', visitorId);

        // Detect device information
        const userAgent = navigator.userAgent;
        const screenWidth = window.screen.width;
        const browser = this.detectBrowser(userAgent);
        const os = this.detectOS(userAgent);
        const deviceType = this.detectDeviceType(userAgent, screenWidth);

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Device info - Browser:', 'heatmap-simple', browser, 'OS:', os, 'Type:', deviceType);

        // Build the data object matching server's expected format
        const data = {
            action: 'session_start',
            sessionId: sessionId,
            visitorId: visitorId,
            url: window.location.href,
            title: document.title,
            userAgent: userAgent,
            language: navigator.language || navigator.userLanguage || '',
            timezone: (typeof Intl !== 'undefined' && Intl.DateTimeFormat) ? (Intl.DateTimeFormat().resolvedOptions().timeZone || '') : '',
            screen_width: screenWidth,
            screen_height: window.screen.height,
            referrer: (function() {
                var ref = document.referrer || trackingConfig.referrer || '';
                if (ref) {
                    try {
                        var refHost = new URL(ref).hostname.replace(/^www\./i, '');
                        var curHost = window.location.hostname.replace(/^www\./i, '');
                        if (refHost === curHost) { return ''; } // Internal referrer, treat as Direct
                    } catch (e) {}
                }
                return ref;
            })(),
            utm_source: trackingConfig.utm_source || '',
            utm_medium: trackingConfig.utm_medium || '',
            utm_campaign: trackingConfig.utm_campaign || '',
            utm_term: trackingConfig.utm_term || '',
            utm_content: trackingConfig.utm_content || '',
            entry_page: window.location.href,
            device: {
                browser: browser,
                os: os,
                deviceType: deviceType
            }
        };

        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'opti_behavior_heatmap_record');
        formData.append('nonce', this.config.nonce);
        formData.append('data', JSON.stringify(data));

        if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Sending initial session start:', 'heatmap-simple', data);

        // Send page view data immediately
        var self = this;
        fetch(this.config.ajax_url, {
            method: 'POST',
            body: formData,
            mode: 'same-origin',
            cache: 'no-cache'
        }).then(function(response) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session start response status:', 'heatmap-simple', response.status);
            // Handle nonce expiry on cached pages. This is the session-start /
            // page-view send — the record that creates the session row the
            // customer's traffic count is built from. On a stale-nonce 403 we
            // MUST re-send this exact payload after the refresh, otherwise a
            // bounce visitor (one page, no click) is never recorded and the
            // traffic number craters. Guard with a single-shot flag so the
            // re-send cannot loop if the fresh nonce is also rejected.
            if ( response.status === 403 ) {
                if ( !self._pageViewNonceRetried ) {
                    self._pageViewNonceRetried = true;
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug( 'Nonce expired (403) on session start, refreshing nonce and re-sending page view', 'heatmap-simple' );
                    ensureFreshNonce( self.config, function( ok ) {
                        if ( ok ) {
                            // Re-send the page view once with the fresh nonce.
                            // track_session_start() dedups by session_id, so a
                            // retry cannot create a duplicate session.
                            self.sendPageView();
                        }
                    } );
                }
                return null;
            }
            // Permanent dead endpoint (handler unregistered on a cached page):
            // HTTP 400 + body exactly "0". Stop the tracker, no retry.
            if ( response.status === 400 ) {
                return response.text().then(function(text) {
                    if ( isDeadEndpoint( 400, text ) ) { self.handleDeadEndpoint(); }
                    return null;
                });
            }
            return response.json();
        }).then(function(responseData) {
            if ( !responseData ) { return; }
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Session start response data:', 'heatmap-simple', responseData);
            // Fix A: Use server-authoritative visitor_id to eliminate multi-tab race condition.
            // The PHP backend computes a deterministic hash (IP + UA + daily salt) that is
            // identical for every tab of the same browser — no race possible server-side.
            // We overwrite the tentative JS-generated ID with the canonical server value so
            // that all subsequent heartbeats, click events, and outbound-link beacons carry
            // the correct, deduplicated visitor_id.
            if ( responseData.success && responseData.data && responseData.data.visitor_id ) {
                var serverVisitorId = responseData.data.visitor_id;
                if ( serverVisitorId !== self.visitorId ) {
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Updating visitorId with server-authoritative value:', 'heatmap-simple', serverVisitorId);
                    self.visitorId = serverVisitorId;
                }
                // Persist the authoritative ID back to the matching storage layer so that
                // the next page load (and the Pro recorder) read the correct value without
                // needing another server round-trip.
                if ( isAnonymous ) {
                    // Anonymous mode: NO persistence. The visitor id is already the
                    // server daily-rotating hash (localized as anon_vid), so there is
                    // nothing to write back — zero cookies, zero client storage.
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Anonymous mode — server visitor id kept in memory only', 'heatmap-simple', serverVisitorId);
                } else {
                    // Full mode: localStorage preferred; cookie fallback (private browsing)
                    try {
                        localStorage.setItem('optibehavior_vid', serverVisitorId);
                    } catch(e) {
                        document.cookie = 'optibehavior_vid=' + serverVisitorId + '; path=/; max-age=31536000; SameSite=Lax';
                    }
                }
            }
            // Cache-safe session id convergence (spec §2.3). On a full-page-cached
            // page the localized anon_sid_seed is frozen, so the session-start
            // ingest recomputes the per-visitor session id server-side and returns
            // it here. Adopt it so every subsequent event (clicks, heartbeats,
            // outbound beacons) and every other tracker on the page (funnel, Pro
            // recorder/errors/forms via the shared broker) key to the same
            // authoritative session as the DB row just created.
            if ( responseData.success && responseData.data && responseData.data.session_id ) {
                var serverSessionId = responseData.data.session_id;
                if ( serverSessionId !== self.sessionId ) {
                    if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.debug('Updating sessionId with server-authoritative value:', 'heatmap-simple', serverSessionId);
                    self.sessionId = serverSessionId;
                }
                // Push the authoritative sid into the shared cookieless broker so
                // subscribed trackers converge in-memory (no cookie, no storage).
                if ( isAnonymous && window.OptiBehaviorAnonBroker
                    && typeof window.OptiBehaviorAnonBroker.setSessionId === 'function' ) {
                    window.OptiBehaviorAnonBroker.setSessionId( serverSessionId );
                }
            }
        }).catch(function(error) {
            if (window.OptiBehaviorDebug) window.OptiBehaviorDebug.error('Session start error:', 'heatmap-simple', error);
        });
    };

    // Test hook (part 2) — exposed AFTER the HeatmapTracker prototype methods
    // are assigned so QA harnesses can construct a tracker and drive the
    // page-view / click nonce-refresh-and-retry paths without booting init().
    // Not a public API. See tests/pageview-nonce-403-retry-test.js.
    if ( typeof window !== 'undefined' ) {
        window.__optiBehaviorTestables = window.__optiBehaviorTestables || {};
        window.__optiBehaviorTestables.HeatmapTracker = HeatmapTracker;
        window.__optiBehaviorTestables.ensureFreshNonce = ensureFreshNonce;
        window.__optiBehaviorTestables.isHeatmapNonceRefreshStarted = function() { return _nonceRefreshStarted; };
        window.__optiBehaviorTestables.isHeatmapNonceRefreshDone = function() { return _nonceRefreshDone; };
    }

})();

