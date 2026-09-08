/**
 * Opti-Behavior Onboarding Popup
 *
 * Handles step navigation, goal selection, and dismiss/skip via AJAX.
 *
 * @package opti-behavior
 * @since 1.6.0
 */

/* global optiBehaviorOnboarding */
(function () {
    'use strict';

    var current = 0;
    var total   = 4;
    var selectedGoals = [];

    /*
     * Debug preview (?ob_preview_onboarding=1, WP_DEBUG + admin only): the modal
     * is rendered for visual inspection on a site that already has data, so it
     * must stay read-only — no dismissal is recorded and no funnel is created.
     */
    var isPreview = !!optiBehaviorOnboarding.preview;

    var labels     = [
        optiBehaviorOnboarding.i18n.step1of4,
        optiBehaviorOnboarding.i18n.step2of4,
        optiBehaviorOnboarding.i18n.step3of4,
        optiBehaviorOnboarding.i18n.step4of4
    ];
    var progresses = ['25%', '50%', '75%', '100%'];
    var nextLabels = [
        optiBehaviorOnboarding.i18n.btnContinue,
        optiBehaviorOnboarding.i18n.btnContinue,
        optiBehaviorOnboarding.i18n.btnContinue,
        optiBehaviorOnboarding.i18n.btnGoDashboard
    ];

    var arrowSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';

    /* ── helpers ──────────────────────────────────────────────── */

    function el(id) {
        return document.getElementById(id);
    }

    /* ── navigate to step n ──────────────────────────────────── */

    function goTo(n) {
        var stepCurrent = el('ob-step' + current);
        var dotCurrent  = el('ob-dot' + current);

        if (stepCurrent) { stepCurrent.classList.remove('active'); }
        if (dotCurrent)  {
            dotCurrent.classList.remove('active');
            if (current < n) { dotCurrent.classList.add('done'); }
        }

        current = n;

        var stepNext = el('ob-step' + current);
        var dotNext  = el('ob-dot' + current);

        if (stepNext) { stepNext.classList.add('active'); }
        if (dotNext)  {
            dotNext.classList.remove('done');
            dotNext.classList.add('active');
        }

        var fill = el('ob-progressFill');
        if (fill) { fill.style.width = progresses[current]; }

        var label = el('ob-stepLabel');
        if (label) { label.textContent = labels[current]; }

        var btn = el('ob-nextBtn');
        if (btn) {
            if (current < total - 1) {
                btn.innerHTML = nextLabels[current] + ' ' + arrowSvg;
            } else {
                btn.innerHTML = nextLabels[current] + ' ✓';
            }
        }
    }

    /* ── next step or finish ─────────────────────────────────── */

    function nextStep() {
        if (current < total - 1) {
            goTo(current + 1);
        } else {
            // Only this path finishes the setup, so only this path may honour
            // the auto-funnel opt-in.
            dismiss({ finish: true });
        }
    }

    /* ── goal → recipe mapping (mirrors the PHP map) ──────────── */

    function offeredRecipeIds() {
        var list = optiBehaviorOnboarding.recommended || [];
        var ids  = [];
        for (var i = 0; i < list.length; i++) {
            ids.push(list[i].id);
        }
        return ids;
    }

    function recipeLabel(id) {
        var list = optiBehaviorOnboarding.recommended || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) { return list[i].label; }
        }
        return '';
    }

    /**
     * First recipe actually offered for this site among the candidates of the
     * selected goals. Empty string when nothing matches.
     */
    function goalRecipeId() {
        var map     = optiBehaviorOnboarding.goalRecipes || {};
        var offered = offeredRecipeIds();

        for (var g = 0; g < selectedGoals.length; g++) {
            var candidates = map[selectedGoals[g]] || [];
            for (var c = 0; c < candidates.length; c++) {
                if (offered.indexOf(candidates[c]) !== -1) { return candidates[c]; }
            }
        }
        return '';
    }

    /** Show which offered funnel best matches the current goal selection. */
    function refreshGoalMatch() {
        var note = el('ob-createFunnelsMatch');
        if (!note) { return; }

        var label = recipeLabel(goalRecipeId());

        if (!label) {
            note.hidden = true;
            note.textContent = '';
            return;
        }

        note.textContent = (optiBehaviorOnboarding.i18n.goalMatch || '%s').replace('%s', label);
        note.hidden = false;
    }

    /* ── toggle a goal (Step 2 — multi-select) ────────────────── */

    function selectGoal(btn) {
        var goal = btn.getAttribute('data-goal') || '';
        var idx  = selectedGoals.indexOf(goal);

        if (idx === -1) {
            // Add to selection
            selectedGoals.push(goal);
            btn.classList.add('selected');
        } else {
            // Remove from selection
            selectedGoals.splice(idx, 1);
            btn.classList.remove('selected');
        }

        refreshGoalMatch();
    }

    /* ── auto-create opt-in (pre-checked by default) ──────────── */

    function wantsRecommendedFunnels() {
        var box = el('ob-createFunnels');
        return !!(box && box.checked);
    }

    /**
     * Create the recommended Free funnel set through the funnels bulk endpoint.
     * Only ever called when the setup was finished with the opt-in box still
     * ticked; the endpoint itself dedupes by step signature, so a second
     * onboarding run creates nothing.
     */
    function createRecommendedFunnels() {
        if (!optiBehaviorOnboarding.funnelsNonce) { return Promise.resolve(); }

        var data = new FormData();
        data.append('action', 'optibehavior_create_recommended_funnels');
        data.append('nonce', optiBehaviorOnboarding.funnelsNonce);

        return fetch(optiBehaviorOnboarding.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        });
    }

    /* ── dismiss popup (AJAX) ────────────────────────────────── */

    /**
     * Close the popup.
     *
     * @param {{finish: boolean}} [opts] `finish: true` only for the last-step
     *        button — skipping or closing the popup never creates funnels, even
     *        though the opt-in box is pre-checked.
     */
    function dismiss(opts) {
        var overlay = document.querySelector('.ob-setup-overlay');
        if (!overlay) { return; }

        var isFinish = !!(opts && opts.finish);

        // Read the opt-in before the modal markup is replaced below.
        var createFunnels = isFinish && wantsRecommendedFunnels();

        // Show success screen briefly
        var modal = overlay.querySelector('.ob-setup-modal');
        if (modal) {
            modal.innerHTML =
                '<div class="ob-success-screen">' +
                    '<div class="ob-success-icon">' +
                        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>' +
                    '</div>' +
                    '<div class="ob-success-title">' + optiBehaviorOnboarding.i18n.setupComplete + '</div>' +
                    '<div class="ob-success-desc">' + optiBehaviorOnboarding.i18n.setupCompleteDesc + '</div>' +
                '</div>';
        }

        // Preview mode is read-only: never persist the dismissal and never
        // create funnels — only play the closing animation below.
        if (!isPreview) {
            // AJAX call to dismiss
            var data = new FormData();
            data.append('action', 'opti_behavior_dismiss_onboarding');
            data.append('_wpnonce', optiBehaviorOnboarding.nonce);
            data.append('goal', selectedGoals.join(','));
            data.append('create_funnels', createFunnels ? '1' : '0');

            fetch(optiBehaviorOnboarding.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function () {
                // The recommended set is created only when the setup was finished
                // with the opt-in box still ticked.
                if (createFunnels) {
                    return createRecommendedFunnels();
                }
            })['catch'](function () { /* dismissal already persisted server-side */ });
        }

        // Fade out after short delay
        setTimeout(function () {
            overlay.style.transition = 'opacity 0.3s ease';
            overlay.style.opacity = '0';
            setTimeout(function () {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }, 300);
        }, 1500);
    }

    /* ── init on DOM ready ───────────────────────────────────── */

    function init() {
        // Next button
        var nextBtn = el('ob-nextBtn');
        if (nextBtn) {
            nextBtn.addEventListener('click', function (e) {
                e.preventDefault();
                nextStep();
            });
        }

        // Skip button
        var skipBtn = el('ob-skipBtn');
        if (skipBtn) {
            skipBtn.addEventListener('click', function (e) {
                e.preventDefault();
                dismiss();
            });
        }

        // Step dots
        for (var i = 0; i < total; i++) {
            (function (idx) {
                var dot = el('ob-dot' + idx);
                if (dot) {
                    dot.addEventListener('click', function () {
                        goTo(idx);
                    });
                }
            })(i);
        }

        // Goal buttons
        var goalBtns = document.querySelectorAll('.ob-setup-modal .ob-goal-btn');
        for (var g = 0; g < goalBtns.length; g++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    selectGoal(btn);
                });
            })(goalBtns[g]);
        }

        // Close on overlay click (not modal)
        var overlay = document.querySelector('.ob-setup-overlay');
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    dismiss();
                }
            });
        }

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var existingOverlay = document.querySelector('.ob-setup-overlay');
                if (existingOverlay) {
                    dismiss();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
