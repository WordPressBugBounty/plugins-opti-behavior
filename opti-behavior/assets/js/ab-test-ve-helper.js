/**
 * A/B Test Visual Editor — Iframe Helper (Pro)
 *
 * Injected into the target page when loaded in the visual editor iframe.
 * Handles element selection, highlighting, change application (text, HTML,
 * CSS, image, duplicate, move, insert), origin detection, and cross-frame
 * communication with the parent editor.
 *
 * @package opti-behavior-pro
 * @since   1.3.0
 */

/* global optiBehaviorVEHelper */
(function () {
    'use strict';

    var VEHS = (typeof optiBehaviorVEHelper !== 'undefined' && optiBehaviorVEHelper.strings)
        ? optiBehaviorVEHelper.strings
        : {};

    var Helper = {
        mode: 'edit',
        hoveredElement: null,
        selectedElement: null,
        highlightOverlay: null,
        selectOverlay: null,

        /**
         * Initialize the helper.
         * Detects `goal_select` mode when `opti_ab_goal_selector=1` is in the URL.
         */
        init: function () {
            // Detect goal-selector mode: opened from the click-goal "Select Element" button.
            if (window.location.search.indexOf('opti_ab_goal_selector=1') !== -1) {
                this.mode = 'goal_select';
            }

            this.createOverlays();
            this.bindEvents();
            this.hideNonContentElements();
            this.startMutationObserver();

            // Show the instruction bar in goal_select mode.
            if (this.mode === 'goal_select') {
                this.createGoalSelectorBar();
            }

            this.notifyParent({ type: 'iframe_ready' });
        },

        /**
         * Create a fixed instruction bar at the top of the page in goal_select mode.
         * Pointer-events are disabled so it doesn't interfere with element selection.
         */
        createGoalSelectorBar: function () {
            var bar = document.createElement('div');
            bar.id = 'opti-ab-goal-selector-bar';
            bar.style.cssText = [
                'position:fixed',
                'top:0',
                'left:0',
                'right:0',
                'z-index:9999999',
                'background:#1d4ed8',
                'color:#fff',
                'text-align:center',
                'padding:10px 16px',
                'font:600 13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
                'letter-spacing:0.01em',
                'box-shadow:0 2px 8px rgba(0,0,0,0.25)',
                'pointer-events:none'
            ].join(';');
            bar.textContent = VEHS.goal_selector_instruction || 'Click any element to use it as the goal target \u2014 hovering highlights elements';
            document.body.appendChild(bar);

            // Push page content below the banner so it is fully visible and clickable.
            // hideNonContentElements() already ran and set padding-top:0 !important,
            // so we must use !important here too to override it.
            var barHeight = bar.offsetHeight || 44;
            document.body.style.setProperty( 'padding-top', barHeight + 'px', 'important' );
        },

        /**
         * Selectors for elements that should be hidden in the visual editor iframe.
         */
        hideSelectors: [
            '#wpadminbar',
            '#optibehavior-bar',
            '#opti-behavior-bar',
            '#opti-stats-bar',
            '#opti-ab-tracker-config',
            '.optibehavior-analytics-bar',
            '.opti-behavior-floating',
            '.opti-stats-bar',
            '[id*="optibehavior"][id*="bar"]'
        ],

        /**
         * Hide non-content elements that shouldn't be editable.
         */
        hideNonContentElements: function () {
            var self = this;

            for (var i = 0; i < this.hideSelectors.length; i++) {
                try {
                    var els = document.querySelectorAll(this.hideSelectors[i]);
                    for (var j = 0; j < els.length; j++) {
                        els[j].style.setProperty('display', 'none', 'important');
                    }
                } catch (e) {
                    // Invalid selector, skip.
                }
            }

            document.documentElement.style.setProperty('margin-top', '0', 'important');
            document.body.style.setProperty('margin-top', '0', 'important');
            document.body.style.setProperty('padding-top', '0', 'important');

            var allDivs = document.querySelectorAll('div');
            for (var k = 0; k < allDivs.length; k++) {
                var div = allDivs[k];
                var cls = self.getClassName(div);
                var id = div.id || '';
                if ((cls && (cls.indexOf('optibehavior') !== -1 || cls.indexOf('opti-behavior') !== -1 || cls.indexOf('opti-stats') !== -1)) ||
                    (id && (id.indexOf('optibehavior') !== -1 || id.indexOf('opti-behavior') !== -1 || id.indexOf('opti-stats') !== -1))) {
                    if (cls.indexOf('opti-ab-ve') === -1 && id.indexOf('opti-ab-ve') === -1) {
                        div.style.setProperty('display', 'none', 'important');
                    }
                }
            }
        },

        /**
         * Watch for dynamically added elements and hide them if they match.
         */
        startMutationObserver: function () {
            var self = this;

            if (typeof MutationObserver === 'undefined') return;

            var observer = new MutationObserver(function (mutations) {
                var needsHide = false;
                for (var m = 0; m < mutations.length; m++) {
                    var added = mutations[m].addedNodes;
                    for (var n = 0; n < added.length; n++) {
                        var node = added[n];
                        if (node.nodeType !== 1) continue;

                        var id = node.id || '';
                        var cls = self.getClassName(node);

                        if (id === 'wpadminbar' || id === 'opti-stats-bar' || id === 'optibehavior-bar' ||
                            id === 'opti-behavior-bar' || id === 'opti-ab-tracker-config' ||
                            id.indexOf('optibehavior') !== -1 ||
                            (cls && (cls.indexOf('opti-stats-bar') !== -1 ||
                                cls.indexOf('optibehavior') !== -1 ||
                                cls.indexOf('opti-behavior-floating') !== -1))) {
                            if (id.indexOf('opti-ab-ve') === -1 && cls.indexOf('opti-ab-ve') === -1) {
                                node.style.setProperty('display', 'none', 'important');
                                needsHide = true;
                            }
                        }
                    }
                }
                if (needsHide) {
                    document.documentElement.style.setProperty('margin-top', '0', 'important');
                    document.body.style.setProperty('margin-top', '0', 'important');
                }
            });

            observer.observe(document.body, { childList: true, subtree: true });
        },

        /**
         * Create highlight and selection overlay elements.
         */
        createOverlays: function () {
            this.highlightOverlay = document.createElement('div');
            this.highlightOverlay.id = 'opti-ab-ve-highlight';
            this.highlightOverlay.style.cssText = 'position:absolute;pointer-events:none;border:2px dashed #6366f1;background:rgba(99,102,241,0.08);z-index:999998;display:none;transition:all 0.1s ease;border-radius:3px;';
            document.body.appendChild(this.highlightOverlay);

            this.selectOverlay = document.createElement('div');
            this.selectOverlay.id = 'opti-ab-ve-select';
            this.selectOverlay.style.cssText = 'position:absolute;pointer-events:none;border:2px solid #6366f1;background:rgba(99,102,241,0.15);z-index:999999;display:none;border-radius:3px;';
            document.body.appendChild(this.selectOverlay);
        },

        /**
         * Bind DOM and message events.
         * Both `edit` and `goal_select` modes use hover highlighting.
         * Click behaviour differs: `edit` opens the full editor panel;
         * `goal_select` sends the selector to the parent and closes.
         */
        bindEvents: function () {
            var self = this;

            // Hover highlighting works in both edit and goal_select modes.
            document.addEventListener('mousemove', function (e) {
                if (self.mode !== 'edit' && self.mode !== 'goal_select') return;
                self.onMouseMove(e);
            }, true);

            // Click handler — dispatches to the correct mode handler.
            document.addEventListener('click', function (e) {
                if (self.mode === 'goal_select') {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    self.onGoalSelectorClick(e);
                    return false;
                }
                if (self.mode !== 'edit') return;
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                self.onElementClick(e);
                return false;
            }, true);

            // Prevent all navigation (links) in both modes.
            document.addEventListener('click', function (e) {
                if (self.mode !== 'edit' && self.mode !== 'goal_select') return;
                var target = e.target;
                while (target && target !== document.body) {
                    if (target.tagName && target.tagName.toLowerCase() === 'a') {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                    target = target.parentElement;
                }
            }, true);

            document.addEventListener('submit', function (e) {
                if (self.mode === 'edit' || self.mode === 'goal_select') {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }, true);

            document.addEventListener('focus', function (e) {
                if (self.mode !== 'edit' && self.mode !== 'goal_select') return;
                var tag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'button') {
                    e.target.blur();
                }
            }, true);

            document.addEventListener('mousedown', function (e) {
                if (self.mode !== 'edit' && self.mode !== 'goal_select') return;
                var tag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
                if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                    e.preventDefault();
                }
            }, true);

            document.addEventListener('keydown', function (e) {
                if (self.mode !== 'edit' && self.mode !== 'goal_select') return;
                var tag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
                if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                    e.preventDefault();
                }
            }, true);

            window.addEventListener('message', function (e) {
                self.handleParentMessage(e);
            });

            window.addEventListener('scroll', function () {
                if (self.selectedElement && self.selectOverlay.style.display !== 'none') {
                    self.positionOverlay(self.selectOverlay, self.selectedElement);
                }
                if (self.hoveredElement && self.highlightOverlay.style.display !== 'none') {
                    self.positionOverlay(self.highlightOverlay, self.hoveredElement);
                }
            }, { passive: true });

            window.addEventListener('resize', function () {
                if (self.selectedElement && self.selectOverlay.style.display !== 'none') {
                    self.positionOverlay(self.selectOverlay, self.selectedElement);
                }
            });
        },

        /**
         * Resolve an element to its best selectable ancestor.
         */
        resolveElement: function (el) {
            if (!el) return null;

            var current = el;
            var maxWalk = 20;

            while (current && current !== document.body && current !== document.documentElement && maxWalk-- > 0) {
                if (!this.isIgnored(current)) {
                    return current;
                }

                var tag = current.tagName ? current.tagName.toLowerCase() : '';
                if (tag === 'svg') {
                    return current;
                }

                current = current.parentElement;
            }

            return null;
        },

        /**
         * Handle mouse movement — show highlight overlay.
         */
        onMouseMove: function (e) {
            var el = this.resolveElement(e.target);

            if (!el) {
                this.highlightOverlay.style.display = 'none';
                this.hoveredElement = null;
                return;
            }

            this.hoveredElement = el;
            this.positionOverlay(this.highlightOverlay, el);
            this.highlightOverlay.style.display = 'block';
        },

        /**
         * Handle element click — select element.
         */
        onElementClick: function (e) {
            var el = this.resolveElement(e.target);
            if (!el) return;

            this.selectElement(el);
        },

        /**
         * Select an element programmatically and notify the parent editor.
         * Shared between click handling and parent-driven navigation
         * (Parent / Child / breadcrumb hops).
         */
        selectElement: function (el) {
            if (!el) return;

            this.selectedElement = el;

            // Position selection overlay.
            this.positionOverlay(this.selectOverlay, el);
            this.selectOverlay.style.display = 'block';

            // Generate unique CSS selector.
            var selector = this.getUniqueSelector(el);
            var tagName = el.tagName.toLowerCase();

            // Get text content safely.
            var text = '';
            var html = '';
            try {
                text = el.textContent ? el.textContent.substring(0, 500) : '';
                html = el.innerHTML ? el.innerHTML.substring(0, 2000) : '';
            } catch (ex) {
                // Some elements may throw on innerHTML access.
            }

            // Get image src if it's an <img>.
            var imageSrc = '';
            if (tagName === 'img') {
                imageSrc = el.src || el.getAttribute('src') || '';
            }

            // Collect all own attributes for the Attributes panel.
            var attributes = {};
            if (el.attributes) {
                for (var a = 0; a < el.attributes.length; a++) {
                    var attr = el.attributes[a];
                    if (attr && attr.name && attr.name.indexOf('data-opti-ab') !== 0) {
                        attributes[attr.name] = attr.value;
                    }
                }
            }

            // Classes list (filter out internal editor classes).
            var classesStr = this.getClassName(el) || '';
            var classes = classesStr.trim().split(/\s+/).filter(function (c) {
                return c && c.indexOf('opti-ab-ve') === -1;
            });

            // Ancestry breadcrumb for the inspector (body → … → el).
            var ancestry = this.buildAncestry(el);

            // Does the element have element children we can dive into?
            var hasChildren = !!(el.firstElementChild);

            // Detect origin (theme/plugin).
            var origin = this.detectOrigin(el);

            // Inline-style snapshot so the CSS tab can show current overrides.
            var inlineStyle = el.style && el.style.cssText ? el.style.cssText : '';

            // Computed-style snapshot for the Design tab so the visual
            // controls reflect what the page actually renders today.
            var computed = this.captureComputedStyle(el);

            // Send to parent.
            this.notifyParent({
                type: 'element_selected',
                selector: selector,
                tagName: tagName,
                text: text,
                html: html,
                imageSrc: imageSrc,
                attributes: attributes,
                classes: classes,
                ancestry: ancestry,
                hasChildren: hasChildren,
                inlineStyle: inlineStyle,
                computed: computed,
                origin: origin
            });
        },

        /**
         * Capture the subset of computed styles the Design tab needs.
         * Values are returned as raw CSS strings (e.g. "rgb(..)", "16px").
         */
        captureComputedStyle: function (el) {
            var out = {};
            if (!el || !window.getComputedStyle) return out;
            var cs = window.getComputedStyle(el);
            var props = [
                'color', 'background-color', 'border-color',
                'font-size', 'font-weight', 'line-height', 'letter-spacing',
                'text-align', 'text-transform', 'font-style', 'text-decoration-line',
                'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
                'border-width', 'border-style', 'border-radius',
                'opacity', 'box-shadow', 'cursor'
            ];
            for (var i = 0; i < props.length; i++) {
                try { out[props[i]] = cs.getPropertyValue(props[i]); } catch (e) { /* noop */ }
            }
            return out;
        },

        /**
         * Build an array of ancestry entries (body → … → element) for the
         * parent inspector's breadcrumb. Each entry exposes a short label
         * and the unique selector needed for remote selection.
         */
        buildAncestry: function (el) {
            var chain = [];
            var current = el;
            var max = 10;

            while (current && current !== document.body && current !== document.documentElement && max-- > 0) {
                chain.unshift({
                    tag: current.tagName ? current.tagName.toLowerCase() : 'node',
                    id: current.id || '',
                    classes: (this.getClassName(current) || '')
                        .trim().split(/\s+/)
                        .filter(function (c) { return c && c.indexOf('opti-ab-ve') === -1; })
                        .slice(0, 2),
                    selector: this.getUniqueSelector(current)
                });
                current = current.parentElement;
            }
            return chain;
        },

        /**
         * Handle a click in goal_select mode.
         *
         * Generates a unique CSS selector for the clicked element, sends it to the
         * parent admin page via postMessage, shows brief "selected" feedback, and
         * switches to 'done' mode to prevent further clicks.
         *
         * @param {MouseEvent} e
         */
        onGoalSelectorClick: function (e) {
            var el = this.resolveElement(e.target);
            if (!el) return;

            var selector = this.getUniqueSelector(el);

            // Show the selection overlay on the clicked element.
            this.positionOverlay(this.selectOverlay, el);
            this.selectOverlay.style.display = 'block';
            this.highlightOverlay.style.display = 'none';

            // Lock further interaction.
            this.mode = 'done';

            // Update the instruction bar to give feedback.
            var bar = document.getElementById('opti-ab-goal-selector-bar');
            if (bar) {
                bar.style.background = '#16a34a'; // green
                bar.textContent = VEHS.goal_selector_selected || 'Element selected! The selector has been copied \u2014 closing\u2026';
            }

            // Send the selector to the parent admin window.
            this.notifyParent({
                type: 'goal_selector_selected',
                selector: selector
            });
        },

        // =================================================================
        //  ORIGIN DETECTION — Theme / Plugin / Builder identification
        // =================================================================

        /**
         * Detect which theme, plugin, or page builder created an element.
         * Walks up the DOM tree checking CSS classes, data attributes,
         * and structural patterns.
         *
         * @param {Element} el  The selected DOM element.
         * @return {Object}     { source: string, blockType: string }
         */
        detectOrigin: function (el) {
            var result = { source: 'Unknown', blockType: '' };
            var current = el;
            var maxWalk = 30;

            while (current && current !== document.body && current !== document.documentElement && maxWalk-- > 0) {
                var cls = this.getClassName(current);
                var id = current.id || '';
                var dataset = current.dataset || {};

                // ----- Elementor -----
                if (cls.indexOf('elementor-') !== -1 || dataset.elementType || dataset.element_type) {
                    result.source = 'Elementor';
                    result.blockType = this.detectElementorBlockType(current, cls, dataset);
                    return result;
                }

                // ----- WordPress Gutenberg Blocks -----
                if (cls.indexOf('wp-block-') !== -1) {
                    result.source = 'WordPress (Gutenberg)';
                    var blockMatch = cls.match(/wp-block-([\w-]+)/);
                    result.blockType = blockMatch ? blockMatch[1].replace(/-/g, ' ') : 'block';
                    return result;
                }
                if (cls.indexOf('has-text-align') !== -1 || cls.indexOf('is-style-') !== -1 ||
                    cls.indexOf('wp-block') !== -1 || cls.indexOf('alignwide') !== -1 ||
                    cls.indexOf('alignfull') !== -1) {
                    // Generic Gutenberg wrapper
                    result.source = 'WordPress (Gutenberg)';
                    result.blockType = current.tagName.toLowerCase();
                    return result;
                }

                // ----- WooCommerce -----
                if (cls.indexOf('woocommerce') !== -1 || cls.indexOf('wc-block') !== -1 ||
                    cls.indexOf('product') !== -1 && cls.indexOf('type-product') !== -1) {
                    result.source = 'WooCommerce';
                    result.blockType = this.detectWooBlockType(cls);
                    return result;
                }

                // ----- Kadence Blocks -----
                if (cls.indexOf('kadence') !== -1 || cls.indexOf('kb-') !== -1 ||
                    cls.indexOf('kt-') !== -1) {
                    result.source = 'Kadence';
                    result.blockType = this.detectKadenceBlockType(cls);
                    return result;
                }

                // ----- Spectra (UAG Blocks) -----
                if (cls.indexOf('uagb-') !== -1 || cls.indexOf('spectra-') !== -1) {
                    result.source = 'Spectra (UAG)';
                    var uagMatch = cls.match(/uagb-([\w-]+)/);
                    result.blockType = uagMatch ? uagMatch[1] : 'block';
                    return result;
                }

                // ----- GenerateBlocks -----
                if (cls.indexOf('gb-container') !== -1 || cls.indexOf('gb-grid') !== -1 ||
                    cls.indexOf('gb-headline') !== -1 || cls.indexOf('gb-button') !== -1 ||
                    cls.indexOf('generateblocks') !== -1) {
                    result.source = 'GenerateBlocks';
                    result.blockType = cls.indexOf('gb-container') !== -1 ? 'container' :
                                       cls.indexOf('gb-grid') !== -1 ? 'grid' :
                                       cls.indexOf('gb-headline') !== -1 ? 'headline' :
                                       cls.indexOf('gb-button') !== -1 ? 'button' : 'block';
                    return result;
                }

                // ----- Astra Theme -----
                if (cls.indexOf('ast-') !== -1 || cls.indexOf('astra-') !== -1 ||
                    id.indexOf('ast-') !== -1) {
                    result.source = 'Astra Theme';
                    result.blockType = this.detectAstraBlockType(cls, id);
                    return result;
                }

                // ----- Beaver Builder -----
                if (cls.indexOf('fl-row') !== -1 || cls.indexOf('fl-col') !== -1 ||
                    cls.indexOf('fl-module') !== -1 || cls.indexOf('fl-builder') !== -1) {
                    result.source = 'Beaver Builder';
                    result.blockType = cls.indexOf('fl-module') !== -1 ? 'module' :
                                       cls.indexOf('fl-col') !== -1 ? 'column' :
                                       cls.indexOf('fl-row') !== -1 ? 'row' : 'section';
                    return result;
                }

                // ----- Divi -----
                if (cls.indexOf('et_pb_') !== -1 || cls.indexOf('et_section') !== -1) {
                    result.source = 'Divi';
                    var diviMatch = cls.match(/et_pb_([\w]+)/);
                    result.blockType = diviMatch ? diviMatch[1].replace(/_/g, ' ') : 'module';
                    return result;
                }

                // ----- SeedProd -----
                if (cls.indexOf('seedprod') !== -1 || cls.indexOf('sp-') !== -1 && dataset.spBlock) {
                    result.source = 'SeedProd';
                    result.blockType = dataset.spBlock || 'block';
                    return result;
                }

                // ----- SureForms -----
                if (cls.indexOf('sureforms') !== -1 || cls.indexOf('srfm-') !== -1) {
                    result.source = 'SureForms';
                    result.blockType = 'form';
                    return result;
                }

                // ----- Contact Form 7 -----
                if (cls.indexOf('wpcf7') !== -1) {
                    result.source = 'Contact Form 7';
                    result.blockType = 'form';
                    return result;
                }

                // ----- WPForms -----
                if (cls.indexOf('wpforms') !== -1) {
                    result.source = 'WPForms';
                    result.blockType = 'form';
                    return result;
                }

                // ----- Gravity Forms -----
                if (cls.indexOf('gform_') !== -1 || cls.indexOf('gfield') !== -1) {
                    result.source = 'Gravity Forms';
                    result.blockType = 'form';
                    return result;
                }

                // ----- Starter Templates -----
                if (cls.indexOf('starter-template') !== -1 || cls.indexOf('st-') !== -1 && cls.indexOf('st-block') !== -1) {
                    result.source = 'Starter Templates';
                    return result;
                }

                // ----- Yoast SEO -----
                if (cls.indexOf('yoast') !== -1 || cls.indexOf('schema-faq') !== -1) {
                    result.source = 'Yoast SEO';
                    result.blockType = cls.indexOf('schema-faq') !== -1 ? 'FAQ' : 'SEO block';
                    return result;
                }

                // ----- RankMath -----
                if (cls.indexOf('rank-math') !== -1) {
                    result.source = 'RankMath';
                    return result;
                }

                // ----- Generic theme detection -----
                if (cls.indexOf('site-header') !== -1 || cls.indexOf('site-footer') !== -1 ||
                    cls.indexOf('site-content') !== -1 || cls.indexOf('site-main') !== -1 ||
                    id === 'site-header' || id === 'site-footer' || id === 'masthead' ||
                    id === 'colophon' || cls.indexOf('entry-content') !== -1) {
                    result.source = 'Theme';
                    result.blockType = cls.indexOf('site-header') !== -1 || id === 'masthead' ? 'header' :
                                       cls.indexOf('site-footer') !== -1 || id === 'colophon' ? 'footer' :
                                       cls.indexOf('entry-content') !== -1 ? 'content' :
                                       cls.indexOf('site-main') !== -1 ? 'main' : 'section';
                    return result;
                }

                // ----- Navigation -----
                if (current.tagName.toLowerCase() === 'nav' || cls.indexOf('navigation') !== -1 ||
                    cls.indexOf('nav-menu') !== -1 || cls.indexOf('menu-item') !== -1) {
                    result.source = 'Theme';
                    result.blockType = 'navigation';
                    return result;
                }

                current = current.parentElement;
            }

            // Fallback: identify by tag
            var tag = el.tagName.toLowerCase();
            if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].indexOf(tag) !== -1) {
                result.blockType = 'heading (' + tag + ')';
            } else if (tag === 'p') {
                result.blockType = 'paragraph';
            } else if (tag === 'img') {
                result.blockType = 'image';
            } else if (tag === 'a') {
                result.blockType = 'link';
            } else if (tag === 'button') {
                result.blockType = 'button';
            } else if (tag === 'form') {
                result.blockType = 'form';
            } else if (tag === 'ul' || tag === 'ol') {
                result.blockType = 'list';
            } else if (tag === 'table') {
                result.blockType = 'table';
            } else if (tag === 'video') {
                result.blockType = 'video';
            } else if (tag === 'svg') {
                result.blockType = 'SVG';
            } else if (tag === 'section') {
                result.blockType = 'section';
            } else if (tag === 'div') {
                result.blockType = 'container';
            } else {
                result.blockType = tag;
            }

            return result;
        },

        /**
         * Detect Elementor block type from classes/data attributes.
         */
        detectElementorBlockType: function (el, cls, dataset) {
            if (dataset.elementType) return dataset.elementType;
            if (cls.indexOf('elementor-widget-') !== -1) {
                var m = cls.match(/elementor-widget-([\w-]+)/);
                return m ? m[1] : 'widget';
            }
            if (cls.indexOf('elementor-section') !== -1) return 'section';
            if (cls.indexOf('elementor-column') !== -1) return 'column';
            if (cls.indexOf('elementor-container') !== -1) return 'container';
            if (cls.indexOf('elementor-heading') !== -1) return 'heading';
            if (cls.indexOf('elementor-button') !== -1) return 'button';
            if (cls.indexOf('elementor-image') !== -1) return 'image';
            return 'element';
        },

        /**
         * Detect WooCommerce block type.
         */
        detectWooBlockType: function (cls) {
            if (cls.indexOf('product-title') !== -1 || cls.indexOf('woocommerce-loop-product__title') !== -1) return 'product title';
            if (cls.indexOf('product-price') !== -1 || cls.indexOf('woocommerce-Price') !== -1) return 'price';
            if (cls.indexOf('add_to_cart') !== -1 || cls.indexOf('add-to-cart') !== -1) return 'add to cart';
            if (cls.indexOf('cart') !== -1) return 'cart';
            if (cls.indexOf('product') !== -1) return 'product';
            if (cls.indexOf('shop') !== -1) return 'shop';
            return 'block';
        },

        /**
         * Detect Kadence block type.
         */
        detectKadenceBlockType: function (cls) {
            if (cls.indexOf('kb-row') !== -1) return 'row layout';
            if (cls.indexOf('kb-icon') !== -1) return 'icon';
            if (cls.indexOf('kb-btn') !== -1 || cls.indexOf('kt-btn') !== -1) return 'button';
            if (cls.indexOf('kb-adv-heading') !== -1 || cls.indexOf('kt-adv-heading') !== -1) return 'heading';
            if (cls.indexOf('kb-tabs') !== -1) return 'tabs';
            if (cls.indexOf('kb-accordion') !== -1) return 'accordion';
            if (cls.indexOf('kb-info-box') !== -1) return 'info box';
            if (cls.indexOf('kb-testimonial') !== -1) return 'testimonial';
            return 'block';
        },

        /**
         * Detect Astra theme block type.
         */
        detectAstraBlockType: function (cls, id) {
            if (cls.indexOf('ast-header') !== -1 || id.indexOf('ast-header') !== -1) return 'header';
            if (cls.indexOf('ast-footer') !== -1 || id.indexOf('ast-footer') !== -1) return 'footer';
            if (cls.indexOf('ast-nav') !== -1) return 'navigation';
            if (cls.indexOf('ast-hero') !== -1) return 'hero';
            if (cls.indexOf('ast-sidebar') !== -1) return 'sidebar';
            if (cls.indexOf('ast-container') !== -1) return 'container';
            return 'element';
        },

        // =================================================================
        //  ACTION HANDLERS — duplicate, move, paste, insert
        // =================================================================

        /**
         * Duplicate an element (clone it and insert after).
         *
         * @param {string} selector  CSS selector of the element to duplicate.
         */
        duplicateElement: function (selector) {
            try {
                var el = document.querySelector(selector);
                if (!el) return;

                var clone = el.cloneNode(true);
                // Remove any data attributes we added
                delete clone.dataset.optiAbOriginal;
                delete clone.dataset.optiAbChanged;

                el.parentNode.insertBefore(clone, el.nextSibling);

                this.notifyParent({ type: 'action_done', action: 'duplicate' });
            } catch (e) {
                // Invalid selector or DOM operation
            }
        },

        /**
         * Move an element up or down among its siblings.
         *
         * @param {string} selector  CSS selector of the element.
         * @param {number} delta     -1 = up, 1 = down
         */
        moveElement: function (selector, delta) {
            try {
                var el = document.querySelector(selector);
                if (!el || !el.parentNode) return;

                if (delta < 0) {
                    // Move up: insert before previous sibling
                    var prev = el.previousElementSibling;
                    if (prev) {
                        el.parentNode.insertBefore(el, prev);
                    }
                } else {
                    // Move down: insert after next sibling
                    var next = el.nextElementSibling;
                    if (next) {
                        el.parentNode.insertBefore(el, next.nextSibling);
                    }
                }

                // Reposition overlay on moved element
                if (this.selectedElement === el) {
                    this.positionOverlay(this.selectOverlay, el);
                }

                this.notifyParent({ type: 'action_done', action: 'move' });
            } catch (e) {
                // Invalid selector or DOM operation
            }
        },

        /**
         * Insert HTML after a target element (for paste / insert block).
         *
         * @param {string} selector  CSS selector (empty = append to main content).
         * @param {string} html      HTML string to insert.
         * @param {string} actionName  'paste' or 'insert_block'
         */
        insertHTML: function (selector, html, actionName) {
            try {
                // Treat empty OR the literal 'body' / 'html' selector as
                // "no specific target → append to main content". Without
                // this, saved changes with selector='body' would try
                // insertBefore(child, body.nextSibling) which places
                // content AFTER </body> — the browser rescues it by
                // appending at document end, landing far below where the
                // user expected the block.
                var appendMode = !selector || selector === 'body' || selector === 'html';
                var target;
                if (!appendMode) {
                    try { target = document.querySelector(selector); } catch (e) { /* bad selector */ }
                }
                if (!target) {
                    appendMode = true;
                    target = document.querySelector('.entry-content') ||
                             document.querySelector('.site-content') ||
                             document.querySelector('#content') ||
                             document.querySelector('main') ||
                             document.body;
                }

                var wrapper = document.createElement('div');
                wrapper.innerHTML = html;

                // Insert each child node. Tag element roots with
                // data-opti-ab-inserted so revertChanges can clean them up
                // on the next apply pass (prevents cumulative duplication
                // when applyChanges re-runs after style edits).
                while (wrapper.firstChild) {
                    var node = wrapper.firstChild;
                    if (node.nodeType === 1 /* ELEMENT_NODE */) {
                        try { node.setAttribute('data-opti-ab-inserted', '1'); } catch (e) { /* noop */ }
                    }
                    if (!appendMode && target && target.parentNode) {
                        // Sibling-after insert: element found via selector.
                        target.parentNode.insertBefore(node, target.nextSibling);
                    } else {
                        // Append to main content area (or body fallback).
                        target.appendChild(node);
                    }
                }

                this.notifyParent({ type: 'action_done', action: actionName || 'paste' });
            } catch (e) {
                // Invalid selector or DOM operation
            }
        },

        // =================================================================
        //  MESSAGE HANDLING
        // =================================================================

        /**
         * Handle messages from the parent editor.
         */
        handleParentMessage: function (event) {
            var data;
            try {
                data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            } catch (e) {
                return;
            }

            if (!data || data.source !== 'opti-ab-ve-parent') return;

            switch (data.type) {
                case 'set_mode':
                    this.mode = data.mode || 'edit';
                    this.highlightOverlay.style.display = 'none';
                    this.selectOverlay.style.display = 'none';
                    // Reset the goal selector instruction bar when returning to
                    // goal_select mode (after the parent kept the modal open for
                    // multi-element selection in add-new mode).
                    if (this.mode === 'goal_select') {
                        var bar = document.getElementById('opti-ab-goal-selector-bar');
                        if (bar) {
                            bar.style.background = '#1d4ed8';
                            bar.textContent = VEHS.goal_selector_instruction || 'Click any element to use it as the goal target \u2014 hovering highlights elements';
                        }
                    }
                    break;

                case 'apply_changes':
                    this.applyChanges(data.changes || []);
                    break;

                case 'highlight':
                    this.highlightSelector(data.selector);
                    break;

                case 'deselect':
                    this.selectOverlay.style.display = 'none';
                    this.selectedElement = null;
                    break;

                case 'action':
                    this.handleAction(data);
                    break;

                // Parent/Child/breadcrumb remote selection.
                case 'select_by_selector':
                    try {
                        var tgt = document.querySelector(data.selector);
                        if (tgt) {
                            tgt.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            this.selectElement(tgt);
                        }
                    } catch (e) { /* invalid selector */ }
                    break;

                case 'select_parent_of':
                    try {
                        var cur = document.querySelector(data.selector);
                        if (cur && cur.parentElement && cur.parentElement !== document.body) {
                            this.selectElement(cur.parentElement);
                        }
                    } catch (e) { /* invalid selector */ }
                    break;

                case 'select_first_child_of':
                    try {
                        var cur2 = document.querySelector(data.selector);
                        if (cur2 && cur2.firstElementChild) {
                            this.selectElement(cur2.firstElementChild);
                        }
                    } catch (e) { /* invalid selector */ }
                    break;
            }
        },

        /**
         * Handle action messages from parent (duplicate, move, paste, insert_block).
         */
        handleAction: function (data) {
            switch (data.action) {
                case 'duplicate':
                    this.duplicateElement(data.selector);
                    break;
                case 'move':
                    this.moveElement(data.selector, data.delta || 0);
                    break;
                case 'paste':
                    this.insertHTML(data.selector, data.html, 'paste');
                    break;
                case 'insert_block':
                    this.insertHTML(data.selector, data.html, 'insert_block');
                    break;
            }
        },

        // =================================================================
        //  CHANGE APPLICATION
        // =================================================================

        /**
         * Properties whose effect *cascades* into descendants (CSS inheritance).
         * When the user styles a container with one of these, we also emit a
         * `.selector, .selector *` rule so child elements (which may carry
         * their own direct theme rules) still pick up the test variant.
         *
         * We deliberately exclude layout/box properties — width, padding,
         * margin, border, background, opacity, box-shadow, cursor — because
         * those should only affect the selected element itself.
         */
        INHERITABLE_CSS: {
            'color': 1, 'font-family': 1, 'font-weight': 1, 'font-style': 1,
            'font-size': 1, 'line-height': 1, 'letter-spacing': 1,
            'text-align': 1, 'text-transform': 1, 'text-decoration': 1,
            'text-decoration-line': 1
        },

        /**
         * Ensure a single master <style> tag exists for cascade overrides.
         * Returns the tag so callers can replace its textContent each apply.
         */
        getCascadeStyleTag: function () {
            var tag = document.getElementById('opti-ab-ve-cascade-style');
            if (!tag) {
                tag = document.createElement('style');
                tag.id = 'opti-ab-ve-cascade-style';
                tag.setAttribute('data-opti-ab-internal', '1');
                document.head.appendChild(tag);
            }
            return tag;
        },

        /**
         * Build the descendant-cascade rule for a change's inheritable CSS.
         * Format:  .selector, .selector * { color: red !important; font-size: 52px !important; }
         */
        buildCascadeRule: function (selector, cssText) {
            if (!cssText) return '';
            var parts = cssText.split(';');
            var inheritable = [];
            for (var i = 0; i < parts.length; i++) {
                var pair = parts[i].split(':');
                if (pair.length < 2) continue;
                var prop = pair.shift().trim().toLowerCase();
                var val = pair.join(':').replace(/!important$/i, '').trim();
                if (!prop || !val) continue;
                if (this.INHERITABLE_CSS[prop]) {
                    inheritable.push(prop + ': ' + val + ' !important');
                }
            }
            if (!inheritable.length) return '';
            // Escape any '<' in selector to be safe (rare).
            var safeSel = selector.replace(/</g, '');
            return safeSel + ', ' + safeSel + ' * { ' + inheritable.join('; ') + '; }';
        },

        /**
         * Apply an array of changes to the page.
         */
        applyChanges: function (changes) {
            // First, revert all previous changes.
            this.revertChanges();

            // Rebuild the cascade stylesheet from scratch each time so removed
            // changes don't leave orphan rules behind.
            var cascadeRules = [];

            for (var i = 0; i < changes.length; i++) {
                var change = changes[i];

                // Handle insert_after (paste / block insert) — these don't target an existing element the same way
                if (change.insert_after) {
                    this.insertHTML(change.selector, change.insert_after, 'apply');
                    continue;
                }

                // Handle duplicate
                if (change.duplicate) {
                    this.duplicateElement(change.selector);
                    continue;
                }

                // Handle move
                if (change.move_delta) {
                    this.moveElement(change.selector, change.move_delta);
                    continue;
                }

                var els;
                try {
                    els = document.querySelectorAll(change.selector);
                } catch (e) {
                    continue;
                }

                for (var j = 0; j < els.length; j++) {
                    var el = els[j];

                    // Store original state for reverting.
                    if (!el.dataset.optiAbOriginal) {
                        try {
                            // Snapshot own attributes so we can fully restore on revert.
                            var origAttrs = {};
                            if (el.attributes) {
                                for (var ai = 0; ai < el.attributes.length; ai++) {
                                    origAttrs[el.attributes[ai].name] = el.attributes[ai].value;
                                }
                            }
                            el.dataset.optiAbOriginal = JSON.stringify({
                                textContent: el.textContent || '',
                                innerHTML: el.innerHTML || '',
                                cssText: el.style ? el.style.cssText : '',
                                display: el.style ? el.style.display : '',
                                src: el.src || '',
                                srcset: el.srcset || '',
                                attributes: origAttrs,
                                className: this.getClassName(el)
                            });
                        } catch (ex) {
                            el.dataset.optiAbOriginal = '{}';
                        }
                        el.dataset.optiAbChanged = '1';
                    }

                    // Apply hide.
                    if (change.hide) {
                        el.style.display = 'none';
                        continue;
                    }

                    // Apply image source change.
                    if (change.image_src && el.tagName && el.tagName.toLowerCase() === 'img') {
                        el.src = change.image_src;
                        el.srcset = ''; // Clear srcset to prevent browser using wrong source
                        el.removeAttribute('srcset');
                    }

                    // Apply text change.
                    if (change.text && !change.html) {
                        el.textContent = change.text;
                    }

                    // Apply HTML change.
                    if (change.html) {
                        el.innerHTML = change.html;
                    }

                    // Apply CSS change — split into individual declarations so we
                    // can force `!important` on each and beat theme-level rules.
                    if (change.css) {
                        var decls = change.css.split(';');
                        for (var di = 0; di < decls.length; di++) {
                            var parts = decls[di].split(':');
                            if (parts.length < 2) continue;
                            var cssProp = parts.shift().trim();
                            var cssVal = parts.join(':').trim();
                            if (!cssProp || !cssVal) continue;
                            // If the author already wrote !important we let it pass through.
                            var hasImportant = /!important$/i.test(cssVal);
                            var cleanVal = cssVal.replace(/!important$/i, '').trim();
                            try {
                                el.style.setProperty(cssProp, cleanVal, 'important');
                            } catch (e) {
                                // Unknown property — swallow.
                            }
                        }

                        // Cascade inheritable props (color / font / text-*) to
                        // descendants so child elements with their own direct
                        // theme rules still pick up the variant. The inline
                        // style above keeps non-inheritable props (padding,
                        // background, border…) scoped to the element.
                        var cascadeRule = this.buildCascadeRule(change.selector, change.css);
                        if (cascadeRule) cascadeRules.push(cascadeRule);
                    }

                    // Apply attribute changes (object of name → value; empty/null removes).
                    if (change.attributes && typeof change.attributes === 'object') {
                        for (var attrName in change.attributes) {
                            if (!Object.prototype.hasOwnProperty.call(change.attributes, attrName)) continue;
                            var attrVal = change.attributes[attrName];
                            // Block risky inline event handlers — editor has a JS tab for that.
                            if (/^on[a-z]+$/i.test(attrName)) continue;
                            try {
                                if (attrVal === null || typeof attrVal === 'undefined' || attrVal === '') {
                                    el.removeAttribute(attrName);
                                } else {
                                    el.setAttribute(attrName, String(attrVal));
                                }
                            } catch (e) { /* invalid attribute name */ }
                        }
                    }

                    // Apply class additions.
                    if (change.classes_add && change.classes_add.length) {
                        for (var ca = 0; ca < change.classes_add.length; ca++) {
                            if (change.classes_add[ca]) {
                                try { el.classList.add(change.classes_add[ca]); } catch (e) { /* invalid class */ }
                            }
                        }
                    }

                    // Apply class removals.
                    if (change.classes_remove && change.classes_remove.length) {
                        for (var cr = 0; cr < change.classes_remove.length; cr++) {
                            if (change.classes_remove[cr]) {
                                try { el.classList.remove(change.classes_remove[cr]); } catch (e) { /* invalid class */ }
                            }
                        }
                    }

                    // Apply custom JS snippet — runs once with el in scope.
                    if (change.js && typeof change.js === 'string') {
                        this.runJsSnippet(change.js, el);
                    }
                }
            }

            // Commit the batch cascade stylesheet so descendants inherit
            // text-related overrides. Empty string when no inheritable rules
            // are present, which keeps the tag idle rather than orphaned.
            this.getCascadeStyleTag().textContent = cascadeRules.join('\n');
        },

        /**
         * Safely execute a user-provided JS snippet with the selected element
         * bound as `el`. Uses Function constructor (scoped) rather than eval.
         * Errors are swallowed so one bad snippet can't break the rest of the
         * variant preview.
         */
        runJsSnippet: function (code, el) {
            try {
                // eslint-disable-next-line no-new-func
                var fn = new Function('el', 'window', 'document', code);
                fn(el, window, document);
            } catch (e) {
                if (window.console && window.console.warn) {
                    window.console.warn('[opti-ab-ve] JS snippet error:', e);
                }
            }
        },

        /**
         * Revert all applied changes to original state.
         */
        revertChanges: function () {
            // Remove nodes inserted by previous `insertHTML` passes so that
            // re-applying the change list (e.g. after an unrelated style
            // edit) does not cumulatively duplicate inserted blocks.
            var inserted = document.querySelectorAll('[data-opti-ab-inserted]');
            for (var ri = 0; ri < inserted.length; ri++) {
                var insEl = inserted[ri];
                if (insEl.parentNode) {
                    try { insEl.parentNode.removeChild(insEl); } catch (e) { /* noop */ }
                }
            }

            var changed = document.querySelectorAll('[data-opti-ab-changed]');
            for (var i = 0; i < changed.length; i++) {
                var el = changed[i];
                if (el.dataset.optiAbOriginal) {
                    try {
                        var orig = JSON.parse(el.dataset.optiAbOriginal);
                        el.innerHTML = orig.innerHTML;
                        el.style.cssText = orig.cssText;
                        // Restore image src if needed
                        if (orig.src && el.tagName && el.tagName.toLowerCase() === 'img') {
                            el.src = orig.src;
                            if (orig.srcset) {
                                el.srcset = orig.srcset;
                            }
                        }
                        // Restore class list.
                        if (typeof orig.className === 'string') {
                            try {
                                if (el.className && typeof el.className === 'object' && 'baseVal' in el.className) {
                                    el.setAttribute('class', orig.className);
                                } else {
                                    el.className = orig.className;
                                }
                            } catch (ex) { /* noop */ }
                        }
                        // Restore attributes: delete any we added, reset originals.
                        if (orig.attributes && el.attributes) {
                            var currentNames = [];
                            for (var ai = 0; ai < el.attributes.length; ai++) {
                                currentNames.push(el.attributes[ai].name);
                            }
                            for (var ci = 0; ci < currentNames.length; ci++) {
                                var n = currentNames[ci];
                                if (!Object.prototype.hasOwnProperty.call(orig.attributes, n) &&
                                    n !== 'class' && n !== 'style' && n.indexOf('data-opti-ab') !== 0) {
                                    try { el.removeAttribute(n); } catch (e) { /* noop */ }
                                }
                            }
                            for (var oName in orig.attributes) {
                                if (!Object.prototype.hasOwnProperty.call(orig.attributes, oName)) continue;
                                if (oName === 'class' || oName === 'style') continue;
                                try { el.setAttribute(oName, orig.attributes[oName]); } catch (e) { /* noop */ }
                            }
                        }
                    } catch (e) {
                        // Ignore parse errors.
                    }
                }
                delete el.dataset.optiAbOriginal;
                delete el.dataset.optiAbChanged;
            }

            // Empty the cascade stylesheet — applyChanges will refill it.
            var tag = document.getElementById('opti-ab-ve-cascade-style');
            if (tag) tag.textContent = '';
        },

        /**
         * Highlight a specific selector.
         */
        highlightSelector: function (selector) {
            try {
                var el = document.querySelector(selector);
                if (el) {
                    this.positionOverlay(this.selectOverlay, el);
                    this.selectOverlay.style.display = 'block';
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } catch (e) {
                // Invalid selector.
            }
        },

        // =================================================================
        //  OVERLAY POSITIONING
        // =================================================================

        /**
         * Check if an element or any ancestor has fixed or sticky positioning.
         */
        isFixedOrSticky: function (el) {
            var current = el;
            while (current && current !== document.body && current !== document.documentElement) {
                var cs = window.getComputedStyle(current);
                if (cs.position === 'fixed' || cs.position === 'sticky') {
                    return true;
                }
                current = current.parentElement;
            }
            return false;
        },

        /**
         * Position an overlay div over an element.
         */
        positionOverlay: function (overlay, el) {
            var rect = el.getBoundingClientRect();

            if (this.isFixedOrSticky(el)) {
                overlay.style.position = 'fixed';
                overlay.style.left = (rect.left - 2) + 'px';
                overlay.style.top = (rect.top - 2) + 'px';
            } else {
                overlay.style.position = 'absolute';
                var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
                var scrollY = window.pageYOffset || document.documentElement.scrollTop;
                overlay.style.left = (rect.left + scrollX - 2) + 'px';
                overlay.style.top = (rect.top + scrollY - 2) + 'px';
            }

            overlay.style.width = (rect.width + 4) + 'px';
            overlay.style.height = (rect.height + 4) + 'px';
        },

        // =================================================================
        //  UTILITY METHODS
        // =================================================================

        /**
         * Safely get the className string from an element.
         * Handles SVG elements (SVGAnimatedString).
         */
        getClassName: function (el) {
            if (!el) return '';
            var cn = el.className;
            if (!cn) return '';
            if (typeof cn === 'object' && cn.baseVal !== undefined) {
                return cn.baseVal || '';
            }
            if (typeof cn === 'string') return cn;
            return el.getAttribute('class') || '';
        },

        /**
         * Check if an element should be ignored.
         */
        isIgnored: function (el) {
            if (!el || !el.tagName) return true;
            var tag = el.tagName.toLowerCase();

            if (['html', 'body', 'head', 'script', 'style', 'meta', 'link', 'noscript', 'br', 'wbr'].indexOf(tag) !== -1) return true;

            if (el.id && el.id.indexOf('opti-ab-ve') === 0) return true;

            var svgInternals = [
                'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
                'rect', 'g', 'defs', 'use', 'symbol', 'clippath', 'mask',
                'lineargradient', 'radialgradient', 'stop', 'text', 'tspan',
                'textpath', 'marker', 'pattern', 'filter', 'fegaussianblur',
                'feoffset', 'feblend', 'fecolormatrix', 'fecomposite',
                'feflood', 'femerge', 'femergenode', 'femorphology', 'feturbulence',
                'fedisplacementmap', 'feimage', 'fetile', 'animate', 'animatetransform',
                'set', 'desc', 'title', 'metadata', 'foreignobject'
            ];
            if (svgInternals.indexOf(tag) !== -1) return true;

            if (el.closest && el.closest('#wpadminbar')) return true;

            if (el.closest && (
                el.closest('#optibehavior-bar') ||
                el.closest('#opti-behavior-bar') ||
                el.closest('.optibehavior-analytics-bar') ||
                el.closest('#opti-ab-tracker-config')
            )) return true;

            return false;
        },

        /**
         * Generate a unique CSS selector for an element.
         */
        getUniqueSelector: function (el) {
            var self = this;

            var esc = (typeof CSS !== 'undefined' && CSS.escape)
                ? function (s) { return CSS.escape(s); }
                : function (s) {
                    return s.replace(/([^\w-])/g, '\\$1');
                };

            if (el.id) {
                try {
                    if (document.querySelectorAll('#' + esc(el.id)).length === 1) {
                        return '#' + esc(el.id);
                    }
                } catch (e) {
                    // ID might produce invalid selector, continue.
                }
            }

            var path = [];
            var current = el;

            while (current && current !== document.body && current !== document.documentElement) {
                var selector = current.tagName.toLowerCase();

                var clsStr = self.getClassName(current);
                if (clsStr) {
                    var classes = clsStr.trim().split(/\s+/).filter(function (c) {
                        return c &&
                            c.indexOf('opti-ab') === -1 &&
                            c.indexOf('hover') === -1 &&
                            c.indexOf('active') === -1 &&
                            c.indexOf('focus') === -1 &&
                            c.length < 50;
                    }).slice(0, 3);

                    if (classes.length) {
                        selector += '.' + classes.map(function (c) { return esc(c); }).join('.');
                    }
                }

                if (current.parentElement) {
                    try {
                        var siblings = current.parentElement.querySelectorAll(':scope > ' + selector);
                        if (siblings.length > 1) {
                            var index = Array.prototype.indexOf.call(current.parentElement.children, current) + 1;
                            selector += ':nth-child(' + index + ')';
                        }
                    } catch (e) {
                        var idx = Array.prototype.indexOf.call(current.parentElement.children, current) + 1;
                        selector += ':nth-child(' + idx + ')';
                    }
                }

                path.unshift(selector);

                var fullSelector = path.join(' > ');
                try {
                    if (document.querySelectorAll(fullSelector).length === 1) {
                        return fullSelector;
                    }
                } catch (e) {
                    // Invalid selector, continue building path.
                }

                current = current.parentElement;
            }

            return path.join(' > ');
        },

        /**
         * Send a message to the parent window.
         */
        notifyParent: function (data) {
            if (window.parent && window.parent !== window) {
                data.source = 'opti-ab-ve-helper';
                window.parent.postMessage(JSON.stringify(data), '*');
            }
        }
    };

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { Helper.init(); });
    } else {
        Helper.init();
    }

})();
