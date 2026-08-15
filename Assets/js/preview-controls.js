/**
 * Safe Preview in-modal controls (Tasks 36, 38 & 39).
 *
 * Drives every interactive control inside a preview modal:
 *   - the syntax language picker      (`data-fic-language-select`)   — reloads
 *   - the CSV delimiter picker and
 *     "first row is header" toggle    (`data-fic-csv-control`)       — reloads
 *   - the Excel sheet tabs            (`data-sheet-index`)           — local only
 *
 * RELOAD CONTROLS: each already carries the fully-built preview URL for the state
 * it would select — a <select> in its option values, a checkbox in `data-fic-url`
 * (pre-toggled by the server). This script only has to navigate, so it holds no
 * state of its own and cannot disagree with what is rendered. That keeps parsing
 * and highlighting server-side, where CSV cells are already entity-escaped and
 * code payloads are escaped before tokenizing; re-rendering either in the browser
 * would mean a second copy of an XSS-sensitive code path.
 *
 * SHEET TABS are purely local — every sheet is already in the DOM, so switching
 * needs no round trip.
 *
 * WHY THIS IS A REGISTERED ASSET, NOT AN INLINE <script>: two independent reasons,
 * either of which alone is fatal.
 *   1. Kanboard's CSP is `default-src 'self'` and `script-src` inherits it
 *      without `'unsafe-inline'`, so an inline block is refused outright.
 *   2. Modal content is injected with `element.innerHTML = html`
 *      (assets/js/core/dom.js) and, per the HTML spec, a <script> inserted that
 *      way never executes — so listeners would not bind even under a permissive
 *      CSP.
 *
 * Every listener is delegated from the document for the same reason: the controls
 * do not exist yet when this file runs.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-fic-language-select], [data-fic-csv-control]';
    var SHEET_TAB_SELECTOR = '[data-sheet-index]';
    var FULLSCREEN_TOGGLE_SELECTOR = '[data-fic-fullscreen-toggle]';
    var FULLSCREEN_CLASS = 'fic-modal-fullscreen';
    var ACTIVE_TAB_BACKGROUND = '#1d6f42';
    var INACTIVE_TAB_BACKGROUND = '#f1f3f5';

    /**
     * The URL this control should navigate to.
     *
     * `data-fic-url` wins so a checkbox can carry its toggled target; a <select>
     * falls back to the chosen option's value.
     */
    function getTargetUrl(control) {
        return control.getAttribute('data-fic-url') || control.value;
    }

    function onChange(event) {
        var control = event.target;

        if (!control || !control.matches || !control.matches(SELECTOR)) {
            return;
        }

        var url = getTargetUrl(control);

        if (!url) {
            return;
        }

        /**
         * KB.modal.replace() swaps the open modal's content in place, which is
         * what re-renders the table or the highlighted source without closing the
         * window. Falling back to a normal navigation covers the preview being
         * opened as a standalone page.
         */
        if (window.KB && window.KB.modal && typeof window.KB.modal.replace === 'function' && window.KB.modal.isOpen()) {
            window.KB.modal.replace(url);
        } else {
            window.location.href = url;
        }
    }

    /**
     * Switch the visible Excel sheet panel.
     *
     * Scoped to the clicked tab's own container rather than the whole document, so
     * two previews open in sequence (or side by side) cannot drive each other.
     * Tabs and panels are paired on the `data-sheet-index` VALUE, never on DOM
     * ordinal position or sheet name — a workbook name containing quotes or markup
     * therefore cannot break the wiring.
     */
    function onSheetTabClick(event) {
        var tab = event.target.closest ? event.target.closest(SHEET_TAB_SELECTOR) : null;

        if (!tab || !tab.classList.contains('fic-sheet-tab')) {
            return;
        }

        event.preventDefault();

        var tabStrip = tab.closest('.fic-sheet-tabs');

        if (!tabStrip || !tabStrip.parentNode) {
            return;
        }

        // Panels are siblings of the tab strip, so its parent is the common root.
        var root = tab.closest('.fic-sheet-container') || tabStrip.parentNode;
        var target = tab.getAttribute('data-sheet-index');

        Array.prototype.forEach.call(root.querySelectorAll('.fic-sheet-panel'), function (panel) {
            panel.style.display = panel.getAttribute('data-sheet-index') === target ? '' : 'none';
        });

        Array.prototype.forEach.call(tabStrip.querySelectorAll('.fic-sheet-tab'), function (other) {
            var isActive = other === tab;

            other.classList.toggle('is-active', isActive);
            other.setAttribute('aria-selected', isActive ? 'true' : 'false');
            other.style.background = isActive ? ACTIVE_TAB_BACKGROUND : INACTIVE_TAB_BACKGROUND;
            other.style.color = isActive ? '#fff' : '#495057';
            other.style.fontWeight = isActive ? '600' : 'normal';
        });

        var badge = root.querySelector('#fic-active-sheet-badge');

        if (badge) {
            // textContent, never innerHTML: the sheet name is already
            // entity-escaped for HTML output, so assigning it as markup would
            // double-unescape it.
            badge.textContent = tab.textContent.trim();
        }
    }

    /**
     * Switch the visible PowerPoint slide panel.
     */
    function onSlideTabClick(event) {
        var tab = event.target.closest ? event.target.closest('[data-slide-index], .fic-slide-tab') : null;

        if (!tab || !tab.classList.contains('fic-slide-tab')) {
            return;
        }

        event.preventDefault();

        var tabStrip = tab.closest('.fic-slide-tabs');

        if (!tabStrip || !tabStrip.parentNode) {
            return;
        }

        var root = tab.closest('.fic-slide-container') || tabStrip.parentNode;
        var target = tab.getAttribute('data-slide-index');

        Array.prototype.forEach.call(root.querySelectorAll('.fic-slide-panel'), function (panel) {
            panel.style.display = panel.getAttribute('data-slide-index') === target ? '' : 'none';
        });

        Array.prototype.forEach.call(tabStrip.querySelectorAll('.fic-slide-tab'), function (other) {
            var isActive = other === tab;

            other.classList.toggle('is-active', isActive);
            other.setAttribute('aria-selected', isActive ? 'true' : 'false');
            other.style.background = isActive ? '#d24726' : INACTIVE_TAB_BACKGROUND;
            other.style.color = isActive ? '#fff' : '#495057';
            other.style.fontWeight = isActive ? '600' : 'normal';
        });

        var badge = root.parentNode.querySelector('#fic-active-slide-badge') || document.querySelector('#fic-active-slide-badge');

        if (badge) {
            badge.textContent = tab.textContent.trim();
        }
    }

    /**
     * Toggle fullscreen on the modal container.
     *
     * The class goes on Kanboard's `#modal-box`, not on our own markup: that is the
     * element core sizes, and the CSS has to override the inline width core sets on
     * it. Nothing is remembered between modals — reopening starts at normal size,
     * which matches how the rest of Kanboard's modals behave.
     */
    function onFullscreenToggleClick(event) {
        var toggle = event.target.closest ? event.target.closest(FULLSCREEN_TOGGLE_SELECTOR) : null;

        if (!toggle) {
            return;
        }

        event.preventDefault();

        // Fall back to the document element so the control still does something
        // when a preview is opened as a standalone page rather than in a modal.
        var box = document.getElementById('modal-box') || document.documentElement;
        var isFullscreen = box.classList.toggle(FULLSCREEN_CLASS);

        toggle.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');

        var label = toggle.querySelector('.fic-fullscreen-label');

        if (label) {
            // textContent, never innerHTML — and read from data attributes so the
            // strings stay translatable server-side.
            label.textContent = isFullscreen
                ? (toggle.getAttribute('data-fic-label-exit') || label.textContent)
                : (toggle.getAttribute('data-fic-label-enter') || label.textContent);
        }
    }

    /**
     * View mode toggle (Rendered / Raw).
     *
     * Swaps the modal content in place via KB.modal.replace() instead of
     * navigating the full page or opening a new tab.
     */
    function onViewModeClick(event) {
        var btn = event.target.closest ? event.target.closest('[data-fic-view-mode], .fic-btn-view-mode') : null;

        if (!btn) {
            return;
        }

        var url = btn.getAttribute('href');

        if (!url || url === '#' || url.startsWith('javascript:')) {
            return;
        }

        event.preventDefault();

        if (window.KB && window.KB.modal && typeof window.KB.modal.replace === 'function' && window.KB.modal.isOpen()) {
            window.KB.modal.replace(url);
        } else {
            window.location.href = url;
        }
    }

    document.addEventListener('change', onChange, false);
    document.addEventListener('click', onSheetTabClick, false);
    document.addEventListener('click', onSlideTabClick, false);
    document.addEventListener('click', onFullscreenToggleClick, false);
    document.addEventListener('click', onViewModeClick, false);
}());


