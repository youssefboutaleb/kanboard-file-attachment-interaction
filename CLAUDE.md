# CLAUDE.md - Claude Agentic Setup & Development Guide

This document defines the agentic workflow, automated quality gates, git hooks, and verification standards for Claude Code and AI coding assistants.

> ⚠️ **MANDATORY RULE**: This `CLAUDE.md` file MUST be updated at the end of **EVERY** completed task/step to reflect current milestone status, implemented handlers, and test execution details.

---

## 🚦 Milestone Task Progress Status

### Milestone 1: Safe Text/JSON Preview (100% RELEASED - v0.1.0 / v0.1.1 Hotfix)
- [x] **Tasks 1 - 10 Complete**

### Milestone 2: Safe CSV Read-Only Table Preview (100% RELEASED - v0.2.0)
- [x] **Tasks 11 - 15 Complete**

### Milestone 3: Safe Markdown HTML Rendering & Code Syntax Highlighting (100% RELEASED - v0.3.0)
- [x] **Tasks 16 - 20 Complete**

### Milestone 4: PDF Embedded Read-Only Viewer (100% RELEASED - v0.4.0)
- [x] **Tasks 21 - 24 Complete**

### Milestone 5: Safe In-App Text & JSON Live Editor with Versioning (100% RELEASED - v0.5.0)
- [x] **Tasks 25 - 29 Complete**

### Milestone 6: Excel Spreadsheet Interactive Preview Engine (100% RELEASED - v0.6.0)
- [x] **Tasks 30 - 34 Complete**

### Milestone 7: Bug Fixes & UI Enhancements (100% RELEASED - v0.7.0)

> **Numbering note**: the requested task numbers ran one behind the roadmap from Task 36 onward, because
> Task 35 fixed both the orphan dropdown action *and* the PDF stream routing that roadmap Task 37 covered.
> Roadmap numbering is authoritative below; the number each was requested under is noted in brackets.

- [x] **Task 35: PDF Stream Routing Fix & Orphan View Action Cleanup** (also closes Task 37)
  - Root cause of the inline fallback banner was **not** the stream URL: core's `browser` action already returns `Content-Type: application/pdf`, but `BootstrapMiddleware` stamps `X-Frame-Options: DENY` on every response, which blocks `<object>` from rendering the document.
  - New `FileStreamController::inline` serves the bytes with `frame-ancestors 'self'` in place of that header. Route: `/b/:project_id/task/:task_id/file/:file_id/stream`.
  - Fullscreen control split away from the download action.
  - Orphan core "View file" entry pruned by `Assets/js/dropdown-cleanup.js`, gated on the `fic-safe-preview` marker so core-only media formats keep their action.
- [x] **Task 36: Dynamic Language Selector & Unknown Extension Handling**
  - `SyntaxLanguageRegistry`: single source of truth for picker options, per-extension defaults, and per-language comment prefixes + keyword sets.
  - Language switching is a **server-side round trip** via the `lang` parameter and `KB.modal.replace()` — highlighting stays in PHP where the payload is already escaped, rather than duplicating the tokenizer in JS.
  - `BinaryContentDetector`: bounded (8 KB) NUL-byte / control-ratio / UTF-8 sniffing for attachments the extension whitelist cannot classify.
  - Unknown & missing extensions now reach Safe Preview: text gets an escaped view with the picker, binary gets `Template/file/binary_notice.php` and renders **no** file content.
  - Core-owned media (`FileValidationService::CORE_MEDIA_EXTENSIONS`) stays excluded — core owns those viewers, and it keeps SVG out of every preview path.
- [x] **Task 37: PDF Stream Routing & Fullscreen Download Redirect Fix** — closed by Task 35 above; no separate work remained.
- [x] **Task 38: Dynamic CSV Delimiter Selector & Header Toggle** [requested as 37]
  - `CsvDelimiterRegistry`: token-based delimiter allow-list (`auto`, `comma`, `semicolon`, `tab`, `pipe`) — delimiters travel as **tokens, never raw characters**, so nothing arbitrary reaches `str_getcsv()`.
  - `Template/file/csv_controls.php`: delimiter `<select>` + "First row is header" checkbox (checked by default), each carrying the fully-built preview URL for the state it selects.
  - Re-render is a server-side round trip via `delimiter` / `header` params through `KB.modal.replace()` — the modal never closes.
  - Header off keeps every row as data and swaps the `<thead>` for 1-based column indices so the sticky gutter stays aligned.
  - `Assets/js/preview-language-selector.js` renamed to `Assets/js/preview-controls.js` and generalized: one delegated `change` handler now drives both the Task 36 language picker and these CSV controls.
- [x] **Task 39: Excel Multi-Sheet Tab Event Handler Fix** [requested as 38]
  - The switcher was an inline `<script>` in `excel_preview.php` — dead code twice over: CSP refuses inline blocks, **and** `assets/js/core/dom.js:82` injects modal content via `element.innerHTML`, which never executes injected `<script>` tags.
  - Logic moved into `Assets/js/preview-controls.js` with a delegated `click` listener; tabs and panels now pair on the `data-sheet-index` **value**, not DOM ordinal, and switching is scoped to the clicked tab's own strip.
- [x] **Task 40: Verification, Packaging & Release v0.7.0** [requested as 38]
  - `Plugin.php` → `0.7.0`; `dist/FileInteractionCore-0.7.0.zip` built.
  - Released with 562 tests / 1816 assertions, PHPStan level 8 clean.

### Milestone 8: Modal Enhancements (100% RELEASED - v0.8.0)
- [x] **Task 41: In-Preview Quick Edit Switcher Button** [requested as 40]
  - `Template/file/modal_actions.php` is a single shared partial rendered by all six modal templates.
  - The switcher is a plain `js-modal-medium` link — **core's own delegated handler calls `KB.modal.replace()`**, so the seamless switch needs zero custom JavaScript.
  - Gated on `FileEditValidationService::EDITABLE_EXTENSIONS` (from the controller) **and** `hasProjectAccess('TaskFileController', 'remove')` in the template, matching the dropdown's existing gate. Project attachments are excluded — the editor resolves via `taskFileModel` only.
- [x] **Task 42: Modal Fullscreen Mode (`⛶ Fullscreen`) Toggle** [requested as 40]
  - Toggles `.fic-modal-fullscreen` on Kanboard's `#modal-box`; styles in `Assets/css/preview.css`, registered on `template:layout:css`.
  - `!important` is unavoidable: core sets the box width as an **inline** style, which beats any selector.
- [x] **Bonus fix (out of requested scope): the live editor's client-side layer was dead code.**
  - `Template/file/edit.php` shipped its whole behaviour as an inline `<script>` — counters, gutter sync, JSON validation and the fetch-based save — none of which ran in v0.5.0-v0.7.0 (lesson 17 below). Extracted to `Assets/js/editor.js` with delegated listeners; translated strings now travel as `data-*` attributes.
  - The form gained `js-modal-ignore-form` so core's modal submit handler does not POST it and replace the modal with the raw JSON response.
- [x] **Task 43: Verification, Packaging & Release v0.8.0** [requested as 41]
  - `Plugin.php` → `0.8.0`; `dist/FileInteractionCore-0.8.0.zip` built.
  - Released with 617 tests / 1967 assertions, PHPStan level 8 clean.

### Milestone 8b: Unified UI Action Panel & View Mode Toggle (100% RELEASED - v0.7.1)

> **Version note**: released as `0.7.1` at explicit request, *after* `0.8.0`. Under SemVer this additive UI work
> would sit at 0.8.1 or 0.9.0 — the number is lower than its predecessor, so treat 0.8.0 as the earlier tag.

- [x] **Task 44: Unified UI Action Panel & Rendered/Raw View Mode Toggle** [requested as 40]
  - `Template/file/modal_actions.php` became the single bottom `.panel-meta` bar for all preview modals plus the editor and the binary notice: friendly type name on the left, View mode / Edit / Open in new tab / Fullscreen / Download on the right.
  - **In-Modal View Mode Toggle Fix**: Added `js-modal-medium` class and delegated `click` listener in `Assets/js/preview-controls.js` to execute `KB.modal.replace()` so toggling between Rendered and Raw views replaces modal content in place without navigating away or opening a new tab.
  - Standardized "Open in new tab" (`<i class="fa fa-external-link"></i>`) across all formats:
    - **PDF**: routes to inline stream `/b/:project_id/task/:task_id/file/:file_id/stream`.
    - **XLSX / CSV / Text / Code / HTML / MD**: routes to standalone preview `/b/:project_id/task/:task_id/file/:file_id/preview`.
  - When opened in a new tab (standalone mode), `FilePreviewController` and `FileEditController` render with the full Kanboard application layout (`$this->helper->layout->app(...)`), including `preview.css`, `preview-controls.js`, and FontAwesome, while omitting in-modal controls (`Fullscreen`, `Open in new tab`).
  - **Standalone XLSX Sheet Tab Switching Fix**: Wrapped sheets in `.fic-sheet-container` in `Template/file/excel_preview.php` and updated `onSheetTabClick` in `Assets/js/preview-controls.js` to properly scope panel switching across both modal and standalone views.
  - **Complete Edit / Save / Cancel System Overhaul**:
    - Fixed `FileEditController::update()` parameter retrieval to correctly read POST body values (`$this->request->getValue('content')`, `grid_data`, `mode`, `csrf_token`) with query param fallback.
    - Added proper CSRF token validation on POST form submissions via `$this->token->validateCSRFToken(...)`.
    - Fixed Cancel button across modals and standalone editor (`class="js-modal-close btn btn-link fic-edit-cancel"` invoking `KB.modal.close()` or `history.back()`).
  - **Interactive In-Browser Spreadsheet Grid Editor (XLSX & CSV)**:
    - Interactive grid table in `Template/file/edit.php` featuring formula bar with active cell indicator (`A1`), cell input field, "Add Row", "Add Column", "Delete Row", "Delete Column", and sheet tabs.
    - Client-side spreadsheet state engine in `Assets/js/editor.js` with keyboard navigation (Enter, Tab, Arrow keys), inline editing, and bidirectional synchronization with multi-sheet workbook JSON (`grid_data`) and CSV text (`content`).
    - Backend `ExcelWriterService` (`src/Service/ExcelWriterService.php`) supports multi-sheet OpenXML `.xlsx` binary packaging (`buildXlsxFromMultiSheet`) and CSV formatting.
    - **Standalone Layout & Script Loading Fix**:
      - Root cause: `\Kanboard\Core\Helper` resolves helpers via `__get()` without implementing `__isset()`. Therefore `isset($this->helper->layout)` evaluated to `false` in PHP, which prevented standalone mode from wrapping views in `$this->helper->layout->app(...)`.
      - Fixed `FileEditController` and `FilePreviewController` to use `is_object($this->helper->layout ?? null)` and `method_exists($layout, 'app')`. Standalone view now loads full assets (`preview.css`, `editor.js`, `preview-controls.js`).
    - **CSV Single-Sheet vs XLSX Multi-Sheet Rules**:
      - CSV / TSV files hide `+ Sheet` and sheet tabs since CSV is flat single-table data.
      - XLSX files feature full sheet lifecycle management: **Add Sheet** (`+ Sheet`), **Rename Sheet** (pencil icon / prompt), and **Delete Sheet** (`×` button on tabs with confirmation).
    - **Non-AJAX Save Fallback**:
      - `FileEditController::update()` detects non-AJAX browser submissions, setting a flash success message and issuing an HTTP 302 redirect back to the preview route instead of outputting raw JSON.
  - **HTML (`.html`, `.htm`)**: Introduced `HtmlPreviewHandler` and `Template/file/html_preview.php` rendering via `<iframe sandbox="" ... srcdoc="...">` for strict security isolation (Rule 4). Supported with Raw view mode toggle (`view=raw`) and Live Editor plain-text editing.
- [x] **Task 45: Release v0.7.1** [requested as 40]
  - `Plugin.php` → `0.7.1`; `dist/FileInteractionCore-0.7.1.zip` built. 725 tests / 2427 assertions, PHPStan level 8 clean.

### Milestone 9: DOCX & PPTX Document Preview Engine (100% RELEASED - v0.9.0)
- [x] **Task 44: OpenXML Word (`.docx`, `.dotx`, `.doc`) Text & Table Parser & Handler**
  - Pure-PHP memory-safe OpenXML DOM parser (`DocxParserService.php`) extracting headings (H1-H4), formatted text runs (bold, italic, underline, strike, code), bullet/numbered lists, and tables with pre-escaped HTML (`htmlspecialchars()`).
  - `DocxPreviewHandler` supports `.docx`, `.dotx`, and detects legacy `.doc` (OLE2 binary format) to present safe download notices without memory bloat.
  - `Template/file/docx_preview.php`: Word Document reading pane with word count, paragraph, and table summary badges.
- [x] **Task 45: OpenXML PowerPoint (`.pptx`, `.potx`, `.ppt`) Slide Deck Parser & Handler**
  - Pure-PHP memory-safe presentation parser (`PptxParserService.php`) resolving slide ordering via `ppt/presentation.xml` & `ppt/_rels/presentation.xml.rels`, extracting slide titles, bullet points, text blocks, and structured slide tables.
  - `PptxPreviewHandler` supports `.pptx`, `.potx`, and detects legacy `.ppt` formats.
  - `Template/file/pptx_preview.php`: Interactive presentation viewer with slide switcher tabs (`Slide 1`, `Slide 2`...), active slide canvas, and presentation title header.
  - Delegated slide switching event handling in `Assets/js/preview-controls.js`.
- [x] **Task 46: High-Fidelity In-Browser Rendering Engines (docx-preview & pptx-viewer)**
  - Client-side high-fidelity rendering for Word (`.docx`) documents utilizing `docx-preview.min.js` and `jszip.min.js` in `Assets/js/vendor/` with complete typography, pagination, margins, tables, drawings, and embedded images.
  - Client-side high-fidelity rendering for PowerPoint (`.pptx`) presentations utilizing `pptx-viewer.umd.js` with full slide deck presentation canvas, shapes, theme colors, typography, tables, and slide navigation controls (Prev, Next, slide counter, tabs, and keyboard shortcuts).
  - Stream routing integration in `FileStreamController` with `docx`, `dotx`, `pptx`, `potx` added to `INLINE_MIME_TYPES` and `MAGIC_SIGNATURES`.
  - Registered assets and automated controller `Assets/js/office-viewer.js` with MutationObserver support.
- [x] **Task 47: Verification, Packaging & Release v0.9.0**
  - `Plugin.php` bumped to `0.9.0`; `dist/FileInteractionCore-0.9.0.zip` built.
  - 755 tests / 2562 assertions passing, PHPStan level 8 clean.

### Milestone 10: Final Production Hardening & Release (PLANNED - v1.0.0)
- [ ] **Task 48: Production Audit, Final Hardening & Release v1.0.0**

---

## 🛠️ Essential Commands & Agentic Scripts

```bash
# Automated Agent Verification Pipeline (PHP Syntax, Composer, PHPStan Level 8, 755 Tests Passing)
bash scripts/agent-verify.sh
# or via composer:
composer agent-verify

# Test Execution via Docker (PHP 8.1 container - 755 Tests Passing)
docker run --rm -v $(pwd):/app -w /app php:8.1-cli vendor/bin/phpunit

# Package Plugin Release
bash scripts/package-plugin.sh # or composer package

# Local Live Kanboard Test Instance
docker compose up -d # Accessible at http://localhost:8085 (admin/admin)
```

---

## 🤖 Agentic Development Lifecycle

Every task executed by Claude or AI agents follows a 6-phase loop:

1. **Intent & Spec Check**: Review `docs/specs/` and `docs/SECURITY.md` for target task boundaries.
2. **Plan & User Approval**: Formulate a small, reviewable implementation plan before touching code.
3. **Strict Code Implementation**:
   - PSR-12 coding standard with strict types (`declare(strict_types=1);`).
   - No Kanboard core modifications.
   - All plain-text output MUST be wrapped with `htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
   - All input paths MUST be wrapped with `basename()`.
4. **Automated Unit Testing**: Write unit tests for every handler, service, and validator in `tests/Unit/`.
5. **Agentic Verification Pipeline**: Execute `bash scripts/agent-verify.sh` and run PHPUnit tests.
6. **Mandatory Documentation Update**: Update `CLAUDE.md` and `walkthrough.md` at the end of EVERY step.

---

## 📐 Architecture & Non-Negotiable Rules

1. **No Core Edits**: Never modify Kanboard core files. All functionality stays within this plugin.
2. **No Guessing**: Flag unverified APIs as `UNKNOWN` or `ASSUMPTION`.
3. **No Unescaped Output**: Always sanitize output to prevent XSS.
4. **Plan First**: Never write feature code without prior plan approval.
5. **Update CLAUDE.md Every Step**: Always update `CLAUDE.md` with new progress, handlers, and commands.

---

## 🧠 Lessons Learned & Agent Memory

1. **Host Environment Fallback**: If PHP/Composer binaries are absent on host CLI, `scripts/agent-verify.sh` automatically routes execution through Docker containers (`php:8.1-cli`, `composer:2`).
2. **Docker Workspace Permissions**: Files created or modified inside Docker containers inherit root/nginx ownership (`1000:100`). Run `docker run --rm -v $(pwd):/work alpine chown -R 1000:1000 /work` to reset file ownership to host user `$USER`.
3. **Kanboard `__isset()` Trap**: `Kanboard\Core\Base` implements magic `__get()` but NOT `__isset()`. Avoid `isset($this->serviceName)`; use container `offsetExists()` via `hasService()`.
4. **Kanboard Template Hook Naming**: Core Kanboard file dropdown hook names are `template:task-file:documents:dropdown` and `template:task-file:images:dropdown`.
5. **Safe Plain Text View**: For `.html`, `.yml`, `.env`, and `.json` attachments, output is strictly HTML-entity escaped (`htmlspecialchars()`) preventing browser script execution or DOM injection.
6. **CSV Modal View Dispatch**: `FilePreviewController` dispatches `FileInteractionCore:file/csv_preview` for CSV attachments, rendering responsive data tables with cell entity escaping and truncation notices.
7. **Markdown & Code Tokenizer**: `CodePreviewHandler` tokenizes code elements using placeholders before HTML span wrapping to prevent comment regexes matching CSS hex colors inside injected span attributes.
8. **PDF Viewer Dispatch**: `FilePreviewController` dispatches `FileInteractionCore:file/pdf_preview` for PDF attachments, rendering a sandboxed `<object>` container with fallback download links and 10MB file limit bounds.
9. **Pre-Save Syntax Checking**: `FileEditValidationService` uses `json_decode()` line scanning to report exact line offset on JSON syntax errors before committing attachment changes.
10. **Live Editor Route Dispatch**: `FileEditController` dispatches `edit` modal rendering and `update` POST actions with pre-save syntax validation and `FileVersionService` revision handling.
11. **OpenXML Spreadsheet Parsing**: `ExcelParserService` extracts `.xlsx` shared strings (`xl/sharedStrings.xml`) and multi-sheet XML structures cleanly with fallback bounds capping.
12. **Excel Handler Dispatch & Binary Classification**: `ExcelPreviewHandler` dispatches `xlsx` and `xls` extensions with a 5 MB cap; binary formats (`pdf`, `xlsx`, `xls`) are classified separately from universal `text/plain` fallbacks in `FileValidationServiceTest`.
13. **Multi-Sheet Spreadsheet Modal View**: `Template/file/excel_preview.php` provides interactive tab switching between workbook sheets, displaying A/B/C column headers, row indices, and cell entity escaping.
14. **Production CSRF Guarding**: `FileEditController` enforces `$this->checkCSRFParam()` on all POST updates to guarantee state changes are protected against cross-site request forgery.
15. **`X-Frame-Options: DENY` Blocks Every Embedded Viewer**: `BootstrapMiddleware::sendHeaders()` queues `X-Frame-Options: DENY` on the shared `Response` singleton for *every* request (`ENABLE_XFRAME` defaults to `true` in `app/constants.php`). Any `<object>`/`<iframe>` viewer fed by a core action will fall through to its fallback content, no matter how correct the URL or MIME type. Core's headers come from PHP, not nginx — verified by a static asset returning none — so a plugin-owned route can override them.
16. **Kanboard's `Response` Cannot Unset a Header**: the container's `response` service already holds the middleware's header bag by the time a controller runs, and exposes no removal method. Calling `Response::send()` therefore always re-emits `DENY`. Binary streams must write their own headers via `header()` / `header_remove()` and never touch `$this->response`.
17. **Inline `<script>` Fails TWICE in a Modal Template** — the single most repeated defect in this plugin (Tasks 35, 36, 38 and 39 all traced to it):
    - **CSP**: `cspRules` is `default-src 'self'` and `script-src` inherits it without `'unsafe-inline'`, so an inline block is refused outright.
    - **innerHTML**: `assets/js/core/dom.js:82` injects modal content with `element.innerHTML = html`, and per the HTML spec a `<script>` inserted that way **never executes** — so listeners would not bind even under a permissive CSP.

    Either reason alone is fatal, and both are silent: no console error, no visible failure, the control just does nothing. **All** client-side behaviour must ship as a file registered on `template:layout:js` (path relative to the Kanboard root, `plugins/<Plugin>/Assets/...`) with **delegated** listeners, because the elements do not exist when the asset runs. `style-src` *does* permit inline CSS, so inline `<style>` is fine.
18. **`js-modal-medium` IS the In-Modal Navigation Primitive**: `assets/js/components/modal.js` delegates clicks on `.js-modal-medium` and calls `KB.modal.replace()` when a modal is already open. A link with that class switches the modal's content with **no custom JavaScript at all** — which is how the in-preview Edit switcher works. Reach for it before writing a handler.
19. **Kanboard's Modal Box Carries an INLINE Width**: `core/modal.js` builds `#modal-box` with `.style('width', width)`. An inline style beats every stylesheet selector regardless of specificity, so fullscreen rules in `Assets/css/preview.css` need `!important`. Overriding from JS instead would mean storing and restoring each original value.
20. **`js-modal-ignore-form` Opts a Form Out of Core's Submit Handling**: core's `getForm()` selects `#modal-content form:not(.js-modal-ignore-form)` and POSTs it, replacing the modal with the response body. Any form whose endpoint answers with JSON — like the editor's update action — must carry that class or the user sees raw JSON.
21. **Core Dropdown Entries Precede Plugin Hooks**: `app/Template/task_file/files.php` renders its own "View file" `<li>` before calling `$this->hook->render(...)` at line 34, so a plugin cannot suppress core entries server-side. `Assets/js/dropdown-cleanup.js` prunes them in the DOM, scoped to the `fic-safe-preview` marker `<li>` — never globally, because core is the *only* viewer for `mp3/mp4/svg/webm/mov` attachments.
22. **Inline Streaming Allow-List Is Not the Preview Allow-List**: `FileStreamController::INLINE_MIME_TYPES` contains `pdf` only. Being previewable through an escaping handler does not make a format safe to serve as a live document — an inline `.html` or `.svg` attachment from our own origin would be stored XSS.
23. **`Route::findUrl()` Requires Exact Param Sets**: a pretty route only matches when the supplied params are exactly the route's params (`array_diff_key` plus a count check). Omitting `project_id` when it is 0 makes the URL silently degrade to `?controller=…&action=…`, so all three params are always passed for the stream route.
24. **PHPStan Needs an Explicit Memory Limit**: `php:8.1-cli` caps at 128 M, which the current `src/` exceeds. The failure surfaces as `Child process error (exit code 255)` with no mention of memory; `scripts/agent-verify.sh` now passes `--memory-limit` (override via `PHPSTAN_MEMORY_LIMIT`).
25. **`KB.modal.replace(url)` Is the In-Modal Navigation API**: `assets/js/core/modal.js` exposes it publicly, and `components/modal.js` already delegates clicks on `.js-modal-replace`. Reloading modal content server-side is therefore free — no need to re-render anything in JS. The language picker uses it, which is why the syntax tokenizer stays in PHP instead of being duplicated in JavaScript.
26. **`resolveHandler()` Cannot Force a Handler Onto an Unsupported Extension**: `FileInteractionManager::resolveHandler()` still consults `supports()` unless the format is literally `text`/`raw`, so `format=code` silently fell through to `TextPreviewHandler` when highlighting a `.txt` as Python. An explicit language choice therefore selects the handler **by name** via `findHandlerByName()`.
27. **`metadata['language']` Is the Raw Token, Not the Canonical Id**: existing tests assert `metadata['language'] === 'sh'` (the extension). Canonical registry ids travel separately as `languageId` / `languageLabel` so that contract stays intact.
28. **Unknown-Extension Preview Needs a Read Ceiling**: allowing arbitrary extensions through means any upload can be the preview target, and `objectStorage->get()` buffers the whole file before any cap is consulted. `FilePreviewController::CONTENT_READ_CEILING_BYTES` skips the read entirely using the attachment row's declared `size`, and that declared size also keeps `validateFileSize()` honest when content was never loaded.
29. **Transport Delimiters as Tokens, Never Raw Characters**: a literal tab or pipe in a query string survives neither URL encoding nor HTML attribute escaping reliably, and accepting a raw character would feed an arbitrary request value into `str_getcsv()`. `CsvDelimiterRegistry` validates opaque tokens (`comma`, `semicolon`, `tab`, `pipe`, `auto`) so only one of four known characters ever reaches the parser.
30. **A Checkbox Carries Its TOGGLED URL**: `Template/file/csv_controls.php` emits `data-fic-url` pointing at the state the checkbox would switch *to*. The server always renders the correct next target, so `preview-controls.js` holds no state of its own and cannot disagree with what is on screen.
31. **Auto-Detect Must Stay Selected While Active**: the picker keys `selected` on `delimiterMode` (the user's choice), not on `delimiterToken` (the effective delimiter). Keying it on the effective value would make the control silently jump off "Auto-detect" after the first render, with no way back.
32. **One Asset for All In-Modal Controls**: `Assets/js/preview-controls.js` (formerly `preview-language-selector.js`) drives the language picker, the CSV delimiter picker and the header toggle from a single delegated `change` listener. Adding another reload control means adding its attribute to that script's `SELECTOR`, not shipping a new file.
33. **A View-Mode Toggle Must Key Off the RENDERING Handler**: raw mode swaps the rich handler for `CodePreviewHandler`, so keying `rawViewAvailable` on the handler actually in use makes the control disappear as soon as it is used. `FilePreviewController` keeps `$renderedHandlerName` from before the swap for exactly this reason.
34. **Friendly Type Names Belong in a Registry**: `PreviewViewModeRegistry::getTypeLabel()` is the only thing standing between internal class names and the UI. It never returns a class name — an unrecognised handler falls back to the neutral "File".
35. **Media Formats Must Stay Out of the Preview Path**: `FileValidationService::CORE_MEDIA_EXTENSIONS` keeps images/audio/video rejected. Core already renders working viewers for them, and it guarantees no URL can route active content (`svg`) into a preview. This list is also what preserves Task 35's dropdown scoping — keep it aligned with core's `FileHelper::getBrowserViewType()`.
36. **High-Fidelity PPTX Vector Rendering & Delegated Controls**: PowerPoint rendering in browser uses `pptx-viewer.umd.js` with vector SVG slide generation, theme color extraction, and shape geometry. Control handlers for slide deck switching (Prev/Next/Tabs) MUST use delegated event listeners on `document` so they function seamlessly in both AJAX modal containers and standalone new tabs.
