/**
 * Safe Preview syntax language picker (Task 36).
 *
 * Each <option> carries the full preview URL for its language, so switching is a
 * server-side round trip: Kanboard re-renders the modal with `lang=<id>` and
 * highlighting stays in PHP, where the payload is already entity-escaped.
 * Re-tokenizing in the browser would mean maintaining a second copy of an
 * XSS-sensitive code path.
 *
 * This ships as a registered asset rather than an inline <script> because
 * Kanboard's CSP is `default-src 'self'` and `script-src` inherits it without
 * `'unsafe-inline'` — an inline handler would be silently blocked.
 *
 * The listener is delegated from the document so it also covers pickers injected
 * when the modal content is replaced.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-fic-language-select]';

    function onChange(event) {
        var select = event.target;

        if (!select || !select.matches || !select.matches(SELECTOR)) {
            return;
        }

        var url = select.value;

        if (!url) {
            return;
        }

        /**
         * KB.modal.replace() swaps the open modal's content in place, which is
         * what keeps the picker feeling instant. Falling back to a normal
         * navigation covers the preview being opened as a standalone page.
         */
        if (window.KB && window.KB.modal && typeof window.KB.modal.replace === 'function' && window.KB.modal.isOpen()) {
            window.KB.modal.replace(url);
        } else {
            window.location.href = url;
        }
    }

    document.addEventListener('change', onChange, false);
}());
