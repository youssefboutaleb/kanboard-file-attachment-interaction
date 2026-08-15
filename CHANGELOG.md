# Changelog

All notable changes to `kanboard-file-interaction-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned - v1.0.0 (Final Production Hardening)
- Production audit, final hardening & release v1.0.0.

---

## [0.9.0] - 2026-08-14

DOCX & PPTX Document Preview Engine.

### Added
- **High-Fidelity In-Browser Office Document & Presentation Preview Engines**:
  - `docx-preview.min.js` & `jszip.min.js`: Client-side high-fidelity Word document rendering preserving pagination, margins, typography, tables, drawings, and embedded images.
  - `pptx-viewer.umd.js`: Client-side high-fidelity PowerPoint presentation rendering preserving slide aspect ratios, background themes, shapes, typography, tables, and slide deck layout.
  - `Assets/js/office-viewer.js`: Automated controller initializing `.fic-docx-container` and `.fic-pptx-container` viewers, fetching binary payloads via stream routes, and providing slide navigation controls (Prev, Next, slide counter, tabs, keyboard shortcuts).
  - Stream route integration in `FileStreamController` with `docx`, `dotx`, `pptx`, `potx` in `INLINE_MIME_TYPES` and `MAGIC_SIGNATURES`.
- **OpenXML Word Document (`.docx`, `.dotx`, `.doc`) Preview Engine**:
  - `DocxParserService`: Pure-PHP memory-safe OpenXML DOM parser extracting headings (H1-H4), formatted text runs (bold, italic, underline, strike, monospace code), bullet/numbered lists, and tables with pre-escaped HTML (`htmlspecialchars()`).
  - `DocxPreviewHandler`: Handler for Word documents returning structured HTML and metadata (`paragraphCount`, `headingCount`, `tableCount`, `wordCount`). Gracefully detects legacy `.doc` (OLE2 binary format) to emit safe download notices without memory bloat.
  - `Template/file/docx_preview.php`: Word Document reading pane with summary badges and clean typography.
- **OpenXML PowerPoint Presentation (`.pptx`, `.potx`, `.ppt`) Preview Engine**:
  - `PptxParserService`: Pure-PHP memory-safe presentation parser resolving slide ordering via `ppt/presentation.xml` & `ppt/_rels/presentation.xml.rels`, extracting slide titles, bullet points, text blocks, and structured slide tables.
  - `PptxPreviewHandler`: Handler for PowerPoint presentations returning slide deck arrays and metadata (`slideCount`, `title`). Gracefully detects legacy `.ppt` format.
  - `Template/file/pptx_preview.php`: Interactive presentation viewer with slide switcher tabs (`Slide 1`, `Slide 2`...), active slide canvas, and presentation title header.
- **Expanded Test Suite**:
  - 30 new unit and integration tests covering synthetic OpenXML ZIP document & presentation parsing, slide ordering, bullet point extraction, table extraction, legacy format notices, streaming MIME types, and template rendering (total 755 tests, 2562 assertions).

### Changed
- Updated `FileValidationService` to whitelist `docx`, `dotx`, `doc` (10 MB cap) and `pptx`, `potx`, `ppt` (15 MB cap) with strict MIME mappings.
- Updated `PreviewViewModeRegistry` with "Word Document" and "PowerPoint Presentation" labels.
- Updated `FilePreviewController` to register `DocxPreviewHandler` and `PptxPreviewHandler` and route to `docx_preview` and `pptx_preview` templates.
- Updated `Template/file/dropdown.php` to include docx and pptx extensions in the Safe Preview menu.
- Bumped plugin version to `0.9.0` in `Plugin.php`.

## [0.7.1] - 2026-08-09

Unified UI action panel and a Rendered/Raw view-mode toggle.

> **Version note**: this release is numbered below the already-published 0.8.0, at
> explicit request. Its contents are additive UI work, so under SemVer they would sit
> at 0.8.1 or 0.9.0. Anyone reading the history top-down should treat 0.8.0 as the
> earlier tag and this as the later one.

### Added
- **Rendered / Raw view-mode toggle** for formats with a rich rendering — Markdown,
  code/HTML, CSV and Excel.
  - Rendered (ON) keeps the rich HTML, table or grid. Raw (OFF) shows the escaped
    source with syntax highlighting.
  - Switching is a server-side round trip on a new `view` parameter, validated
    against `PreviewViewModeRegistry`; anything unrecognised falls back to rendered
    and never reaches a branch.
  - The toggle links to the mode it would switch TO, so the server always renders the
    correct next target and the control keeps no client-side state.
  - Availability is keyed on the handler that *renders* the attachment, not the one
    currently in use — otherwise the control would disappear the moment it was used,
    since raw mode always resolves to the highlighter.
  - Asking for the raw view of a binary-backed format (an `.xlsx` is a ZIP) answers
    with the "Binary File (Preview not supported, click Download)" notice plus a
    **Render** button back to the rich view, rather than a screen of mojibake. No
    attachment bytes are emitted.
  - PDF, plain text and JSON deliberately offer no toggle: the first has no text
    source, the others are already source.

### Changed
- **Unified bottom action bar.** All five preview modals plus the editor and the
  binary notice now share one `.panel-meta` container (`Template/file/modal_actions.php`)
  carrying the friendly file type name on the left and View mode · Edit · Fullscreen ·
  Download on the right.
  - The top-right fullscreen button added in v0.8.0 is gone; Fullscreen is now a
    `fic-btn-fullscreen` link in the bar. It keeps a real `href` as the no-JavaScript
    fallback while `preview-controls.js` intercepts the click to toggle in place.
  - The Edit switcher label shortened from "Edit File" to "Edit". Its two gates are
    unchanged: editable format plus `hasProjectAccess('TaskFileController', 'remove')`.
  - PDF keeps one extra action the other modals cannot offer — "Open in new tab",
    which hands the inline stream to the browser's own full-window PDF viewer.
- **Internal technical labels removed from the UI** (requirement 2):
  - handler class names (`PdfPreviewHandler`, `CodePreviewHandler`, `TextPreviewHandler`, …)
    are replaced by friendly names such as "PDF Document", "CSV Table" and "Spreadsheet";
  - the inline language badge (`<span class="badge">BASH</span>`) is deleted — the
    language picker is now the only place the language is shown;
  - the security boilerplate lines ("Safe Read-Only Syntax Highlighted View", "Safe
    Sanitized Markdown View", "Safe Escaped Plain Text View", "Safe Read-Only CSV
    Table View", "Safe Read-Only Spreadsheet View") are gone.
- `Plugin.php` version set to `0.7.1`.

### Not implemented
- The requirement lists **Diagrams** among the rich formats needing a view toggle.
  This plugin has no diagram renderer, so there is nothing to toggle; the registry is
  the single place to add one when a diagram handler exists.

---

## [0.8.0] - 2026-08-09

Modal interaction enhancements. Both new controls live in one shared partial,
`Template/file/modal_actions.php`, rendered by all six modal templates.

### Added - Tasks 41 & 42
- **In-preview "Edit File" switcher.** Editable attachments now offer a switch straight from the preview
  modal into the live editor, without closing the modal.
  - Implemented with core's own `js-modal-medium` class, whose delegated handler already calls
    `KB.modal.replace()` — the seamless switch needs **no custom JavaScript**.
  - Gated on two things: the format must be in `FileEditValidationService::EDITABLE_EXTENSIONS` (`.txt`,
    `.json`, `.md`, `.markdown`, `.yaml`, `.yml`, `.sh`, `.py`, `.js`, `.css`, `.sql`), and the user must
    pass `hasProjectAccess('TaskFileController', 'remove')` — the same ACL entry the attachment dropdown
    already uses for its Edit item. Project-overview attachments are excluded, since the editor resolves
    files through `taskFileModel` only.
- **Fullscreen toggle (`⛶ Fullscreen`) on all six modals** — plain text, markdown/code, CSV, PDF, Excel and
  the editor. Toggles `.fic-modal-fullscreen` on Kanboard's `#modal-box`, expanding it to the full viewport
  with sticky modal, document and table headers.
  - Styles ship as `Assets/css/preview.css` registered on `template:layout:css`. The width and height rules
    need `!important` because core sets the modal box width as an **inline** style, which no selector can
    outrank.
- Both controls live in one shared partial, `Template/file/modal_actions.php`.

### Fixed - live editor client-side layer (found outside the requested scope)
- **The live editor's entire client-side layer never ran.** `Template/file/edit.php` shipped it as an inline
  `<script>`, which could not execute for two independent reasons — Kanboard's CSP refuses inline blocks,
  and modal content is injected via `element.innerHTML` (`assets/js/core/dom.js`), which never executes an
  injected script. This affected v0.5.0 through v0.7.0: the line and character counters never updated, the
  line gutter never tracked scrolling, JSON was never re-validated while typing, and the save fell back to
  a plain form POST that navigated the browser to the **raw JSON body** of the update response instead of
  staying in the modal.
  - Extracted to `Assets/js/editor.js` with delegated listeners (`scroll` uses the capture phase, since it
    does not bubble). Translated strings and the JSON-mode flag travel as `data-*` attributes, because a
    static asset cannot call `t()`.
  - The form now carries `js-modal-ignore-form`, so core's own modal submit handler does not also POST it
    and replace the modal with the JSON response body.

### Changed
- `Plugin.php` version bumped to `0.8.0`.

---

## [0.7.0] - 2026-08-09

Bug fixes and UI enhancements across the preview modals. Three defects in this release shared one root
cause: **Kanboard's CSP (`default-src 'self'`, no `script-src 'unsafe-inline'`) silently blocks inline
`<script>` blocks, and modal content injected via `innerHTML` never executes them either.** All
client-side behaviour now ships as registered assets on the `template:layout:js` hook.

### Fixed - Excel multi-sheet tab switching (Task 39)
- **Clicking a sheet tab now switches sheets.** The switcher shipped as an inline `<script>` inside
  `Template/file/excel_preview.php` and could never have run, for two independent reasons: CSP refuses
  inline blocks, and `assets/js/core/dom.js` injects modal content with `element.innerHTML = html`, which
  per the HTML spec never executes a `<script>` inserted that way. The logic moved into
  `Assets/js/preview-controls.js` behind a delegated `click` listener.
- Tabs and panels are now paired on the `data-sheet-index` **value** rather than DOM ordinal position, and
  switching is scoped to the clicked tab's own strip so two previews cannot drive each other.
- The active-sheet badge is updated with `textContent`, never `innerHTML`: sheet names arrive
  pre-escaped, so assigning them as markup would double-unescape them.

### Added - v0.7.0 (Task 38)
- **Dynamic delimiter selector and header toggle in the CSV preview modal.** Controls offer Auto-detect,
  Comma, Semicolon, Tab and Pipe, plus a "First row is header" checkbox that is checked by default.
  Changing either re-renders the table **without closing the modal**, via a server-side round trip on the
  new `delimiter` and `header` parameters handed to Kanboard's `KB.modal.replace()`.
  - `CsvDelimiterRegistry` transports delimiters as opaque **tokens, never literal characters**: a raw tab
    or pipe survives neither URL encoding nor attribute escaping reliably, and a raw value would flow
    straight into `str_getcsv()`. Unrecognised tokens collapse to auto-detection.
  - Auto-detect stays the selected option while it is the active mode, with the delimiter it resolved to
    reported separately ("Auto-detected: SEMICOLON") — so the control never silently jumps off
    Auto-detect with no way back.
  - With the header toggle off, no row is consumed: every line stays in the table body and the `<thead>`
    carries 1-based column indices, keeping the sticky header and row gutter aligned.
  - Cell escaping is unchanged and holds under every delimiter choice, so re-parsing cannot smuggle markup.
- `CsvPreviewHandler::preview()` accepts a `delimiterToken` option and reports `delimiterToken`,
  `delimiterMode` and `delimiterLabel` in its metadata alongside the existing `delimiter`.

### Changed - v0.7.0 (Task 38)
- `Assets/js/preview-language-selector.js` renamed to **`Assets/js/preview-controls.js`** and generalized:
  a single delegated `change` listener now drives the Task 36 language picker, the CSV delimiter picker and
  the CSV header toggle, rather than duplicating the modal-replace and navigation-fallback logic per control.

### Added - v0.7.0 (Task 38)
- **Dynamic delimiter selector and header toggle in the CSV preview modal.** Controls offer Auto-detect,
  Comma, Semicolon, Tab and Pipe, plus a "First row is header" checkbox that is checked by default.
  Changing either re-renders the table **without closing the modal**, via a server-side round trip on the
  new `delimiter` and `header` parameters handed to Kanboard's `KB.modal.replace()`.
  - `CsvDelimiterRegistry` transports delimiters as opaque **tokens, never literal characters**: a raw tab
    or pipe survives neither URL encoding nor attribute escaping reliably, and a raw value would flow
    straight into `str_getcsv()`. Unrecognised tokens collapse to auto-detection.
  - Auto-detect stays the selected option while it is the active mode, with the delimiter it resolved to
    reported separately ("Auto-detected: SEMICOLON") — so the control never silently jumps off
    Auto-detect with no way back.
  - With the header toggle off, no row is consumed: every line stays in the table body and the `<thead>`
    carries 1-based column indices, keeping the sticky header and row gutter aligned.
  - Cell escaping is unchanged and holds under every delimiter choice, so re-parsing cannot smuggle markup.
- `CsvPreviewHandler::preview()` accepts a `delimiterToken` option and reports `delimiterToken`,
  `delimiterMode` and `delimiterLabel` in its metadata alongside the existing `delimiter`.

### Changed - v0.7.0 (Task 38)
- `Assets/js/preview-language-selector.js` renamed to **`Assets/js/preview-controls.js`** and generalized:
  a single delegated `change` listener now drives the Task 36 language picker, the CSV delimiter picker and
  the CSV header toggle, rather than duplicating the modal-replace and navigation-fallback logic per control.

### Added - v0.7.0 (Task 36)
- **Dynamic syntax language selector in the Safe Preview modal header.** A picker offers JSON, YAML, Bash,
  Python, SQL, PHP, CSS, JavaScript, XML/HTML, Config and Plain Text, opening on the language implied by the
  file extension (`.json` → JSON, `.yml`/`.yaml` → YAML, `.sh` → Bash, `.py` → Python, `.env`/`.ini`/`.conf`
  → Config, `.txt`/`.log` → Plain Text) and switching highlighting on the fly.
  - Switching is a **server-side round trip** on a new `lang` parameter, handed to Kanboard's own
    `KB.modal.replace()`. Highlighting stays in PHP where the payload is already entity-escaped, rather than
    duplicating an XSS-sensitive tokenizer in JavaScript.
  - `SyntaxLanguageRegistry` now owns the option list, per-extension defaults, and **per-language comment
    prefixes and keyword sets** — so `#` is a comment in Bash but not in JSON, and `--` is one in SQL.
  - The `lang` parameter is validated against the registry; anything unrecognised falls back to the
    extension default and never reaches the highlighter.
  - Selecting "Plain Text" drops highlighting entirely and routes to the escaped text view.
  - Ships as `Assets/js/preview-controls.js` (shared with the Task 38 CSV controls) registered on `template:layout:js`, since CSP
    (`default-src 'self'`, no `script-src 'unsafe-inline'`) blocks inline handlers.
- **Attachments with unknown or missing extensions can now be previewed.** `BinaryContentDetector` inspects
  a bounded 8 KB window for NUL bytes, a control-character ratio above 10%, and invalid UTF-8:
  - printable text renders in the escaped text view with the language picker and a "Detected Text" badge;
  - binary content renders `Template/file/binary_notice.php` — "Binary File (Preview not supported, click
    Download)" with a download action and **none of the file content**.
  - Image, audio and video formats (`FileValidationService::CORE_MEDIA_EXTENSIONS`) are deliberately
    excluded: Kanboard core already previews them, and it keeps active content such as SVG out of every
    preview path. See `docs/SECURITY.md` §8.
  - `FilePreviewController::CONTENT_READ_CEILING_BYTES` refuses to buffer an attachment whose declared size
    exceeds 10 MB, closing a memory exposure that widening the preview surface would otherwise have opened.

### Changed - v0.7.0 (Task 36)
- Non-whitelisted extensions such as `.zip`, `.docx` and `.exe` no longer produce an "extension is not
  allowed" error modal; they answer with the binary download notice instead. No handler runs and no bytes
  are rendered, so the security boundary is unchanged.
- `CodePreviewHandler::preview()` accepts a `language` option that outranks the file extension.
  `metadata['language']` keeps its previous meaning (the raw token); the canonical registry id and label
  travel as `metadata['languageId']` and `metadata['languageLabel']`.

### Added - v0.7.0 (Task 36)
- **Dynamic syntax language selector in the Safe Preview modal header.** A picker offers JSON, YAML, Bash,
  Python, SQL, PHP, CSS, JavaScript, XML/HTML, Config and Plain Text, opening on the language implied by the
  file extension (`.json` → JSON, `.yml`/`.yaml` → YAML, `.sh` → Bash, `.py` → Python, `.env`/`.ini`/`.conf`
  → Config, `.txt`/`.log` → Plain Text) and switching highlighting on the fly.
  - Switching is a **server-side round trip** on a new `lang` parameter, handed to Kanboard's own
    `KB.modal.replace()`. Highlighting stays in PHP where the payload is already entity-escaped, rather than
    duplicating an XSS-sensitive tokenizer in JavaScript.
  - `SyntaxLanguageRegistry` now owns the option list, per-extension defaults, and **per-language comment
    prefixes and keyword sets** — so `#` is a comment in Bash but not in JSON, and `--` is one in SQL.
  - The `lang` parameter is validated against the registry; anything unrecognised falls back to the
    extension default and never reaches the highlighter.
  - Selecting "Plain Text" drops highlighting entirely and routes to the escaped text view.
  - Ships as `Assets/js/preview-language-selector.js` registered on `template:layout:js`, since CSP
    (`default-src 'self'`, no `script-src 'unsafe-inline'`) blocks inline handlers.
- **Attachments with unknown or missing extensions can now be previewed.** `BinaryContentDetector` inspects
  a bounded 8 KB window for NUL bytes, a control-character ratio above 10%, and invalid UTF-8:
  - printable text renders in the escaped text view with the language picker and a "Detected Text" badge;
  - binary content renders `Template/file/binary_notice.php` — "Binary File (Preview not supported, click
    Download)" with a download action and **none of the file content**.
  - Image, audio and video formats (`FileValidationService::CORE_MEDIA_EXTENSIONS`) are deliberately
    excluded: Kanboard core already previews them, and it keeps active content such as SVG out of every
    preview path. See `docs/SECURITY.md` §8.
  - `FilePreviewController::CONTENT_READ_CEILING_BYTES` refuses to buffer an attachment whose declared size
    exceeds 10 MB, closing a memory exposure that widening the preview surface would otherwise have opened.

### Changed - v0.7.0 (Task 36)
- Non-whitelisted extensions such as `.zip`, `.docx` and `.exe` no longer produce an "extension is not
  allowed" error modal; they answer with the binary download notice instead. No handler runs and no bytes
  are rendered, so the security boundary is unchanged.
- `CodePreviewHandler::preview()` accepts a `language` option that outranks the file extension.
  `metadata['language']` keeps its previous meaning (the raw token); the canonical registry id and label
  travel as `metadata['languageId']` and `metadata['languageLabel']`.

### Fixed - v0.7.0 (Task 35)
- **PDF documents now render inline in the modal instead of showing the "Inline PDF viewing is not supported" fallback banner.**
  The `<object data>` URL was already pointing at core's inline `FileViewerController::browser` action, which returns the
  correct `Content-Type: application/pdf`. The actual blocker was a response header: `BootstrapMiddleware::sendHeaders()`
  stamps `X-Frame-Options: DENY` on *every* core response (`ENABLE_XFRAME` defaults to `true`), and browsers render an
  embedded PDF in a nested browsing context, so `DENY` aborted it and `<object>` fell through to its child content.
  - Added `FileStreamController::inline` plus the route `/b/:project_id/task/:task_id/file/:file_id/stream`, which serves the
    bytes with `Content-Security-Policy: default-src 'none'; frame-ancestors 'self'` in place of `X-Frame-Options`, keeping
    embedding restricted to this origin. See `docs/SECURITY.md` §5.
  - Streaming is allow-listed to `pdf` only and requires a matching `%PDF` magic signature, so active content
    (`.html`, `.svg`, …) can never be served inline from our own origin (`docs/SECURITY.md` §6).
  - `Cache-Control: private` replaces core's `public` for ACL-protected attachment bytes.
- **The modal's fullscreen control no longer triggers a file download.** The combined
  "Open Fullscreen / Download" link was bound to the `download` action, which sends
  `Content-Disposition: attachment`. It is now two distinct actions: **Fullscreen** (inline stream) and **Download**.
- **Removed the redundant orphan "View file" entry from the attachment dropdown.**
  Core renders its own view action *before* the `template:task-file:documents:dropdown` hook fires, inside the same `<ul>`,
  so it cannot be suppressed server-side without patching core. `Assets/js/dropdown-cleanup.js` (registered on
  `template:layout:js`, since CSP blocks inline scripts) prunes it in the DOM, scoped to the `fic-safe-preview` marker the
  dropdown template emits — so core keeps its view action for `mp3`/`mp4`/`svg`/`webm`/`mov` attachments that Safe Preview
  does not handle.
- `scripts/agent-verify.sh` now passes `--memory-limit` to PHPStan; `php:8.1-cli` caps at 128 M, which the grown `src/`
  exceeded, reported only as an opaque `Child process error (exit code 255)`.

### Changed
- `Plugin.php` version bumped to `0.7.0`.

---

## [0.6.0] - 2026-08-08

### Added
- **Excel Spreadsheet Interactive Preview Engine**:
  - `ExcelParserService`: Memory-safe `.xlsx` OpenXML parser extracting sheet names (`xl/workbook.xml`), shared string lookup tables (`xl/sharedStrings.xml`), and row/column data matrices capped at 100 rows x 50 columns.
  - `ExcelPreviewHandler`: Handler supporting `.xlsx` and `.xls` attachments, returning multi-sheet workbook structure and metadata.
  - `Template/file/excel_preview.php`: Multi-sheet tabbed spreadsheet modal view with worksheet navigation tabs (`Sheet1`, `Sheet2`), A/B/C column headers, row index gutter, cell HTML entity escaping, and legacy format / truncation banners.
  - Added `'xlsx'` and `'xls'` extensions to attachment dropdown whitelist (`Template/file/dropdown.php`) with 5 MB file size caps in `FileValidationService`.
- **Expanded Test Suite**:
  - 39 new unit & integration tests covering OpenXML spreadsheet parsing, multi-sheet tab rendering, legacy `.xls` notification, XSS cell escaping, and 5 MB size cap enforcement (total 279 tests, 925 assertions).

### Fixed
- Registered `ExcelPreviewHandler` in `FilePreviewController` handler registry (7-handler registry order).

### Changed
- Updated `Plugin.php` version to `0.6.0`.

---

## [0.5.0] - 2026-08-08

### Added
- **Safe In-App Text & JSON Live Editor**:
  - `FileEditController`: Live editor controller handling `edit()` modal rendering and `update()` POST save actions.
  - `FileEditValidationService`: Pre-save validation engine checking payload size bounds (500 KB limit) and JSON syntax error line detection (`json_decode()` error reporting).
  - `FileVersionService`: Attachment revision engine supporting overwrite updates and versioned revision file creation (`filename_v2.ext`).
  - `Template/file/edit.php`: Interactive editor modal view with syntax status indicators, line-number gutter, live character counters, and save mode selection (overwrite vs revision).
  - "Edit File" action link added to file attachment dropdown (`Template/file/dropdown.php`) for text/JSON/Markdown attachments when user has write access.
- **Expanded Test Suite**:
  - 54 new unit & integration tests covering pre-save validation, JSON syntax error estimation, version filename generation, edit modal rendering, and write ACL enforcement (total 240 tests, 789 assertions).

### Changed
- Updated `Plugin.php` version to `0.5.0` and registered `/b/:project_id/task/:task_id/file/:file_id/edit` and `update` routes.

---

## [0.4.0] - 2026-08-08

### Added
- **PDF Embedded Read-Only Viewer Engine**:
  - `PdfPreviewHandler`: Binary-safe handler for `.pdf` attachments and `application/pdf` / `application/x-pdf` MIME types. The payload is never parsed, decoded, or executed — only size metadata is emitted.
  - `Template/file/pdf_preview.php`: Embedded modal viewer streaming the document into an `<object type="application/pdf">` container through Kanboard core's inline `FileViewerController::browser` action.
  - Graceful fallback banner with a secure download link (`rel="noopener noreferrer"`) for browsers without an inline PDF renderer.
  - Dedicated 10 MB size ceiling for PDF attachments.
- **Per-Extension Size Cap Mechanism**:
  - `FileValidationService::EXTENSION_MAX_SIZE_BYTES` overrides the global 500 KB default on a per-format basis; `validateFileSize()` accepts an optional `$extension` argument.
  - `getMaxSizeForExtension()` accessor, with constructor-injectable caps for testing.
- **Test Suite Expansion**:
  - 44 new unit & integration tests covering PDF handler resolution, registration precedence, 10 MB boundary enforcement, MIME spoofing rejection, modal template dispatching, inline-vs-download URL targeting, and filename escaping (total 186 tests, 582 assertions).

### Changed
- Updated `FileValidationService` to whitelist `pdf`. `MIME_MAP['pdf']` accepts `application/pdf`, `application/x-pdf`, and `application/octet-stream`, and deliberately rejects `text/*` — a PDF announcing itself as renderable text is treated as a spoofing attempt.
- Updated `FilePreviewController` to register 6 format handlers with `PdfPreviewHandler` FIRST, so binary payloads never fall through to the `TextPreviewHandler` `text/*` catch-all, and to dispatch `FileInteractionCore:file/pdf_preview`.
- Updated `Template/file/dropdown.php` to expose "Safe Preview" for `.pdf` attachments.
- Bumped plugin version to `0.4.0` in `Plugin.php` and `composer.json`.

### Fixed
- PDF viewer `<object>` container now targets the inline `browser` action instead of `download`. The `download` action sets `Content-Disposition: attachment`, which made browsers open a save dialog instead of rendering the document inside the modal.

### Build & CI
- Removed the top-level `version` field from `composer.json`. It made `composer validate --strict` exit non-zero, failing the GitHub Actions CI job. `Plugin.php::getPluginVersion()` is now the single source of truth, and `scripts/package-plugin.sh` reads the archive version from it.
- `dist/` is now git-ignored. Release archives are published as GitHub Release assets by the new `.github/workflows/release.yml`, which fires on `v*` tag pushes, verifies the tag matches `Plugin.php`, and uses the matching `CHANGELOG.md` section as release notes.

### Known Limitations
- Spec 004 AC-3 specifies a `sandbox` attribute, which the HTML `<object>` element does not support (it is an `<iframe>`-only attribute). Script containment currently relies on the browser's built-in PDF viewer. Migrating the container to a sandboxed `<iframe>` is tracked as a follow-up.

---

## [0.3.0] - 2026-08-08

### Added
- **Safe Markdown HTML Rendering Engine**:
  - `MarkdownParserService` & `MarkdownPreviewHandler` supporting `.md` and `.markdown` attachments.
  - Converts Markdown headers (`#`), lists (`-`/`1.`), blockquotes (`>`), bold/italic (`**`/`*`), code fences (```), and links into safe HTML.
  - Strict XSS script tag entity escaping (`<script>`, `<iframe>`, `<img onerror=...>`) and link protocol sanitization (`javascript:` -> `#`).
- **Code Syntax Highlighting Engine**:
  - `CodePreviewHandler` supporting `.json`, `.yml`, `.yaml`, `.xml`, `.html`, `.sh`, `.py`, `.php`, `.js`, `.css`, `.sql`.
  - Tokenized syntax highlighting for keywords, strings, comments, numbers, and functions with XSS entity escaping.
- **Rich HTML Preview Template**:
  - `Template/file/markdown_preview.php`: Shared rich modal view for Markdown & Code previews.
- **Expanded Test Suite**:
  - 70 new unit & integration tests covering Markdown parsing, link sanitization, syntax highlighting, options forwarding, and template dispatching (total 142 tests, 460 assertions).

### Changed
- Updated `FileValidationService` to whitelist `.markdown`, `.sh`, `.py`, `.php`, `.js`, `.css`, `.sql` extensions and MIME maps.
- Updated `FileInteractionManager` & `FilePreviewController` to register 5 format handlers (`MarkdownPreviewHandler`, `CsvPreviewHandler`, `CodePreviewHandler`, `JsonPreviewHandler`, `TextPreviewHandler`).

---

## [0.2.0] - 2026-08-08

### Added
- **CSV & TSV Read-Only Table Preview Engine**:
  - `CsvPreviewHandler`: Format handler supporting `.csv` and `.tsv` attachments.
  - `CsvParserService`: Memory-safe streaming parser supporting comma (`,`), semicolon (`;`), tab (`\t`), and pipe (`|`) field delimiters.
  - Automatic delimiter detection based on frequency scoring across head lines.
  - HTML entity escaping (`htmlspecialchars()`) applied to all CSV cell values to prevent XSS.
  - Preview bounds capping output to first 100 rows and 50 columns to guarantee fast rendering under 8MB RAM.
  - `Template/file/csv_preview.php`: Responsive modal table template view with styled headers, alternating row colors, row index column, delimiter badge, and truncation warning banners.
- **Test Suite Expansion**:
  - 18 new unit & integration tests covering CSV delimiter detection, table rendering, truncation limits, cell XSS escaping, and DIC container resolution (total 72 tests, 240 assertions).

### Changed
- Updated `FileValidationService` to whitelist `csv` and `tsv` extensions and MIME types (`text/csv`, `text/tab-separated-values`, `application/vnd.ms-excel`).
- Updated `FileInteractionManager` to support name-based forced format resolution.
- Updated `FilePreviewController` to route CSV previews to `FileInteractionCore:file/csv_preview`.

---

## [0.1.0] - 2026-08-07

### Added
- **Core Strategy & Registry Architecture**:
  - `FileHandlerInterface` contract for file preview handlers.
  - `PreviewResult` immutable value object for preview output and metadata.
  - `FileInteractionManager` central registry for format handler resolution and forced format overrides.
- **Format Handlers**:
  - `TextPreviewHandler`: Safe read-only preview for `.txt`, `.md`, `.env`, `.ini`, `.conf`, `.yaml`, `.yml`, `.xml`, `.log`, `.html`, `.htm`.
  - `JsonPreviewHandler`: Safe JSON validation, 2-space pretty printing, recursion depth limit (500 KB / 512 depth), and friendly invalid JSON error reporting.
- **Security & Validation Services**:
  - `FileValidationService`: Strict extension whitelisting, `basename()` path traversal protection, null-byte rejection, 500 KB file size limit enforcement, and MIME type validation.
  - `PermissionService` & `PermissionCheckerInterface`: ACL authorization abstraction for project, task, and file read access.
  - `MockPermissionChecker`: In-memory permission checker for decoupled unit testing.
- **UI Integration & Controllers**:
  - `FilePreviewController`: Handles preview route requests `/b/:project_id/task/:task_id/file/:file_id/preview`.
  - `Template/file/dropdown.php`: Injects "Safe Preview" modal action link into task attachment dropdown menus.
  - `Template/file/preview.php`: Renders modal preview dialog displaying filename, handler badge, line/char counts, and HTML-escaped content.
- **Agentic Infrastructure & Quality Gates**:
  - `scripts/agent-verify.sh`: Verification script running PHP syntax check, composer validation, PHPStan Level 8 static analysis, and PHPUnit unit tests (with automatic Docker fallback).
  - `.githooks/pre-commit` & `.githooks/pre-push`: Automated git pre-commit and pre-push security hooks.
  - `docker-compose.yml`: Live Kanboard testing environment mapped to port `8085`.
  - `scripts/package-plugin.sh`: Packaging script for building release ZIP archives (`dist/FileInteractionCore-0.1.0.zip`).
- **Comprehensive Documentation**:
  - `AGENTS.md`, `CLAUDE.md`, `SECURITY.md`, `ARCHITECTURE.md`, `ROADMAP.md`, `docs/specs/001-safe-text-preview.md`, and `docs/MANUAL_TESTING.md`.

