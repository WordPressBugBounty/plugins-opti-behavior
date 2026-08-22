/**
 * A/B Test Visual Editor (Pro)
 *
 * Manages the iframe-based visual editor for point-and-click element
 * selection, inline editing (text, HTML, CSS, image), element actions
 * (delete, duplicate, copy, paste, move), block insertion, and
 * theme/plugin origin detection.
 *
 * @package opti-behavior-pro
 * @since   1.3.0
 */

/* global jQuery, optiBehaviorVisualEditor, wp */
(function ($) {
    'use strict';

    var VE = {
        testId: 0,
        variantId: 0,
        targetUrl: '',
        changes: [],
        undoStack: [],   // snapshots of this.changes before each mutation
        redoStack: [],   // undone snapshots for redo
        selectedElement: null,
        selectedSelector: '',
        mode: 'edit', // 'edit' or 'preview'
        iframeReady: false,
        clipboard: null, // { html: '...', selector: '...' }
        mediaFrame: null, // wp.media frame

        /**
         * Predefined block templates for the Add Block feature.
         */
        blockTemplates: [
            {
                name: 'Heading',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 12h12"/><path d="M6 20V4"/><path d="M18 20V4"/></svg>',
                html: '<h2 style="margin:20px 0;font-size:28px;font-weight:700;">New Heading</h2>'
            },
            {
                name: 'Paragraph',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v16"/><path d="M18 3v16"/><path d="M14 3H8a4 4 0 0 0 0 8h6"/></svg>',
                html: '<p style="margin:16px 0;font-size:16px;line-height:1.6;">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>'
            },
            {
                name: 'Image',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
                html: '<div style="margin:20px 0;text-align:center;"><img src="https://placehold.co/600x300/e2e8f0/64748b?text=Placeholder+Image" alt="Placeholder" style="max-width:100%;height:auto;border-radius:8px;"></div>'
            },
            {
                name: 'Button',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="6"/><path d="M12 12h.01"/></svg>',
                html: '<div style="margin:20px 0;text-align:center;"><a href="#" style="display:inline-block;padding:14px 28px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:16px;">Click Here</a></div>'
            },
            {
                name: 'Divider',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/></svg>',
                html: '<hr style="margin:24px 0;border:none;border-top:2px solid #e5e7eb;">'
            },
            {
                name: 'Spacer',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="m8 7 4-4 4 4"/><path d="m8 17 4 4 4-4"/></svg>',
                html: '<div style="height:48px;" aria-hidden="true"></div>'
            },
            {
                name: 'Two Columns',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/></svg>',
                html: '<div style="display:flex;gap:20px;margin:20px 0;"><div style="flex:1;padding:20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;"><h4 style="margin:0 0 8px;">Column 1</h4><p style="margin:0;color:#6b7280;">Add your content here.</p></div><div style="flex:1;padding:20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;"><h4 style="margin:0 0 8px;">Column 2</h4><p style="margin:0;color:#6b7280;">Add your content here.</p></div></div>'
            },
            {
                name: 'CTA Box',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/><path d="m12 12 4 10 1.7-4.3L22 16Z"/></svg>',
                html: '<div style="margin:20px 0;padding:32px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;text-align:center;"><h3 style="color:#fff;margin:0 0 12px;font-size:24px;">Ready to get started?</h3><p style="color:rgba(255,255,255,0.9);margin:0 0 20px;font-size:16px;">Join thousands of satisfied customers today.</p><a href="#" style="display:inline-block;padding:14px 32px;background:#fff;color:#6366f1;text-decoration:none;border-radius:8px;font-weight:600;font-size:16px;">Get Started</a></div>'
            }
        ],

        /**
         * Initialize the visual editor.
         */
        init: function () {
            var $editor = $('#opti-ab-visual-editor');
            if (!$editor.length) return;

            this.testId = parseInt($editor.data('test-id'), 10) || 0;
            this.variantId = parseInt($editor.data('variant-id'), 10) || 0;
            this.targetUrl = $editor.data('target-url') || '';
            this.changes = $editor.data('changes') || [];

            if (!Array.isArray(this.changes)) {
                try {
                    this.changes = JSON.parse(this.changes);
                } catch (e) {
                    this.changes = [];
                }
            }

            this.bindEvents();
            this.renderChangesList();
            this.updateChangesCount();
            this.setupIframe();
            this.populateBlockGrid();
        },

        /**
         * Bind UI events.
         */
        bindEvents: function () {
            var self = this;

            // Mode toggle
            $(document).on('click', '.opti-ab-ve-mode-btn', function () {
                var mode = $(this).data('mode');
                self.setMode(mode);
            });

            // Save button
            $('#opti-ab-ve-save').on('click', function () {
                self.saveChanges();
            });

            // Undo / Redo buttons
            $('#opti-ab-ve-undo').on('click', function () {
                self.undo();
            });
            $('#opti-ab-ve-redo').on('click', function () {
                self.redo();
            });

            // Undo / Redo keyboard shortcuts (Ctrl+Z / Ctrl+Y)
            $(document).on('keydown', function (e) {
                // Only in edit mode, not when typing in an input/textarea.
                if (self.mode !== 'edit') return;
                var tag = (e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

                if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'z') {
                    e.preventDefault();
                    self.undo();
                } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'Z'))) {
                    e.preventDefault();
                    self.redo();
                }
            });

            // Edit panel tabs
            $(document).on('click', '.opti-ab-ve-tab', function () {
                var tab = $(this).data('tab');
                $('.opti-ab-ve-tab').removeClass('active');
                $(this).addClass('active');
                $('.opti-ab-ve-tab-content').removeClass('active');
                $('.opti-ab-ve-tab-content[data-tab="' + tab + '"]').addClass('active');
            });

            // Apply edit
            $('#opti-ab-ve-apply-edit').on('click', function () {
                self.applyEdit();
            });

            // Cancel edit
            $('#opti-ab-ve-cancel-edit').on('click', function () {
                self.cancelEdit();
            });

            // Remove change from list
            $(document).on('click', '.opti-ab-ve-change-remove', function () {
                var idx = parseInt($(this).closest('.opti-ab-ve-change-item').data('index'), 10);
                self.removeChange(idx);
            });

            // Click change to re-edit
            $(document).on('click', '.opti-ab-ve-change-item .opti-ab-ve-change-selector', function () {
                var idx = parseInt($(this).closest('.opti-ab-ve-change-item').data('index'), 10);
                self.editExistingChange(idx);
            });

            // Element action buttons (delete, duplicate, copy, paste, move)
            $(document).on('click', '.opti-ab-ve-action-btn', function () {
                var action = $(this).data('action');
                if (action) {
                    self.handleAction(action);
                }
            });

            // Image upload via WP Media Library
            $('#opti-ab-ve-image-upload').on('click', function () {
                self.openMediaLibrary();
            });

            // Image URL input — live preview
            $('#opti-ab-ve-image-url').on('input', function () {
                var url = $(this).val();
                if (url) {
                    $('#opti-ab-ve-image-preview-img').attr('src', url);
                }
            });

            // Add Block button
            $('#opti-ab-ve-add-block-btn').on('click', function () {
                self.toggleBlockPanel();
            });

            // Close Add Block panel
            $('#opti-ab-ve-block-panel-close').on('click', function () {
                self.hideBlockPanel();
            });

            // Block template click
            $(document).on('click', '.opti-ab-ve-block-item', function () {
                var idx = parseInt($(this).data('index'), 10);
                if (!isNaN(idx) && self.blockTemplates[idx]) {
                    self.insertBlock(self.blockTemplates[idx]);
                }
            });

            // Breadcrumb click — select ancestor by selector.
            $(document).on('click', '.opti-ab-ve-bc-item:not(.is-current)', function () {
                var sel = $(this).data('selector');
                if (sel) {
                    self.postToIframe({ type: 'select_by_selector', selector: sel });
                }
            });

            // Remove a class chip → add to classes_remove.
            $(document).on('click', '#opti-ab-ve-classes-current .opti-ab-ve-chip__x', function () {
                $(this).closest('.opti-ab-ve-chip').remove();
            });

            // Add a class chip.
            $('#opti-ab-ve-class-add-btn').on('click', function () {
                self.addClassFromInput();
            });
            $('#opti-ab-ve-class-add-input').on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.addClassFromInput();
                }
            });

            // Add a custom attribute row.
            $('#opti-ab-ve-attrs-custom-add').on('click', function () {
                self.addCustomAttribute();
            });

            // ---- Design tab wiring ----

            // Colour swatch (native picker) → mirror into the hex input.
            // Bind both `input` (fires while dragging in picker) and `change`
            // (fires when the picker closes) so no platform drops the update.
            $(document).on('input change', '.opti-ab-ve-color-swatch', function () {
                var $wrap = $(this).closest('.opti-ab-ve-color-field');
                $wrap.find('[data-role="hex"]').val($(this).val());
                // Mark the field as touched so apply knows the user picked.
                $wrap.attr('data-touched', '1');
            });

            // Hex input → push to the swatch if it's a valid hex.
            $(document).on('input change', '.opti-ab-ve-color-hex', function () {
                var v = ($(this).val() || '').trim();
                var $wrap = $(this).closest('.opti-ab-ve-color-field');
                var hex = self.cssColorToHex(v);
                if (hex) $wrap.find('[data-role="swatch"]').val(hex);
                $wrap.attr('data-touched', '1');
            });

            // × button clears a colour field.
            $(document).on('click', '.opti-ab-ve-color-clear', function () {
                var $wrap = $(this).closest('.opti-ab-ve-color-field');
                $wrap.find('[data-role="hex"]').val('');
                $wrap.find('[data-role="swatch"]').val('#000000');
            });

            // Segment buttons (align / transform) → toggle is-active state.
            $(document).on('click', '.opti-ab-ve-seg-btn', function () {
                var $btn = $(this);
                var $group = $btn.closest('.opti-ab-ve-btn-group');
                var alreadyActive = $btn.hasClass('is-active');
                $group.find('.opti-ab-ve-seg-btn').removeClass('is-active');
                if (!alreadyActive) $btn.addClass('is-active');
            });

            // Reset design — clear every control in the Design tab.
            $('#opti-ab-ve-design-reset').on('click', function () {
                self.resetDesignTab();
                self.schedulePreview();
            });

            // Collapse / expand the Changes list region.
            $('#opti-ab-ve-changes-toggle').on('click', function () {
                $('#opti-ab-ve-sidebar-top').toggleClass('is-collapsed');
            });

            // Reset all — open the confirm modal.
            $('#opti-ab-ve-reset').on('click', function () {
                self.openResetModal();
            });
            $('#opti-ab-ve-reset-cancel').on('click', function () {
                self.closeResetModal();
            });
            $('#opti-ab-ve-reset-confirm').on('click', function () {
                self.closeResetModal();
                self.performReset();
            });
            // Clicking the overlay (outside the dialog) cancels.
            $('#opti-ab-ve-reset-modal').on('click', function (e) {
                if (e.target === this) self.closeResetModal();
            });
            // Escape closes the modal.
            $(document).on('keydown.opti-ab-ve-reset', function (e) {
                if (e.key === 'Escape' && $('#opti-ab-ve-reset-modal').is(':visible')) {
                    self.closeResetModal();
                }
            });

            // ---- LIVE PREVIEW wiring ----
            // Any inspector input triggers a debounced preview so the iframe
            // reflects the in-flight edit in real time — no need to click
            // Apply to see colour/size/spacing changes.
            var previewSelectors = [
                '.opti-ab-ve-color-hex',
                '.opti-ab-ve-color-swatch',
                '.opti-ab-ve-design-control',
                '.opti-ab-ve-design-toggle',
                '#opti-ab-ve-text-editor',
                '#opti-ab-ve-html-editor',
                '#opti-ab-ve-css-editor',
                '#opti-ab-ve-js-editor',
                '#opti-ab-ve-url-href',
                '#opti-ab-ve-url-target',
                '#opti-ab-ve-url-rel',
                '#opti-ab-ve-image-url',
                '#opti-ab-ve-hide-element',
                '.opti-ab-ve-attr-field'
            ].join(', ');

            $(document).on('input change', previewSelectors, function () {
                self.schedulePreview();
            });

            // Segment buttons (align / transform) — click fires preview.
            $(document).on('click', '.opti-ab-ve-seg-btn', function () {
                // Preview after the click's is-active class is committed.
                setTimeout(function () { self.schedulePreview(); }, 30);
            });

            // Color × clear triggers preview.
            $(document).on('click', '.opti-ab-ve-color-clear', function () {
                setTimeout(function () { self.schedulePreview(); }, 30);
            });

            // Class chip add/remove triggers preview.
            $(document).on('click', '#opti-ab-ve-class-add-btn, #opti-ab-ve-classes-current .opti-ab-ve-chip__x', function () {
                setTimeout(function () { self.schedulePreview(); }, 50);
            });

            // Listen for messages from iframe
            window.addEventListener('message', function (e) {
                self.handleIframeMessage(e);
            });
        },

        /**
         * Debounced wrapper for previewCurrentEdit to avoid flooding the
         * iframe on every keystroke / slider tick.
         */
        _previewTimer: null,
        schedulePreview: function () {
            var self = this;
            if (this._previewTimer) clearTimeout(this._previewTimer);
            this._previewTimer = setTimeout(function () {
                self.previewCurrentEdit();
            }, 80);
        },

        /**
         * Show the Reset-all confirm modal. Kept separate from applyEdit so
         * accidental clicks do nothing until the user explicitly confirms.
         */
        openResetModal: function () {
            $('#opti-ab-ve-reset-modal').css('display', 'flex');
            // Focus the Cancel button by default so Enter ≠ Reset.
            setTimeout(function () {
                var btn = document.getElementById('opti-ab-ve-reset-cancel');
                if (btn) btn.focus();
            }, 50);
        },

        closeResetModal: function () {
            $('#opti-ab-ve-reset-modal').hide();
        },

        /**
         * Clear every change — locally AND on the server — and revert the
         * iframe preview. Only called after explicit user confirmation in
         * the modal.
         *
         * After 29+ DOM-structural changes (Add Block, Duplicate, Move)
         * the iframe's snapshot state can drift enough that the
         * in-place revertChanges() doesn't fully restore the original —
         * so we hard-reload the iframe for a guaranteed clean slate.
         */
        performReset: function () {
            var self = this;

            // Drop every pending edit + every committed change.
            this.selectedElement = null;
            this.selectedSelector = '';
            $('#opti-ab-ve-edit-panel').slideUp(150);
            this.hideElementInfo();
            this.changes = [];
            this.undoStack = [];
            this.redoStack = [];

            // Re-render the now-empty state.
            this.renderChangesList();
            this.updateChangesCount();
            this.updateUndoRedoButtons();

            // Persist the empty array to the server, then hard-reload the
            // iframe so it loads the target URL fresh — guaranteed clean
            // state regardless of how many structural changes preceded.
            $.ajax({
                url: optiBehaviorVisualEditor.ajax_url,
                type: 'POST',
                data: {
                    action: 'opti_behavior_ab_visual_save',
                    nonce: optiBehaviorVisualEditor.nonce,
                    test_id: self.testId,
                    variant_id: self.variantId,
                    changes: JSON.stringify([])
                }
            }).done(function () {
                self.reloadIframe();
                self.showToast(optiBehaviorVisualEditor.strings.reset_done || 'All changes reset.', 'success');
            }).fail(function () {
                self.showToast(optiBehaviorVisualEditor.strings.reset_failed || 'Reset failed \u2014 try again.', 'error');
            });
        },

        /**
         * Hard-reload the iframe to its target URL.
         *
         * Setting `iframe.src = same-url-with-diff-query` can be a no-op in
         * some browsers. We force a real navigation by:
         *   1. Parking the iframe on about:blank (guaranteed navigation).
         *   2. On the next tick, setting the original src with a cache-bust.
         *
         * The ab-test-ve-helper script reinitialises on the fresh load and
         * the parent re-applies the (now empty) changes array on
         * iframe_ready.
         */
        reloadIframe: function () {
            var self = this;
            var iframe = document.getElementById('opti-ab-ve-iframe');
            if (!iframe) return;

            this.iframeReady = false;
            $('#opti-ab-ve-iframe-loading').fadeIn(100);

            var originalSrc = (iframe.src || '').split('#')[0].replace(/[?&]opti_ab_cache=\d+/, '');
            var sep = originalSrc.indexOf('?') === -1 ? '?' : '&';
            var freshSrc = originalSrc + sep + 'opti_ab_cache=' + Date.now();

            // Park on about:blank first so any cache layer can't shortcut the
            // navigation — the browser must load `freshSrc` from scratch.
            try { iframe.src = 'about:blank'; } catch (e) { /* noop */ }
            setTimeout(function () {
                iframe.src = freshSrc;
            }, 30);
        },

        /**
         * Clear every Design-tab control back to empty / default.
         */
        resetDesignTab: function () {
            $('.opti-ab-ve-color-field [data-role="hex"]').val('');
            $('.opti-ab-ve-color-field [data-role="swatch"]').val('#000000');
            $('.opti-ab-ve-color-field').attr('data-touched', '0');
            $('.opti-ab-ve-design-control[data-unit="px"]').val('');
            $('#opti-ab-ve-ds-lineheight').val('');
            $('#opti-ab-ve-ds-opacity').val(1);
            $('#opti-ab-ve-ds-fontweight, #opti-ab-ve-ds-borderstyle, #opti-ab-ve-ds-cursor, #opti-ab-ve-ds-shadow').val('');
            $('.opti-ab-ve-seg-btn').removeClass('is-active');
            $('.opti-ab-ve-design-toggle').prop('checked', false);
        },

        /**
         * Append a class chip based on the input value.
         */
        addClassFromInput: function () {
            var $input = $('#opti-ab-ve-class-add-input');
            var val = ($input.val() || '').trim().replace(/^\./, '');
            if (!val) return;
            if ($('#opti-ab-ve-classes-current .opti-ab-ve-chip[data-class="' + this.escAttr(val) + '"]').length) {
                $input.val('');
                return;
            }
            // Remove the "(no classes)" placeholder if present.
            $('#opti-ab-ve-classes-current .opti-ab-ve-help').remove();
            var removeLabel = optiBehaviorVisualEditor.strings.remove || 'Remove';
            $('#opti-ab-ve-classes-current').append(
                '<span class="opti-ab-ve-chip" data-class="' + this.escAttr(val) + '">' +
                this.escHtml(val) +
                '<button type="button" class="opti-ab-ve-chip__x" title="' + removeLabel + '" aria-label="' + removeLabel + '">&times;</button>' +
                '</span>'
            );
            $input.val('');
        },

        /**
         * Add a custom attribute field to the Attributes form.
         */
        addCustomAttribute: function () {
            var name = ($('#opti-ab-ve-attrs-custom-name').val() || '').trim();
            var value = ($('#opti-ab-ve-attrs-custom-value').val() || '');
            if (!name || !/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/.test(name)) return;
            if (/^on[a-z]+$/i.test(name)) return; // block on* handlers
            if ($('.opti-ab-ve-attr-field[data-name="' + this.escAttr(name) + '"]').length) {
                $('.opti-ab-ve-attr-field[data-name="' + this.escAttr(name) + '"]').val(value);
            } else {
                var id = 'opti-ab-ve-attr-' + name;
                $('#opti-ab-ve-attrs-form').append(
                    '<div class="opti-ab-ve-attr-row">' +
                    '  <label for="' + id + '">' + this.escHtml(name) + ' <code>' + this.escHtml(name) + '</code></label>' +
                    '  <input type="text" class="opti-ab-ve-input opti-ab-ve-attr-field" id="' + id + '" data-name="' + this.escAttr(name) + '" value="' + this.escAttr(value) + '">' +
                    '</div>'
                );
            }
            $('#opti-ab-ve-attrs-custom-name').val('');
            $('#opti-ab-ve-attrs-custom-value').val('');
        },

        /**
         * Set up the iframe load handler.
         */
        setupIframe: function () {
            var self = this;
            var $iframe = $('#opti-ab-ve-iframe');

            $iframe.on('load', function () {
                self.iframeReady = true;
                $('#opti-ab-ve-iframe-loading').fadeOut(200);

                // Apply existing changes to iframe preview.
                self.applyChangesToIframe();
            });

            // Timeout for loading
            setTimeout(function () {
                if (!self.iframeReady) {
                    $('#opti-ab-ve-iframe-loading').html(
                        '<p style="color:#dc3545;">' + (optiBehaviorVisualEditor.strings.load_error || 'Could not load page.') + '</p>'
                    );
                }
            }, 15000);
        },

        /**
         * Populate the Add Block grid with templates.
         */
        populateBlockGrid: function () {
            var $grid = $('#opti-ab-ve-block-grid');
            if (!$grid.length) return;
            $grid.empty();

            var S = (typeof optiBehaviorVisualEditor !== 'undefined' && optiBehaviorVisualEditor.strings) ? optiBehaviorVisualEditor.strings : {};
            var blockKeys = ['block_heading','block_paragraph','block_image','block_button','block_divider','block_spacer','block_two_columns','block_cta_box'];

            for (var i = 0; i < this.blockTemplates.length; i++) {
                var t = this.blockTemplates[i];
                var blockName = (blockKeys[i] && S[blockKeys[i]]) || t.name;
                $grid.append(
                    '<div class="opti-ab-ve-block-item" data-index="' + i + '">' +
                    '  <div class="opti-ab-ve-block-item__icon">' + t.icon + '</div>' +
                    '  <div class="opti-ab-ve-block-item__name">' + this.escHtml(blockName) + '</div>' +
                    '</div>'
                );
            }
        },

        /**
         * Handle messages from the iframe (element selection, etc.).
         */
        handleIframeMessage: function (event) {
            var data;
            try {
                data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            } catch (e) {
                return;
            }

            if (!data || data.source !== 'opti-ab-ve-helper') return;

            switch (data.type) {
                case 'element_selected':
                    this.onElementSelected(data);
                    break;
                case 'iframe_ready':
                    this.iframeReady = true;
                    this.applyChangesToIframe();
                    break;
                case 'action_done':
                    // Confirmation from iframe that an action was performed.
                    if (data.action === 'duplicate') {
                        this.showToast(optiBehaviorVisualEditor.strings.duplicated || 'Element duplicated!', 'success');
                    } else if (data.action === 'move') {
                        this.showToast(optiBehaviorVisualEditor.strings.moved || 'Element moved!', 'success');
                    } else if (data.action === 'insert_block') {
                        this.showToast(optiBehaviorVisualEditor.strings.block_inserted || 'Block inserted!', 'success');
                        this.hideBlockPanel();
                    } else if (data.action === 'paste') {
                        this.showToast(optiBehaviorVisualEditor.strings.pasted || 'Element pasted!', 'success');
                    }
                    break;
            }
        },

        /**
         * Handle element selection from iframe.
         *
         * If there's an uncommitted edit on a different element, auto-commit
         * it first so the user doesn't silently lose their work when they
         * click around. This matches the "Save Changes" auto-commit so both
         * entry points are consistent.
         */
        onElementSelected: function (data) {
            if (this.mode !== 'edit') return;

            var incomingSelector = data.selector || '';
            if (this.selectedSelector && this.selectedSelector !== incomingSelector && this.hasPendingEdit()) {
                this.applyEdit();
            }

            this.selectedSelector = data.selector || '';
            this.selectedElement = {
                selector: data.selector,
                tagName: data.tagName,
                text: data.text || '',
                html: data.html || '',
                css: data.inlineStyle || '',
                imageSrc: data.imageSrc || '',
                attributes: data.attributes || {},
                classes: data.classes || [],
                ancestry: data.ancestry || [],
                hasChildren: !!data.hasChildren,
                computed: data.computed || {},
                origin: data.origin || null
            };

            // Show element info + breadcrumb + actions
            this.showElementInfo(data);
            this.renderBreadcrumb(data.ancestry || []);

            // Toggle parent/child buttons based on capabilities.
            this.updateNavButtons();

            // Show edit panel
            this.showEditPanel();
        },

        /**
         * Render the clickable ancestry breadcrumb.
         */
        renderBreadcrumb: function (ancestry) {
            var $bc = $('#opti-ab-ve-breadcrumb');
            if (!$bc.length) return;
            $bc.empty();

            if (!ancestry.length) {
                $bc.hide();
                return;
            }

            var html = '';
            for (var i = 0; i < ancestry.length; i++) {
                var node = ancestry[i];
                var label = node.tag || 'node';
                if (node.id) {
                    label += '#' + node.id;
                } else if (node.classes && node.classes.length) {
                    label += '.' + node.classes[0];
                }
                var isLast = i === ancestry.length - 1;
                html += '<span class="opti-ab-ve-bc-item' + (isLast ? ' is-current' : '') + '" data-selector="' + this.escAttr(node.selector) + '" title="' + this.escAttr(node.selector) + '">' + this.escHtml(label) + '</span>';
                if (!isLast) {
                    html += '<span class="opti-ab-ve-bc-sep">›</span>';
                }
            }
            $bc.html(html).show();
        },

        /**
         * Enable/disable Parent / Child navigation buttons.
         */
        updateNavButtons: function () {
            var el = this.selectedElement;
            // Parent button — always enabled if there's ancestry (breadcrumb has > 1 nodes).
            var hasParent = el && el.ancestry && el.ancestry.length > 1;
            $('.opti-ab-ve-action-btn[data-action="select-parent"]').prop('disabled', !hasParent);
            $('.opti-ab-ve-action-btn[data-action="select-child"]').prop('disabled', !(el && el.hasChildren));
        },

        /**
         * Show element info section (tag name, origin/source detection).
         */
        showElementInfo: function (data) {
            var $info = $('#opti-ab-ve-element-info');
            var $actions = $('#opti-ab-ve-actions');

            // Element tag
            var tagDisplay = '<' + (data.tagName || 'div') + '>';
            if (data.selector && data.selector.length < 60) {
                tagDisplay = data.selector;
            }
            $('#opti-ab-ve-element-tag').text(tagDisplay);

            // Origin / Source detection
            var origin = data.origin;
            if (origin && origin.source && origin.source !== 'Unknown') {
                $('#opti-ab-ve-element-origin').text(origin.source);
                // Set badge color based on source
                var badgeColor = this.getOriginColor(origin.source);
                $('#opti-ab-ve-element-origin').css({
                    'background': badgeColor,
                    'color': '#fff'
                });
                $('#opti-ab-ve-element-origin-wrap').show();
            } else {
                $('#opti-ab-ve-element-origin-wrap').hide();
            }

            // Block type
            if (origin && origin.blockType) {
                $('#opti-ab-ve-element-type').text(origin.blockType);
                $('#opti-ab-ve-element-type-wrap').show();
            } else {
                $('#opti-ab-ve-element-type-wrap').hide();
            }

            $info.slideDown(150);
            $actions.slideDown(150);
        },

        /**
         * Get a color for the origin badge based on the source name.
         */
        getOriginColor: function (source) {
            var colors = {
                'Elementor': '#92003B',
                'WordPress (Gutenberg)': '#0073aa',
                'WooCommerce': '#7f54b3',
                'Kadence': '#0058b0',
                'Spectra (UAG)': '#6104e2',
                'GenerateBlocks': '#1e73be',
                'Astra Theme': '#ff7a00',
                'Beaver Builder': '#1172b8',
                'Divi': '#7c3aed',
                'SeedProd': '#00a32a',
                'SureForms': '#3b82f6',
                'Starter Templates': '#6c2eb9',
                'Contact Form 7': '#f7a046',
                'Yoast SEO': '#a4286a',
                'Theme': '#374151'
            };
            return colors[source] || '#6b7280';
        },

        /**
         * Hide element info section.
         */
        hideElementInfo: function () {
            $('#opti-ab-ve-element-info').slideUp(150);
            $('#opti-ab-ve-actions').slideUp(150);
        },

        /**
         * Show the edit panel for the selected element.
         *
         * The inspector surfaces 7+ tabs:
         *   - Text, HTML, CSS (always)
         *   - URL          (for <a>, <img>, <form>, <iframe>, media sources)
         *   - Attributes   (dynamic form generated from tag)
         *   - Classes      (chip editor)
         *   - JS           (custom snippet that receives `el`)
         *   - Image        (for <img> only)
         */
        showEditPanel: function () {
            var el = this.selectedElement;
            if (!el) return;

            $('#opti-ab-ve-text-editor').val(el.text);
            $('#opti-ab-ve-html-editor').val(el.html);
            $('#opti-ab-ve-css-editor').val(el.css || '');
            $('#opti-ab-ve-hide-element').prop('checked', false);
            $('#opti-ab-ve-js-editor').val('');

            var isImage = el.tagName === 'img';
            var hasUrl = this.tagHasUrl(el.tagName);

            // URL tab: visible when the element carries an href/src/action.
            if (hasUrl) {
                $('#opti-ab-ve-url-tab').show();
                var hrefVal = this.getPrimaryUrlAttr(el);
                $('#opti-ab-ve-url-href').val(hrefVal);
                if (el.tagName === 'a') {
                    $('#opti-ab-ve-url-target-wrap').show();
                    $('#opti-ab-ve-url-rel-wrap').show();
                    $('#opti-ab-ve-url-target').val(el.attributes.target || '');
                    $('#opti-ab-ve-url-rel').val(el.attributes.rel || '');
                } else {
                    $('#opti-ab-ve-url-target-wrap').hide();
                    $('#opti-ab-ve-url-rel-wrap').hide();
                }
            } else {
                $('#opti-ab-ve-url-tab').hide();
            }

            // Attributes tab — dynamic form.
            this.renderAttributesForm(el);

            // Classes tab — chip list.
            this.renderClassesChips(el.classes || []);

            // Design tab — pre-fill all visual controls from computed styles.
            this.populateDesignTab(el);

            // Image tab — show only for <img> elements
            if (isImage) {
                $('#opti-ab-ve-image-tab').show();
                $('#opti-ab-ve-image-url').val(el.imageSrc || '');
                $('#opti-ab-ve-image-preview-img').attr('src', el.imageSrc || '');
            } else {
                $('#opti-ab-ve-image-tab').hide();
            }

            // Check if this selector already has a change.
            var existing = this.findChangeBySelector(el.selector);
            if (existing !== null) {
                var change = this.changes[existing];
                if (change.text) $('#opti-ab-ve-text-editor').val(change.text);
                if (change.html) $('#opti-ab-ve-html-editor').val(change.html);
                if (change.css) $('#opti-ab-ve-css-editor').val(change.css);
                if (change.hide) $('#opti-ab-ve-hide-element').prop('checked', true);
                if (change.image_src && isImage) {
                    $('#opti-ab-ve-image-url').val(change.image_src);
                    $('#opti-ab-ve-image-preview-img').attr('src', change.image_src);
                }
                if (change.js) $('#opti-ab-ve-js-editor').val(change.js);
                if (change.attributes) {
                    for (var k in change.attributes) {
                        if (!Object.prototype.hasOwnProperty.call(change.attributes, k)) continue;
                        var $f = $('.opti-ab-ve-attr-field[data-name="' + this.escAttr(k) + '"]');
                        if ($f.length) {
                            $f.val(change.attributes[k] == null ? '' : change.attributes[k]);
                        }
                        if (k === 'href' || k === 'src' || k === 'action') {
                            $('#opti-ab-ve-url-href').val(change.attributes[k] || '');
                        }
                        if (k === 'target') $('#opti-ab-ve-url-target').val(change.attributes[k] || '');
                        if (k === 'rel') $('#opti-ab-ve-url-rel').val(change.attributes[k] || '');
                    }
                }
            }

            $('#opti-ab-ve-edit-panel').slideDown(200);

            // Reset to the most relevant starting tab.
            // Design is the primary entry point for visual editing now;
            // URL/Image only win when the element is inherently link/image-shaped.
            $('.opti-ab-ve-tab').removeClass('active');
            $('.opti-ab-ve-tab-content').removeClass('active');
            var startTab = 'design';
            if (isImage) startTab = 'image';
            else if (hasUrl && el.tagName === 'a') startTab = 'url';
            $('.opti-ab-ve-tab[data-tab="' + startTab + '"]').addClass('active');
            $('.opti-ab-ve-tab-content[data-tab="' + startTab + '"]').addClass('active');
        },

        /**
         * Whether a tag has a primary URL attribute (href / src / action).
         */
        tagHasUrl: function (tag) {
            return ['a', 'img', 'form', 'iframe', 'video', 'audio', 'source', 'script', 'link'].indexOf(tag) !== -1;
        },

        /**
         * Read the primary URL-bearing attribute for a tag.
         */
        getPrimaryUrlAttr: function (el) {
            if (!el || !el.attributes) return '';
            if (el.tagName === 'a') return el.attributes.href || '';
            if (el.tagName === 'form') return el.attributes.action || '';
            if (el.tagName === 'link') return el.attributes.href || '';
            return el.attributes.src || '';
        },

        /**
         * Attribute schema per tag — shown in the Attributes tab.
         *
         * Each entry: { name, label, type, placeholder, options? }
         * type ∈ { text, number, url, checkbox, select }
         */
        getAttributeSchema: function (tag) {
            var i18n = (window.optiBehaviorVisualEditor && optiBehaviorVisualEditor.strings) || {};
            var defaultLabel = i18n.attr_default_option || '(default)';
            var newTabLabel = '_blank (' + (i18n.attr_new_tab || 'new tab') + ')';
            var descriptiveTextPlaceholder = i18n.attr_descriptive_text || 'Descriptive text';
            var titleTooltipLabel = 'title (' + (i18n.attr_tooltip_hint || 'tooltip') + ')';
            var common = [
                { name: 'id', label: 'ID', type: 'text', placeholder: 'my-id' },
                { name: 'title', label: titleTooltipLabel, type: 'text', placeholder: '' },
                { name: 'aria-label', label: 'aria-label', type: 'text', placeholder: '' },
                { name: 'role', label: 'role', type: 'text', placeholder: 'button' }
            ];
            var byTag = {
                a: [
                    { name: 'href', label: 'href', type: 'url', placeholder: 'https://…' },
                    { name: 'target', label: 'target', type: 'select', options: [
                        { v: '', l: defaultLabel }, { v: '_blank', l: newTabLabel },
                        { v: '_self', l: '_self' }, { v: '_parent', l: '_parent' }, { v: '_top', l: '_top' }
                    ]},
                    { name: 'rel', label: 'rel', type: 'text', placeholder: 'noopener noreferrer' },
                    { name: 'download', label: 'download', type: 'text', placeholder: 'filename.pdf' }
                ],
                img: [
                    { name: 'src', label: 'src', type: 'url', placeholder: 'https://…' },
                    { name: 'alt', label: 'alt', type: 'text', placeholder: descriptiveTextPlaceholder },
                    { name: 'width', label: 'width', type: 'number', placeholder: '600' },
                    { name: 'height', label: 'height', type: 'number', placeholder: '400' },
                    { name: 'loading', label: 'loading', type: 'select', options: [
                        { v: '', l: defaultLabel }, { v: 'lazy', l: 'lazy' }, { v: 'eager', l: 'eager' }
                    ]}
                ],
                button: [
                    { name: 'type', label: 'type', type: 'select', options: [
                        { v: '', l: defaultLabel }, { v: 'button', l: 'button' },
                        { v: 'submit', l: 'submit' }, { v: 'reset', l: 'reset' }
                    ]},
                    { name: 'disabled', label: 'disabled', type: 'checkbox' },
                    { name: 'name', label: 'name', type: 'text' },
                    { name: 'value', label: 'value', type: 'text' }
                ],
                input: [
                    { name: 'type', label: 'type', type: 'text', placeholder: 'text | email | number | …' },
                    { name: 'name', label: 'name', type: 'text' },
                    { name: 'value', label: 'value', type: 'text' },
                    { name: 'placeholder', label: 'placeholder', type: 'text' },
                    { name: 'required', label: 'required', type: 'checkbox' },
                    { name: 'disabled', label: 'disabled', type: 'checkbox' }
                ],
                textarea: [
                    { name: 'name', label: 'name', type: 'text' },
                    { name: 'placeholder', label: 'placeholder', type: 'text' },
                    { name: 'rows', label: 'rows', type: 'number' },
                    { name: 'required', label: 'required', type: 'checkbox' }
                ],
                select: [
                    { name: 'name', label: 'name', type: 'text' },
                    { name: 'required', label: 'required', type: 'checkbox' },
                    { name: 'multiple', label: 'multiple', type: 'checkbox' }
                ],
                form: [
                    { name: 'action', label: 'action (URL)', type: 'url', placeholder: '/submit' },
                    { name: 'method', label: 'method', type: 'select', options: [
                        { v: '', l: defaultLabel }, { v: 'GET', l: 'GET' }, { v: 'POST', l: 'POST' }
                    ]},
                    { name: 'enctype', label: 'enctype', type: 'text' }
                ],
                iframe: [
                    { name: 'src', label: 'src', type: 'url' },
                    { name: 'width', label: 'width', type: 'number' },
                    { name: 'height', label: 'height', type: 'number' },
                    { name: 'allow', label: 'allow', type: 'text' }
                ],
                video: [
                    { name: 'src', label: 'src', type: 'url' },
                    { name: 'poster', label: 'poster', type: 'url' },
                    { name: 'controls', label: 'controls', type: 'checkbox' },
                    { name: 'autoplay', label: 'autoplay', type: 'checkbox' },
                    { name: 'loop', label: 'loop', type: 'checkbox' },
                    { name: 'muted', label: 'muted', type: 'checkbox' }
                ],
                audio: [
                    { name: 'src', label: 'src', type: 'url' },
                    { name: 'controls', label: 'controls', type: 'checkbox' },
                    { name: 'autoplay', label: 'autoplay', type: 'checkbox' },
                    { name: 'loop', label: 'loop', type: 'checkbox' }
                ],
                label: [
                    { name: 'for', label: 'for', type: 'text', placeholder: 'input-id' }
                ]
            };
            return (byTag[tag] || []).concat(common);
        },

        /**
         * Render the Attributes tab form for the selected element.
         *
         * When the URL tab is also shown (anchor/img/form/iframe/media), the
         * URL-related attributes are excluded here to avoid redundancy — each
         * property is edited in exactly one place. This fixes a real-world UX
         * bug where users would edit href in both tabs and the later-applied
         * one silently won.
         */
        renderAttributesForm: function (el) {
            var $form = $('#opti-ab-ve-attrs-form');
            $form.empty();

            var schema = this.getAttributeSchema(el.tagName);
            var attrs = el.attributes || {};

            // Attributes owned by the URL tab for the current tag.
            if (this.tagHasUrl(el.tagName)) {
                var urlOwned = ['href', 'src', 'action', 'target', 'rel', 'download'];
                schema = schema.filter(function (f) { return urlOwned.indexOf(f.name) === -1; });
            }

            // Image tab already handles these for <img>, so skip in Attrs.
            if (el.tagName === 'img') {
                var imgOwned = ['alt', 'width', 'height', 'loading'];
                schema = schema.filter(function (f) { return imgOwned.indexOf(f.name) === -1; });
            }

            for (var i = 0; i < schema.length; i++) {
                var f = schema[i];
                var val = attrs[f.name];
                if (typeof val === 'undefined') val = '';

                var id = 'opti-ab-ve-attr-' + f.name;
                var html = '<div class="opti-ab-ve-attr-row">';
                html += '  <label for="' + id + '">' + this.escHtml(f.label) + ' <code>' + this.escHtml(f.name) + '</code></label>';

                if (f.type === 'checkbox') {
                    var checked = (val === '' || val === null) ? '' : (val === 'false' ? '' : 'checked');
                    html += '<label class="opti-ab-ve-attr-check"><input type="checkbox" class="opti-ab-ve-attr-field" id="' + id + '" data-name="' + this.escAttr(f.name) + '" ' + checked + '> <span>' + this.escHtml(f.name) + '</span></label>';
                } else if (f.type === 'select') {
                    html += '<select class="opti-ab-ve-input opti-ab-ve-attr-field" id="' + id + '" data-name="' + this.escAttr(f.name) + '">';
                    for (var k = 0; k < f.options.length; k++) {
                        var op = f.options[k];
                        html += '<option value="' + this.escAttr(op.v) + '"' + (op.v === val ? ' selected' : '') + '>' + this.escHtml(op.l) + '</option>';
                    }
                    html += '</select>';
                } else {
                    html += '<input type="' + (f.type === 'number' ? 'number' : 'text') + '" class="opti-ab-ve-input opti-ab-ve-attr-field" id="' + id + '" data-name="' + this.escAttr(f.name) + '" value="' + this.escAttr(val) + '" placeholder="' + this.escAttr(f.placeholder || '') + '">';
                }
                html += '</div>';
                $form.append(html);
            }
        },

        // =============================================================
        //  DESIGN TAB — visual controls for color / type / spacing / etc.
        // =============================================================

        /**
         * Populate the Design tab with the element's current computed styles.
         * Values are normalised to the form each control expects (px → number,
         * rgb → hex, etc.) so the UI reflects what the page renders today.
         */
        populateDesignTab: function (el) {
            var c = (el && el.computed) || {};

            // ---- Colors ----
            $('.opti-ab-ve-color-field').each(function () {
                var $wrap = $(this);
                var prop = $wrap.data('prop');
                var raw = c[prop] || '';
                var hex = VE.cssColorToHex(raw);
                $wrap.find('[data-role="swatch"]').val(hex || '#000000');
                $wrap.find('[data-role="hex"]').val(hex || '');
                // Fresh selection — reset touched state.
                $wrap.attr('data-touched', '0');
            });

            // ---- Numeric / unit controls ----
            $('.opti-ab-ve-design-control[data-unit="px"]').each(function () {
                var $input = $(this);
                var prop = $input.data('prop');
                var raw = c[prop] || '';
                var n = parseFloat(raw);
                $input.val(isNaN(n) ? '' : n);
            });

            // ---- line-height / opacity (unit-less) ----
            $('#opti-ab-ve-ds-lineheight').val(c['line-height'] && c['line-height'] !== 'normal' ? parseFloat(c['line-height']) || '' : '');
            $('#opti-ab-ve-ds-opacity').val(c['opacity'] ? parseFloat(c['opacity']) : 1);

            // ---- Selects ----
            $('#opti-ab-ve-ds-fontweight').val(VE.closestWeight(c['font-weight']));
            $('#opti-ab-ve-ds-borderstyle').val(VE.matchOption('#opti-ab-ve-ds-borderstyle', c['border-style']));
            $('#opti-ab-ve-ds-cursor').val(VE.matchOption('#opti-ab-ve-ds-cursor', c['cursor']));
            // Box-shadow matches one of the preset options.
            $('#opti-ab-ve-ds-shadow').val(VE.matchOption('#opti-ab-ve-ds-shadow', c['box-shadow']) || '');

            // ---- Segment button groups (align / transform) ----
            $('.opti-ab-ve-btn-group[data-prop="text-align"] .opti-ab-ve-seg-btn').removeClass('is-active');
            if (c['text-align']) {
                $('.opti-ab-ve-btn-group[data-prop="text-align"] .opti-ab-ve-seg-btn[data-value="' + c['text-align'] + '"]').addClass('is-active');
            }
            $('.opti-ab-ve-btn-group[data-prop="text-transform"] .opti-ab-ve-seg-btn').removeClass('is-active');
            if (c['text-transform']) {
                $('.opti-ab-ve-btn-group[data-prop="text-transform"] .opti-ab-ve-seg-btn[data-value="' + c['text-transform'] + '"]').addClass('is-active');
            }

            // ---- Style toggles (italic / underline / strike) ----
            $('.opti-ab-ve-design-toggle').each(function () {
                var $t = $(this);
                var prop = $t.data('prop');
                var onVal = $t.data('on');
                var cur = (c[prop] || c['text-decoration-line'] || '') + '';
                $t.prop('checked', cur.indexOf(onVal) !== -1);
            });

            // Replay an existing change (css) if we have one stored.
            var existing = VE.findChangeBySelector(el.selector);
            if (existing !== null) {
                var ch = VE.changes[existing];
                if (ch.design && typeof ch.design === 'object') {
                    VE.applyDesignStateToControls(ch.design);
                }
            }
        },

        /**
         * Overlay a saved design state onto the Design tab controls.
         */
        applyDesignStateToControls: function (design) {
            for (var prop in design) {
                if (!Object.prototype.hasOwnProperty.call(design, prop)) continue;
                var val = design[prop] || '';
                // Colors → hex inputs + swatches.
                var $cwrap = $('.opti-ab-ve-color-field[data-prop="' + prop + '"]');
                if ($cwrap.length) {
                    var hex = this.cssColorToHex(val);
                    $cwrap.find('[data-role="hex"]').val(hex || val);
                    if (hex) $cwrap.find('[data-role="swatch"]').val(hex);
                    // A saved design state counts as "user-set" so re-save emits it.
                    $cwrap.attr('data-touched', '1');
                    continue;
                }
                // Numeric px control.
                var $pxc = $('.opti-ab-ve-design-control[data-prop="' + prop + '"][data-unit="px"]');
                if ($pxc.length) { $pxc.val(parseFloat(val) || ''); continue; }
                // Button groups.
                var $group = $('.opti-ab-ve-btn-group[data-prop="' + prop + '"]');
                if ($group.length) {
                    $group.find('.opti-ab-ve-seg-btn').removeClass('is-active');
                    $group.find('.opti-ab-ve-seg-btn[data-value="' + val + '"]').addClass('is-active');
                    continue;
                }
                // Generic control (select / range / number).
                var $g = $('.opti-ab-ve-design-control[data-prop="' + prop + '"]');
                if ($g.length) $g.val(val);
            }
        },

        /**
         * Read all Design-tab controls and return only the properties the
         * user actually set (non-empty and different from computed default).
         * Shape: { 'color':'#ff0000', 'padding-top':'20px', … }
         */
        readDesignTabState: function () {
            var state = {};
            var self = this;

            // Colors — use hex input as canonical. Only include a colour if
            // the user actually touched the picker/hex field, which avoids
            // emitting the element's already-computed colour just because it
            // happened to pre-fill the swatch.
            $('.opti-ab-ve-color-field').each(function () {
                var $w = $(this);
                var prop = $w.data('prop');
                var hex = ($w.find('[data-role="hex"]').val() || '').trim();
                var touched = $w.attr('data-touched') === '1';
                // Valid #RRGGBB only — prevents pushing "#zzz" invalid values.
                if (touched && /^#[0-9a-f]{6}$/i.test(hex)) {
                    state[prop] = hex;
                }
            });

            // Numeric px-unit controls.
            $('.opti-ab-ve-design-control[data-unit="px"]').each(function () {
                var $i = $(this);
                var prop = $i.data('prop');
                var v = $i.val();
                if (v !== '' && v !== null) state[prop] = v + 'px';
            });

            // Unit-less numbers: line-height, opacity.
            var lh = $('#opti-ab-ve-ds-lineheight').val();
            if (lh !== '' && lh !== null) state['line-height'] = lh;
            var op = $('#opti-ab-ve-ds-opacity').val();
            if (op !== '' && op !== null && parseFloat(op) < 1) state['opacity'] = op;

            // Selects.
            var fw = $('#opti-ab-ve-ds-fontweight').val();
            if (fw) state['font-weight'] = fw;
            var bs = $('#opti-ab-ve-ds-borderstyle').val();
            if (bs) state['border-style'] = bs;
            var cur = $('#opti-ab-ve-ds-cursor').val();
            if (cur) state['cursor'] = cur;
            var sh = $('#opti-ab-ve-ds-shadow').val();
            if (sh) state['box-shadow'] = sh;

            // Segment button groups.
            $('.opti-ab-ve-btn-group').each(function () {
                var prop = $(this).data('prop');
                var v = $(this).find('.opti-ab-ve-seg-btn.is-active').data('value');
                if (v) state[prop] = v;
            });

            // Style toggles (italic/underline/strike) — only one prop=text-decoration wins.
            $('.opti-ab-ve-design-toggle:checked').each(function () {
                var $t = $(this);
                var prop = $t.data('prop');
                var on = $t.data('on');
                state[prop] = on;
            });

            return state;
        },

        /**
         * Compile a design state object into a CSS declaration string.
         * Only includes rules that differ from the original computed value —
         * so users can freely reset a field to leave it inherited.
         */
        designStateToCss: function (state, computed) {
            var parts = [];
            for (var prop in state) {
                if (!Object.prototype.hasOwnProperty.call(state, prop)) continue;
                var v = state[prop];
                if (v === '' || v === null || typeof v === 'undefined') continue;
                var orig = (computed && computed[prop]) ? ('' + computed[prop]).trim() : '';
                // Normalise for comparison (rgb ↔ hex, integer px).
                if (prop === 'color' || prop === 'background-color' || prop === 'border-color') {
                    var originalHex = this.cssColorToHex(orig);
                    if (originalHex && originalHex.toLowerCase() === v.toLowerCase()) continue;
                } else if (/-(width|radius|size|spacing|top|right|bottom|left)$/.test(prop) ||
                           prop === 'line-height' || prop === 'letter-spacing' || prop === 'opacity') {
                    // Compare as numbers so "52.4833px" and "52.4833" are considered equal.
                    if (parseFloat(orig) === parseFloat(v)) continue;
                } else if (orig === v) {
                    continue;
                }
                parts.push(prop + ': ' + v);
            }
            return parts.join('; ');
        },

        /**
         * Convert any CSS color string to #RRGGBB, or '' if not representable.
         */
        cssColorToHex: function (c) {
            if (!c) return '';
            c = ('' + c).trim();
            if (c.charAt(0) === '#') {
                if (c.length === 4) {
                    return '#' + c[1] + c[1] + c[2] + c[2] + c[3] + c[3];
                }
                if (c.length === 7) return c;
            }
            var m = c.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
            if (m) {
                var pad = function (n) { var s = parseInt(n, 10).toString(16); return s.length < 2 ? '0' + s : s; };
                return '#' + pad(m[1]) + pad(m[2]) + pad(m[3]);
            }
            return '';
        },

        /**
         * Snap a font-weight (can be "bold", "700", "normal") to the closest
         * select option value so the dropdown reflects the current weight.
         */
        closestWeight: function (w) {
            if (!w) return '';
            if (w === 'bold') return '700';
            if (w === 'normal') return '400';
            var n = parseInt(w, 10);
            if (isNaN(n)) return '';
            var opts = [300, 400, 500, 600, 700, 800, 900];
            var best = opts[0], diff = Math.abs(n - opts[0]);
            for (var i = 1; i < opts.length; i++) {
                var d = Math.abs(n - opts[i]);
                if (d < diff) { best = opts[i]; diff = d; }
            }
            return '' + best;
        },

        /**
         * Find the select option whose value matches (or is contained in) a
         * computed style string — used for box-shadow/border-style reconciliation.
         */
        matchOption: function (selectId, value) {
            if (!value) return '';
            var v = ('' + value).trim();
            var match = '';
            $(selectId + ' option').each(function () {
                var ov = ($(this).attr('value') || '').trim();
                if (ov && (ov === v || v.indexOf(ov) !== -1)) match = ov;
            });
            return match;
        },

        /**
         * Render the class chips (click to remove).
         */
        renderClassesChips: function (classes) {
            var S = optiBehaviorVisualEditor.strings || {};
            var $wrap = $('#opti-ab-ve-classes-current');
            $wrap.empty();
            if (!classes.length) {
                $wrap.html('<span class="opti-ab-ve-help">' + (S.no_classes || '(no classes)') + '</span>');
                return;
            }
            var removeLabel = S.remove || 'Remove';
            for (var i = 0; i < classes.length; i++) {
                var c = classes[i];
                $wrap.append(
                    '<span class="opti-ab-ve-chip" data-class="' + this.escAttr(c) + '">' +
                    this.escHtml(c) +
                    '<button type="button" class="opti-ab-ve-chip__x" title="' + removeLabel + '" aria-label="' + removeLabel + '">&times;</button>' +
                    '</span>'
                );
            }
        },

        /**
         * Apply the current edit.
         * Collects every tab's state and compiles it into a single change record.
         */
        /**
         * Compile the current inspector state into a change object.
         * Shared by `previewCurrentEdit` (live) and `applyEdit` (commit).
         */
        buildCurrentChange: function () {
            var selector = this.selectedSelector;
            if (!selector) return null;

            var orig = this.selectedElement || { text: '', html: '', imageSrc: '', attributes: {}, classes: [] };
            var newText = $('#opti-ab-ve-text-editor').val();
            var newHtml = $('#opti-ab-ve-html-editor').val();
            var newCss = $('#opti-ab-ve-css-editor').val();
            var newImageSrc = $('#opti-ab-ve-image-url').val();
            var newJs = $('#opti-ab-ve-js-editor').val();

            var change = {
                selector: selector,
                hide: $('#opti-ab-ve-hide-element').is(':checked')
            };

            if (newText !== (orig.text || '')) change.text = newText;
            if (newHtml !== (orig.html || '')) change.html = newHtml;
            if (newJs) change.js = newJs;

            // Design tab → compile visual controls into a CSS declaration
            // string. Merged with the raw CSS tab so a user can combine both.
            var designState = this.readDesignTabState();
            var designCss = this.designStateToCss(designState, orig.computed || {});
            var combinedCss = [designCss, newCss].filter(Boolean).join('; ');
            if (combinedCss) change.css = combinedCss;
            if (Object.keys(designState).length) change.design = designState;

            // Image source.
            if (orig.tagName === 'img') {
                if (newImageSrc && newImageSrc !== (orig.imageSrc || '')) {
                    change.image_src = newImageSrc;
                }
            }

            // URL tab overrides (href / src / action) — normalise to attributes map.
            var attrChanges = {};
            if (this.tagHasUrl(orig.tagName)) {
                var urlField = $('#opti-ab-ve-url-href').val();
                var urlKey = orig.tagName === 'a' ? 'href' : (orig.tagName === 'form' ? 'action' : (orig.tagName === 'link' ? 'href' : 'src'));
                var origUrlVal = (orig.attributes && orig.attributes[urlKey]) || '';
                if (urlField !== origUrlVal) {
                    attrChanges[urlKey] = urlField;
                }
                if (orig.tagName === 'a') {
                    var t = $('#opti-ab-ve-url-target').val();
                    if (t !== (orig.attributes.target || '')) attrChanges.target = t;
                    var r = $('#opti-ab-ve-url-rel').val();
                    if (r !== (orig.attributes.rel || '')) attrChanges.rel = r;
                }
            }

            // Attributes tab — diff every field against original.
            $('.opti-ab-ve-attr-field').each(function () {
                var $f = $(this);
                var name = $f.data('name');
                if (!name) return;
                var val;
                if ($f.is(':checkbox')) {
                    val = $f.is(':checked') ? '' : null; // boolean attr → presence only
                } else {
                    val = $f.val();
                }
                var origVal = (orig.attributes && typeof orig.attributes[name] !== 'undefined') ? orig.attributes[name] : '';
                // Normalise boolean attrs: original is either empty-string (present) or missing.
                if ($f.is(':checkbox')) {
                    var wasPresent = typeof orig.attributes[name] !== 'undefined';
                    var isChecked = $f.is(':checked');
                    if (wasPresent !== isChecked) {
                        attrChanges[name] = isChecked ? '' : null;
                    }
                } else if (val !== origVal) {
                    attrChanges[name] = val;
                }
            });

            if (Object.keys(attrChanges).length) {
                change.attributes = attrChanges;
            }

            // Classes tab — diff pending classes.
            var currentClasses = (orig.classes || []).slice();
            var nextClasses = [];
            $('#opti-ab-ve-classes-current .opti-ab-ve-chip').each(function () {
                nextClasses.push($(this).data('class'));
            });
            var add = nextClasses.filter(function (c) { return currentClasses.indexOf(c) === -1; });
            var remove = currentClasses.filter(function (c) { return nextClasses.indexOf(c) === -1; });
            if (add.length) change.classes_add = add;
            if (remove.length) change.classes_remove = remove;

            return change;
        },

        /**
         * Live preview: render the user's in-flight edit in the iframe
         * without committing it to the changes list. Merged with existing
         * committed changes so the preview reflects the final state.
         */
        previewCurrentEdit: function () {
            var draft = this.buildCurrentChange();
            if (!draft) return;
            // Merge: if a committed non-structural change already targets
            // this selector, the draft replaces it in the preview set.
            // Structural changes (insert_after / duplicate / move_delta)
            // are always kept — they must not be replaced by a design edit.
            var previewChanges = [];
            var matched = false;
            for (var i = 0; i < this.changes.length; i++) {
                if (this.changes[i].selector === draft.selector && !this.isStructuralChange(this.changes[i])) {
                    previewChanges.push(draft);
                    matched = true;
                } else {
                    previewChanges.push(this.changes[i]);
                }
            }
            if (!matched) previewChanges.push(draft);
            this.postToIframe({ type: 'apply_changes', changes: previewChanges });
        },

        /**
         * Commit the current edit to the persistent changes list.
         */
        applyEdit: function () {
            var change = this.buildCurrentChange();
            if (!change) return;
            var selector = change.selector;

            this.pushUndoSnapshot();
            var existing = this.findChangeBySelector(selector);
            if (existing !== null) {
                this.changes[existing] = change;
            } else {
                this.changes.push(change);
            }

            this.renderChangesList();
            this.updateChangesCount();
            this.cancelEdit();
            this.applyChangesToIframe();
        },

        /**
         * Cancel editing and hide the edit panel.
         * Also drops any live-preview changes so the iframe reflects only
         * the committed set.
         */
        cancelEdit: function () {
            this.selectedElement = null;
            this.selectedSelector = '';
            $('#opti-ab-ve-edit-panel').slideUp(200);
            this.hideElementInfo();

            // Reset preview to committed state (drops uncommitted edits).
            this.applyChangesToIframe();

            // Tell iframe to deselect.
            this.postToIframe({ type: 'deselect' });
        },

        /**
         * Handle element action buttons.
         */
        handleAction: function (action) {
            var str = optiBehaviorVisualEditor.strings || {};

            switch (action) {
                case 'delete':
                    this.actionDelete();
                    break;
                case 'duplicate':
                    this.actionDuplicate();
                    break;
                case 'copy':
                    this.actionCopy();
                    break;
                case 'paste':
                    this.actionPaste();
                    break;
                case 'move-up':
                    this.actionMove(-1);
                    break;
                case 'move-down':
                    this.actionMove(1);
                    break;
                case 'select-parent':
                    this.actionSelectParent();
                    break;
                case 'select-child':
                    this.actionSelectChild();
                    break;
            }
        },

        /**
         * Ask the iframe to select the parent of the current element.
         */
        actionSelectParent: function () {
            if (!this.selectedSelector) return;
            this.postToIframe({ type: 'select_parent_of', selector: this.selectedSelector });
        },

        /**
         * Ask the iframe to select the first element-child of the current element.
         */
        actionSelectChild: function () {
            if (!this.selectedSelector) return;
            this.postToIframe({ type: 'select_first_child_of', selector: this.selectedSelector });
        },

        /**
         * Delete (hide) the selected element.
         */
        actionDelete: function () {
            var selector = this.selectedSelector;
            if (!selector) {
                this.showToast(optiBehaviorVisualEditor.strings.no_element || 'Select an element first.', 'error');
                return;
            }

            var change = { selector: selector, hide: true };

            this.pushUndoSnapshot();
            var existing = this.findChangeBySelector(selector);
            if (existing !== null) {
                this.changes[existing] = change;
            } else {
                this.changes.push(change);
            }

            this.renderChangesList();
            this.updateChangesCount();
            this.applyChangesToIframe();
            this.cancelEdit();
            this.showToast(optiBehaviorVisualEditor.strings.deleted || 'Element hidden!', 'success');
        },

        /**
         * Duplicate the selected element.
         */
        actionDuplicate: function () {
            var selector = this.selectedSelector;
            if (!selector) {
                this.showToast(optiBehaviorVisualEditor.strings.no_element || 'Select an element first.', 'error');
                return;
            }

            // Tell iframe to clone the element.
            this.postToIframe({
                type: 'action',
                action: 'duplicate',
                selector: selector
            });

            // Record as a change for persistence.
            this.pushUndoSnapshot();
            this.changes.push({
                selector: selector,
                duplicate: true
            });

            this.renderChangesList();
            this.updateChangesCount();
        },

        /**
         * Copy the selected element to clipboard.
         */
        actionCopy: function () {
            if (!this.selectedElement) {
                this.showToast(optiBehaviorVisualEditor.strings.no_element || 'Select an element first.', 'error');
                return;
            }

            this.clipboard = {
                html: this.selectedElement.html || '',
                tagName: this.selectedElement.tagName || 'div',
                outerHtml: '<' + (this.selectedElement.tagName || 'div') + '>' +
                           (this.selectedElement.html || '') +
                           '</' + (this.selectedElement.tagName || 'div') + '>'
            };

            // Enable paste button
            $('#opti-ab-ve-paste-btn').prop('disabled', false);

            this.showToast(optiBehaviorVisualEditor.strings.copied || 'Element copied!', 'success');
        },

        /**
         * Paste the clipboard content after the selected element.
         */
        actionPaste: function () {
            if (!this.clipboard) {
                this.showToast(optiBehaviorVisualEditor.strings.nothing_to_paste || 'Nothing to paste.', 'error');
                return;
            }

            var selector = this.selectedSelector;
            if (!selector) {
                this.showToast(optiBehaviorVisualEditor.strings.no_element || 'Select an element first.', 'error');
                return;
            }

            // Tell iframe to insert HTML after the selected element.
            this.postToIframe({
                type: 'action',
                action: 'paste',
                selector: selector,
                html: this.clipboard.outerHtml
            });

            // Record as a change.
            this.pushUndoSnapshot();
            this.changes.push({
                selector: selector,
                insert_after: this.clipboard.outerHtml
            });

            this.renderChangesList();
            this.updateChangesCount();
        },

        /**
         * Move the selected element up or down among its siblings.
         *
         * @param {number} delta  -1 for up, 1 for down
         */
        actionMove: function (delta) {
            var selector = this.selectedSelector;
            if (!selector) {
                this.showToast(optiBehaviorVisualEditor.strings.no_element || 'Select an element first.', 'error');
                return;
            }

            // Tell iframe to move the element.
            this.postToIframe({
                type: 'action',
                action: 'move',
                selector: selector,
                delta: delta
            });

            // Record as a change.
            this.pushUndoSnapshot();
            this.changes.push({
                selector: selector,
                move_delta: delta
            });

            this.renderChangesList();
            this.updateChangesCount();
        },

        /**
         * Open WordPress Media Library picker.
         */
        openMediaLibrary: function () {
            var self = this;

            // If the media frame already exists, reopen it.
            if (this.mediaFrame) {
                this.mediaFrame.open();
                return;
            }

            // Create the media frame.
            this.mediaFrame = wp.media({
                title: optiBehaviorVisualEditor.strings.select_image || 'Select Image',
                button: {
                    text: optiBehaviorVisualEditor.strings.select_image || 'Select Image'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            // When an image is selected.
            this.mediaFrame.on('select', function () {
                var attachment = self.mediaFrame.state().get('selection').first().toJSON();
                var url = attachment.url;

                // Use a specific size if available
                if (attachment.sizes) {
                    if (attachment.sizes.large) {
                        url = attachment.sizes.large.url;
                    } else if (attachment.sizes.medium_large) {
                        url = attachment.sizes.medium_large.url;
                    } else if (attachment.sizes.full) {
                        url = attachment.sizes.full.url;
                    }
                }

                $('#opti-ab-ve-image-url').val(url);
                $('#opti-ab-ve-image-preview-img').attr('src', url);
            });

            this.mediaFrame.open();
        },

        /**
         * Toggle the Add Block panel.
         */
        toggleBlockPanel: function () {
            var $panel = $('#opti-ab-ve-block-panel');
            if ($panel.is(':visible')) {
                this.hideBlockPanel();
            } else {
                this.showBlockPanel();
            }
        },

        /**
         * Show the Add Block panel.
         */
        showBlockPanel: function () {
            $('#opti-ab-ve-block-panel').slideDown(200);
        },

        /**
         * Hide the Add Block panel.
         */
        hideBlockPanel: function () {
            $('#opti-ab-ve-block-panel').slideUp(200);
        },

        /**
         * Insert a block template into the page.
         *
         * @param {Object} template  Block template { name, html }
         */
        insertBlock: function (template) {
            // Resolve the anchor selector once and reuse it for BOTH the
            // live iframe insert AND the persisted change — so live preview
            // and saved state stay in sync.
            //
            // - If the user has an element selected, anchor the new block
            //   after that element (sibling-after semantics).
            // - 'body' / 'html' are treated as "no specific anchor" because
            //   insertBefore(child, body.nextSibling) would place the block
            //   outside </body>, landing at the document end on preview.
            // - Empty anchor means "append to .entry-content / main /
            //   #content" (handled identically in the iframe helper and in
            //   the server-side/runtime JS applier).
            var anchor = this.selectedSelector || '';
            if (anchor === 'body' || anchor === 'html') {
                anchor = '';
            }

            // Tell iframe to insert the block live.
            this.postToIframe({
                type: 'action',
                action: 'insert_block',
                selector: anchor,
                html: template.html
            });

            // Persist the same anchor in the change so reload / preview /
            // production all insert the block at the user's intended spot.
            this.pushUndoSnapshot();
            this.changes.push({
                selector: anchor,
                insert_after: template.html,
                insert_position: 'after',
                block_name: template.name
            });

            this.renderChangesList();
            this.updateChangesCount();
        },

        /**
         * Remove a change by index.
         */
        removeChange: function (idx) {
            if (idx >= 0 && idx < this.changes.length) {
                this.pushUndoSnapshot();
                this.changes.splice(idx, 1);
                this.renderChangesList();
                this.updateChangesCount();
                this.applyChangesToIframe();
            }
        },

        /**
         * Edit an existing change.
         */
        editExistingChange: function (idx) {
            if (idx < 0 || idx >= this.changes.length) return;

            var change = this.changes[idx];
            this.selectedSelector = change.selector;
            this.selectedElement = {
                selector: change.selector,
                tagName: '',
                text: change.text || '',
                html: change.html || '',
                css: change.css || '',
                imageSrc: change.image_src || '',
                origin: null
            };

            this.showEditPanel();

            // Highlight in iframe.
            this.postToIframe({ type: 'highlight', selector: change.selector });
        },

        // ─── Undo / Redo ────────────────────────────────────────────────

        /**
         * Snapshot the current changes array onto the undo stack.
         * Must be called BEFORE every mutation to this.changes.
         * Clears the redo stack (standard behaviour — a new action
         * after undo drops the forward history).
         */
        pushUndoSnapshot: function () {
            this.undoStack.push(JSON.parse(JSON.stringify(this.changes)));
            this.redoStack = [];
            this.updateUndoRedoButtons();
        },

        /**
         * Undo: restore the previous changes snapshot.
         */
        undo: function () {
            if (!this.undoStack.length) return;
            // Save current state for redo.
            this.redoStack.push(JSON.parse(JSON.stringify(this.changes)));
            // Restore previous snapshot.
            this.changes = this.undoStack.pop();
            this.renderChangesList();
            this.updateChangesCount();
            this.applyChangesToIframe();
            this.updateUndoRedoButtons();
            this.cancelEdit();
        },

        /**
         * Redo: re-apply a previously undone snapshot.
         */
        redo: function () {
            if (!this.redoStack.length) return;
            // Save current state for undo.
            this.undoStack.push(JSON.parse(JSON.stringify(this.changes)));
            // Restore forward snapshot.
            this.changes = this.redoStack.pop();
            this.renderChangesList();
            this.updateChangesCount();
            this.applyChangesToIframe();
            this.updateUndoRedoButtons();
            this.cancelEdit();
        },

        /**
         * Enable/disable the undo and redo toolbar buttons based on
         * the current stack depth.
         */
        updateUndoRedoButtons: function () {
            var $undo = $('#opti-ab-ve-undo');
            var $redo = $('#opti-ab-ve-redo');
            if ($undo.length) $undo.prop('disabled', !this.undoStack.length);
            if ($redo.length) $redo.prop('disabled', !this.redoStack.length);
        },

        /**
         * Return true when a change object is a DOM-structural operation
         * (Insert Block / Duplicate / Move) rather than a style/content edit.
         *
         * Structural changes must never be merged with or overwritten by
         * design/text edits, even when both share the same anchor selector.
         */
        isStructuralChange: function (change) {
            return !!(change.insert_after || change.duplicate || typeof change.move_delta !== 'undefined');
        },

        /**
         * Find a **non-structural** change index by CSS selector.
         *
         * Structural changes (insert_after / duplicate / move_delta) are
         * intentionally skipped so that `applyEdit` / `previewCurrentEdit`
         * never overwrite an inserted-block entry with a design edit that
         * happens to target the same anchor element.
         */
        findChangeBySelector: function (selector) {
            for (var i = 0; i < this.changes.length; i++) {
                if (this.changes[i].selector === selector && !this.isStructuralChange(this.changes[i])) {
                    return i;
                }
            }
            return null;
        },

        /**
         * Render the changes list in the sidebar.
         */
        renderChangesList: function () {
            var S = optiBehaviorVisualEditor.strings || {};
            var $list = $('#opti-ab-ve-changes-list');
            $list.empty();

            if (this.changes.length === 0) {
                $list.html('<p class="opti-ab-ve-no-changes">' + (S.no_changes || 'No changes yet.') + '</p>');
                return;
            }

            for (var i = 0; i < this.changes.length; i++) {
                var c = this.changes[i];
                var label = c.selector;
                if (label.length > 40) label = label.substring(0, 37) + '...';

                // Icon based on change type
                var typeIcon = '✏️';
                var typeLabel = '';
                if (c.hide) {
                    typeIcon = '🚫';
                    typeLabel = 'Hide';
                } else if (c.image_src) {
                    typeIcon = '🖼️';
                    typeLabel = 'Image';
                } else if (c.duplicate) {
                    typeIcon = '📋';
                    typeLabel = 'Duplicate';
                } else if (c.insert_after) {
                    typeIcon = '➕';
                    typeLabel = c.block_name || 'Insert';
                } else if (c.move_delta) {
                    typeIcon = c.move_delta < 0 ? '⬆️' : '⬇️';
                    typeLabel = 'Move';
                } else if (c.js) {
                    typeIcon = '⚡';
                    typeLabel = 'JS';
                } else if (c.attributes) {
                    typeIcon = '🔧';
                    typeLabel = 'Attrs';
                } else if (c.classes_add || c.classes_remove) {
                    typeIcon = '🏷️';
                    typeLabel = 'Classes';
                } else if (c.css) {
                    typeIcon = '🎨';
                    typeLabel = 'CSS';
                }

                var typeTag = typeLabel
                    ? ' <span class="opti-ab-ve-change-type">' + this.escHtml(typeLabel) + '</span>'
                    : '';

                $list.append(
                    '<div class="opti-ab-ve-change-item" data-index="' + i + '">' +
                    '  <span class="opti-ab-ve-change-icon">' + typeIcon + '</span>' +
                    '  <span class="opti-ab-ve-change-selector" title="' + this.escAttr(c.selector) + '">' +
                         this.escHtml(label) + typeTag +
                    '  </span>' +
                    '  <button type="button" class="opti-ab-ve-change-remove" title="' + (S.remove || 'Remove') + '">&times;</button>' +
                    '</div>'
                );
            }
        },

        /**
         * Update the changes count badge — both in the top toolbar and the
         * inline header count chip.
         */
        updateChangesCount: function () {
            var str = optiBehaviorVisualEditor.strings || {};
            $('#opti-ab-ve-changes-count').text(this.changes.length + ' ' + (str.changes_label || 'changes'));
            $('#opti-ab-ve-header-count').text(this.changes.length);
        },

        /**
         * Set the editor mode (edit/preview).
         */
        setMode: function (mode) {
            this.mode = mode;
            $('.opti-ab-ve-mode-btn').removeClass('active');
            $('.opti-ab-ve-mode-btn[data-mode="' + mode + '"]').addClass('active');

            // Tell iframe to stop intercepting clicks when in preview mode.
            this.postToIframe({ type: 'set_mode', mode: mode });

            if (mode === 'preview') {
                // Auto-commit any in-flight inspector draft before hiding the
                // panel so the server-side preview receives the exact changes
                // currently visible in the editor iframe.
                if (this.hasPendingEdit && this.hasPendingEdit()) {
                    this.applyEdit();
                } else {
                    this.cancelEdit();
                }
                this.openLivePreview();
            }
        },

        /**
         * Save current changes and open a frontend preview via a native
         * target=_blank form submit. This avoids script-created popups while
         * still ensuring the new tab sees the latest Visual Editor changes.
         */
        openLivePreview: function () {
            var self = this;
            var $form = $('#opti-ab-ve-preview-form');
            var $changes = $('#opti-ab-ve-preview-changes');

            var S = optiBehaviorVisualEditor.strings || {};
            if (!$form.length || !$changes.length) {
                this.showToast(S.preview_form_unavailable || 'Preview form is unavailable.', 'error');
                setTimeout(function () { self.setMode('edit'); }, 100);
                return;
            }

            if (!this.targetUrl) {
                this.showToast(S.no_target_url || 'No target URL to preview.', 'error');
                setTimeout(function () { self.setMode('edit'); }, 100);
                return;
            }

            $changes.val(JSON.stringify(this.changes));

            try {
                $form[0].submit();
                this.showToast(S.preview_opened || 'Preview opened in a new tab.', 'success');
            } catch (e) {
                this.showToast(S.preview_failed || 'Could not open preview. Please try again.', 'error');
            }

            setTimeout(function () { self.setMode('edit'); }, 400);
        },

        /**
         * Apply all changes to the iframe.
         */
        applyChangesToIframe: function () {
            this.postToIframe({
                type: 'apply_changes',
                changes: this.changes
            });
        },

        /**
         * Send a message to the iframe.
         */
        postToIframe: function (data) {
            var iframe = document.getElementById('opti-ab-ve-iframe');
            if (iframe && iframe.contentWindow) {
                data.source = 'opti-ab-ve-parent';
                iframe.contentWindow.postMessage(JSON.stringify(data), '*');
            }
        },

        /**
         * Does the current draft contain any actual edit vs the original?
         * Used to decide whether to auto-commit on element switch or Save.
         */
        hasPendingEdit: function () {
            if (!this.selectedSelector) return false;
            var c = this.buildCurrentChange();
            if (!c) return false;
            // A change is "meaningful" if it has any property besides the
            // boilerplate selector + hide=false.
            //
            // The `design` key is always populated with the element's
            // current computed values (readDesignTabState), even when the
            // user never touched the Design tab.  It only represents a
            // real edit when `designStateToCss` detected a diff and set
            // the `css` key as well.  Ignore a lone `design` with no
            // accompanying `css` to prevent spurious auto-commits.
            for (var k in c) {
                if (!Object.prototype.hasOwnProperty.call(c, k)) continue;
                if (k === 'selector') continue;
                if (k === 'hide' && c[k] === false) continue;
                if (k === 'design' && !c.css) continue;
                return true;
            }
            return false;
        },

        /**
         * Save all changes to the server.
         *
         * Auto-commits any in-flight inspector draft so users don't have to
         * remember to click Apply before Save Changes. This was the single
         * biggest footgun in the previous workflow — users would see live
         * preview, click Save, and wonder why the colour didn't persist.
         */
        saveChanges: function () {
            var self = this;
            var $btn = $('#opti-ab-ve-save');

            // Auto-commit any pending draft so Save always persists what the
            // user sees in the iframe preview.
            if (this.hasPendingEdit()) {
                this.applyEdit();
            }

            $btn.prop('disabled', true).text(optiBehaviorVisualEditor.strings.saving || 'Saving...');

            $.ajax({
                url: optiBehaviorVisualEditor.ajax_url,
                type: 'POST',
                data: {
                    action: 'opti_behavior_ab_visual_save',
                    nonce: optiBehaviorVisualEditor.nonce,
                    test_id: self.testId,
                    variant_id: self.variantId,
                    changes: JSON.stringify(self.changes)
                },
                success: function (res) {
                    $btn.prop('disabled', false).text(optiBehaviorVisualEditor.strings.save || 'Save Changes');
                    if (res.success) {
                        self.showToast(optiBehaviorVisualEditor.strings.changes_saved || 'Changes saved!', 'success');
                    } else {
                        self.showToast(res.data && res.data.message ? res.data.message : (optiBehaviorVisualEditor.strings.error || 'Error'), 'error');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text(optiBehaviorVisualEditor.strings.save || 'Save Changes');
                    self.showToast(optiBehaviorVisualEditor.strings.network_error || 'Network error.', 'error');
                }
            });
        },

        /**
         * Show a toast notification.
         */
        showToast: function (message, type) {
            var $toast = $('<div class="opti-ab-ve-toast opti-ab-ve-toast--' + (type || 'info') + '">' + this.escHtml(message) + '</div>');
            $('body').append($toast);
            setTimeout(function () { $toast.addClass('show'); }, 10);
            setTimeout(function () {
                $toast.removeClass('show');
                setTimeout(function () { $toast.remove(); }, 300);
            }, 3000);
        },

        /**
         * HTML escape.
         */
        escHtml: function (str) {
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        /**
         * Attribute escape.
         */
        escAttr: function (str) {
            return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    };

    $(document).ready(function () {
        VE.init();
    });

})(jQuery);
