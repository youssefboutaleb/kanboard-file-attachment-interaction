/**
 * Orphan "View file" dropdown cleanup (Task 35).
 *
 * Kanboard core renders its own view action into the attachment dropdown BEFORE
 * the `template:task-file:documents:dropdown` hook fires, so a plugin cannot
 * suppress that <li> server-side without patching core. It is removed here
 * instead — from a registered asset file rather than an inline <script>, because
 * Kanboard's CSP is `default-src 'self'` with no `script-src 'unsafe-inline'`.
 *
 * Scoping matters: core also offers "View file" for audio, video and svg
 * attachments that Safe Preview does NOT handle, and those entries must survive.
 * The gate is therefore the marker <li> that Template/file/dropdown.php emits
 * only for formats this plugin claims — cleanup is confined to that marker's own
 * dropdown list.
 */
(function () {
    'use strict';

    var MARKER_SELECTOR = 'li.fic-safe-preview[data-fic-ext]';
    var PROCESSED_FLAG = 'ficDropdownCleaned';

    /**
     * True when a href points at one of core's un-sandboxed view actions.
     *
     * Matches both the popover variant (`action=show`) and the raw inline
     * variant (`action=browser`), while leaving `download` — and every non-core
     * controller — untouched.
     */
    function isCoreViewAction(href) {
        if (!href) {
            return false;
        }

        // getAttribute() returns entity-decoded text in the DOM, but normalise
        // anyway so the same predicate holds for raw server-rendered markup.
        var url = href.replace(/&amp;/g, '&');

        if (url.indexOf('controller=FileViewerController') === -1) {
            return false;
        }

        return /[?&]action=(show|browser)(&|$)/.test(url);
    }

    /**
     * Remove core's view entries from the dropdown owning the given marker.
     */
    function cleanDropdown(marker) {
        var list = marker.closest('ul');

        if (!list || list[PROCESSED_FLAG]) {
            return;
        }

        list[PROCESSED_FLAG] = true;

        Array.prototype.forEach.call(list.children, function (entry) {
            if (entry === marker || entry.tagName !== 'LI') {
                return;
            }

            var link = entry.querySelector('a[href]');

            if (link && isCoreViewAction(link.getAttribute('href'))) {
                entry.remove();
            }
        });
    }

    function run(root) {
        var scope = root && root.querySelectorAll ? root : document;

        Array.prototype.forEach.call(scope.querySelectorAll(MARKER_SELECTOR), cleanDropdown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            run(document);
        });
    } else {
        run(document);
    }

    // Kanboard re-renders the attachment table over ajax after an upload or a
    // removal, so the freshly injected dropdowns need cleaning too.
    if (typeof window.MutationObserver === 'function') {
        new window.MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length > 0) {
                    run(document);
                    return;
                }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
}());
