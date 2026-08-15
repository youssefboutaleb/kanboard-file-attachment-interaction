# Walkthrough — FileInteractionCore

Engineering log of implementation steps and their verification evidence.

---

## Milestone 9: DOCX & PPTX Document Preview Engine (v0.9.0)

High-fidelity in-app document and presentation preview engines for Microsoft Office files (`.docx`, `.dotx`, `.doc`, `.pptx`, `.potx`, `.ppt`).

### 1. High-Fidelity In-Browser Rendering Engines (docx-preview & pptx-viewer)
- **Word Document High-Fidelity Renderer (`Assets/js/vendor/docx-preview.min.js` + `Assets/js/vendor/jszip.min.js`)**:
  - Full client-side DOM rendering preserving Word pagination, margins, typography, tables, drawings, shapes, and embedded images.
  - Initialized asynchronously via `docx.renderAsync()` with progress spinner and fallback error handling in `Assets/js/office-viewer.js`.
- **PowerPoint Presentation High-Fidelity Renderer (`Assets/js/vendor/pptx-viewer.umd.js`)**:
  - Full client-side presentation rendering preserving slide aspect ratio, background colors, shapes, typography, tables, and slide deck layout.
  - **Text Whitespace Preservation**: Patched OpenXML text extraction (`W(e)`) to preserve intra-run whitespace without global trimming so multi-run words (e.g. bold or colored spans) remain cleanly spaced.
  - **Paragraph Text Flow & Alignment**: Added explicit `text-align` on inner flex span containers and updated non-bullet paragraphs to block flow so centered, right-aligned, and justified text render accurately without left-alignment drift.
  - **Manual Line Break (`<a:br>`) Support**: Preserved document order traversal of paragraph child nodes (`r`, `br`, `fld`) so manual line breaks insert `<br>` elements instead of collapsing onto a single line.
  - **Shape Mirroring (`flipH` / `flipV`) Matrix Transforms**: Extracted `flipH` and `flipV` flags from shape transforms (`<a:xfrm>`) and applied scale mirroring in SVG coordinate transforms (`so(e.bounds, e.rotation)`) so flipped shapes, connectors, and diagrams render in their correct orientation.
  - Interactive presentation toolbar featuring **Prev**, **Next**, **Slide X of Y** counter badge, slide tabs strip, and keyboard navigation (`ArrowLeft`, `ArrowRight`, `PageUp`, `PageDown`, `Space`).
- **Binary Stream Integration (`FileStreamController.php`)**:
  - Added `docx`, `dotx`, `pptx`, `potx` to `INLINE_MIME_TYPES` and `MAGIC_SIGNATURES` with `PK` magic byte validation, 10MB/15MB size caps, and same-origin framing CSP (`frame-ancestors 'self'`).
  - Served directly via `/b/:project_id/task/:task_id/file/:file_id/stream`.

### 2. Pure-PHP Memory-Safe OpenXML Structural Parsers (Server-Side Fallback & Metadata)
- **Word Document Parser (`src/Service/DocxParserService.php`)**:
  - OpenXML DOM traversal over `word/document.xml` using `LIBXML_NONET | LIBXML_NOBLANKS` to prevent XXE / SSRF vulnerabilities.
  - Extracts structural headings (H1-H4), styled text runs, bullet/numbered lists, and tables with pre-escaped HTML (`htmlspecialchars()`).
  - Computes document statistics: `headingCount`, `paragraphCount`, `tableCount`, `wordCount`.
- **PowerPoint Presentation Parser (`src/Service/PptxParserService.php`)**:
  - Resolves slide ordering via `ppt/presentation.xml` & `ppt/_rels/presentation.xml.rels`, extracting slide titles, bullet points, text blocks, and structured slide tables.
- **Legacy Format Graceful Fallback**:
  - Detects legacy `.doc` and `.ppt` (OLE2 binary format) in `DocxPreviewHandler.php` and `PptxPreviewHandler.php` (`isLegacyFormat = true`), displaying clean download notices without memory bloat.

### 3. Verification Evidence
- **Automated Agent Verification Pipeline (`bash scripts/agent-verify.sh`)**:
  - PHP Syntax: OK
  - Composer Validation: OK
  - PHPStan Static Analysis (Level 8): **0 errors**
  - PHPUnit Test Suite: **755 tests passing, 2562 assertions, 0 errors, 0 failures**
- **Release Packaging**:
  - Packaged `dist/FileInteractionCore-0.9.0.zip` ready for distribution.

---

## Attachment Interaction Fixes & In-App Spreadsheet & Text Editing (PDF, XLSX, CSV, Text, Code, Rendered HTML/MD)

Comprehensive fixes for file attachment interactions, in-modal Rendered/Raw view mode switching, in-browser interactive spreadsheet grid editing for XLSX & CSV, complete save/cancel/CSRF edit engine overhaul, standalone Excel sheet tab switching, and unified "Open in new tab" action.

### 1. In-Modal Rendered / Raw View Mode Toggle Fix
- **Problem**: Clicking the Rendered / Raw toggle button (`fic-btn-view-mode`) on HTML or Markdown attachments triggered a browser navigation that loaded the standalone new-tab page view instead of swapping the modal content in place.
- **Solution**:
  - Added `class="js-modal-medium fic-btn-view-mode"` in `Template/file/modal_actions.php`.
  - Added delegated `click` listener in `Assets/js/preview-controls.js` that intercepts clicks on `[data-fic-view-mode]` / `.fic-btn-view-mode`, stops default navigation (`event.preventDefault()`), and executes `window.KB.modal.replace(url)`.
  - The modal content switches smoothly between Rendered and Raw views in place without closing the modal or navigating the window.

### 2. Standalone XLSX Sheet Tab Switching Fix
- **Problem**: In standalone (new tab) view mode, switching sheets in a multi-sheet spreadsheet was unable to target the sibling panel.
- **Solution**:
  - Wrapped sheet tabs and panels in dedicated container `<div class="fic-sheet-container">` in `Template/file/excel_preview.php`.
  - Updated `onSheetTabClick` in `Assets/js/preview-controls.js` to look for the common root container, ensuring sheet tab clicking works seamlessly both inside Kanboard modals and on standalone full-window pages.

### 3. Complete Edit / Save / Cancel Overhaul Across All File Types
- **Save Engine Fix**: `FileEditController::update()` extracts raw form parameters directly via `$this->request->getRawFormValues()` / `$_POST` before Kanboard core's `Request::getValues()` strips the CSRF token.
- **CSRF Token Validation**: Validates submitted form CSRF tokens via `$this->token->validateCSRFToken(...)` and `$this->token->validateReusableCSRFToken(...)`.
- **Standalone Layout Rendering Fix**: Fixed container helper resolution (`is_object($this->helper->layout ?? null)` and `method_exists($layout, 'app')`), ensuring standalone (new tab) editing loads Kanboard's full application shell with all CSS (`preview.css`) and JavaScript (`editor.js`, `preview-controls.js`).
- **Non-AJAX Fallback**: Standard browser form POST submissions redirect with HTTP 302 and flash success messages instead of dumping raw JSON.
- **Cancel Button Fix**: Replaced invalid `.close-popover` class with `class="js-modal-close btn btn-link fic-edit-cancel"`, properly triggering modal close (`KB.modal.close()`) or `history.back()`.
- **Form Submission**: Submits `FormData(form)` over AJAX, displaying server alerts on failure, and on success closes the modal and refreshes the file list.

### 4. In-Browser Interactive Spreadsheet Grid Editor (XLSX & CSV)
- **Interactive Grid Interface**: When editing `.xlsx`, `.xls`, `.csv`, or `.tsv`:
  - Renders an interactive spreadsheet grid in `Template/file/edit.php` with sticky column letter headers (`A`, `B`, `C`...) and row numbers (`1`, `2`, `3`...).
  - Features an active cell formula bar displaying the current coordinate (e.g. `A1`), `fx` symbol, and inline cell editor with bidirectional sync.
  - Toolbar with quick actions: **+ Row**, **+ Column**, **- Row**, **- Column**, **Clear Cell**.
  - **CSV Single-Sheet Rules**: CSV / TSV files hide `+ Sheet` and sheet tabs since CSV is flat tabular data.
  - **Multi-Sheet XLSX Management**: XLSX files feature full sheet lifecycle management: **Add Sheet** (`+ Sheet`), **Rename Sheet** (pencil icon with prompt), and **Delete Sheet** (`×` button on tabs with confirmation).
  - Keyboard navigation (`Tab`, `Shift+Tab`, `Enter`, `Shift+Enter`, Arrow keys) and real-time synchronization.
- **Backend OpenXML & CSV Packaging**:
  - `ExcelWriterService` (`src/Service/ExcelWriterService.php`) converts multi-sheet structured JSON (`grid_data`) or tabular CSV text back into standard OpenXML `.xlsx` binary packages using a pure-PHP PKZIP packager.

### 5. Verification Evidence
- Automated Verification Pipeline (`bash scripts/agent-verify.sh`):
  - PHP Syntax: OK
  - Composer Validation: OK
  - PHPStan Static Analysis (Level 8): **0 errors**
  - PHPUnit Suite: **725 tests passing, 2427 assertions, 0 failures, 0 errors**

---

## Unified UI Action Panel & View Mode Toggle — Release v0.7.1

> Requested as "Task 40". Released as `0.7.1` **after** `0.8.0`, at explicit request — see the version
> note at the end.

### 1. Unified bottom action bar

One `.panel-meta` container in `Template/file/modal_actions.php`, rendered by all five preview modals plus
the editor and the binary notice. The v0.8.0 top-right fullscreen button is gone.

The Fullscreen control changed shape: it is now an `<a class="fic-btn-fullscreen">` rather than a
`<button>`. It keeps a **real `href`** (the preview URL for the current view) so a middle-click or a
JavaScript-less browser still goes somewhere sensible, while `preview-controls.js` intercepts the click
and toggles `.fic-modal-fullscreen` in place. `target="_blank"` is deliberately *not* set on it — the
requirement's sample markup includes it, but for four of the five modals the preview route returns a bare
modal fragment, so a new tab would show unstyled HTML. PDF is the one case where a new tab genuinely
works (the stream is a real standalone document), so it keeps a separate "Open in new tab" link.

### 2. Removing the technical labels

Handler class names, the inline language badge (`BASH`) and all five "Safe …" boilerplate lines are gone.
`PreviewViewModeRegistry::getTypeLabel()` replaces them with friendly names — "PDF Document", "CSV Table",
"Spreadsheet" — and is written so it *cannot* return a class name: an unrecognised handler falls back to
the neutral "File". `testNeverReturnsAnInternalClassName` sweeps every handler × extension combination.

### 3. The view-mode toggle, and the trap in it

`view=raw` swaps the rich handler for `CodePreviewHandler`. The subtle part: **availability cannot be
keyed on the handler in use**, because after the swap that is always `CodePreviewHandler` — the toggle
would disappear the instant it was used, with no way back. `FilePreviewController` keeps
`$renderedHandlerName` from before the swap for exactly this. `testToggleRemainsAvailableInRawMode` locks
it down.

Two deliberate scope decisions:

- **Excel raw view.** An `.xlsx` is a ZIP, so its "source" is binary. Rather than hide the toggle (which
  the requirement asks for on Excel) or dump mojibake, a raw request whose bytes are binary answers with
  the existing "Binary File" notice plus a **Render** button back to the grid. That reuses
  `BinaryContentDetector`, so the branch is keyed on content rather than on the extension — a text-backed
  file still renders raw normally.
- **Diagrams.** The requirement lists them among rich formats. This plugin has no diagram renderer, so
  there is nothing to toggle. Noted in the CHANGELOG under "Not implemented"; the registry is the one
  place to add it later.

PDF, plain text and JSON get no toggle: the first has no text source, the others already *are* source.

### 4. Verification

**Live HTTP**, authenticated, all five modals:

```
                  bars  friendly label        handler-name / boilerplate leaks
.md   (file 4)      1   Markdown Modal        none
.pdf  (file 29)     1   PDF Document Modal    none
.csv  (file 33)     1   CSV Table Modal       none
.xlsx (file 27)     1   Spreadsheet Modal     none
.json (file 5)      1   JSON Modal            none

.md rendered -> fa-toggle-on,  link to view=raw,      Edit + Fullscreen + Download present
.md raw      -> fa-toggle-off, link to view=rendered, code-highlight present, zero <h1>
.xlsx raw    -> "Binary File (Preview not supported, click Download)" + fic-btn-render + view=rendered
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 694 / 694 (100%)  —  2292 assertions   (was 617; +77 new)
```

### 5. Pre-existing tests that changed meaning

Five encoded the old design and were rewritten, not deleted:

| Test | Was | Now |
|---|---|---|
| `testFullscreenToggleIsAButtonThatCannotSubmitAForm` | asserted `<button type="button">` | renamed `…IsALinkInTheActionBar`; asserts the anchor **and** that no `<button>` carries the toggle attribute |
| `testEditSwitcherIsOfferedForEditableFormats` | asserted the label "Edit File" | asserts "Edit" |
| `testPickerAlsoRendersInTheHighlightedCodeView` | asserted the `PYTHON` badge | asserts the badge is **absent** and the picker still shows "Python" |
| `testFullscreenLinkOpensInlineStreamNotDownload` | Fullscreen href == stream URL | split: `…OpenInNewTabLinkTargetsTheInlineStream` + `…FullscreenControlIsTheSharedInModalToggle` |
| `testMarkdownPreviewTemplateRendersCodeViewVariant` | asserted `SH` badge + "Safe Read-Only…" | asserts both are absent |

One over-broad assertion I added and then fixed: a blanket `assertStringNotContainsString('<button type="submit"')`
failed on the editor, which has a legitimate Save button. It now targets the toggle attribute specifically.

### 6. Version note

**This release is numbered below its predecessor.** `0.8.0` was published in the previous step; this work
is additive UI change, so under SemVer it belongs at `0.8.1` or `0.9.0`. `0.7.1` was requested explicitly
in two of the five requirements, so that is what shipped — but the history now reads 0.7.0 → 0.8.0 → 0.7.1,
and the `dist/` directory holds a `0.8.0` zip that is *older* than the `0.7.1` one. Renumbering is four
small edits (`Plugin.php`, the `PluginTest` assertion, the CHANGELOG heading, the README refs) plus a
repackage.

**Carried forward**: the manual browser pass is still outstanding, and this release changes the fullscreen
control from a button to a link — worth including in that pass.

---

## Task 43 — Release v0.8.0

> Requested as "Task 41". Roadmap Task 43.

```
Plugin.php::getPluginVersion()          -> 0.8.0
PluginTest version assertion            -> 0.8.0
CHANGELOG.md                            -> [0.8.0] - 2026-08-09 section; [Unreleased] now holds
                                           only the v0.9.0 and v1.0.0 plans
README.md                               -> badge, release heading, install paths, test count
dist/FileInteractionCore-0.8.0.zip      -> built by scripts/package-plugin.sh
```

`composer.json` deliberately carries no `version` field (it fails `composer validate --strict`), so
`Plugin.php::getPluginVersion()` stays the single source of truth the packaging script reads.

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 617 / 617 (100%)  —  1967 assertions
```

Archive contents verified: all five JS/CSS assets and all ten templates present, zero dev files
(`tests/`, `vendor/`, `scripts/`, `phpunit.xml`, `phpstan.neon` all excluded).

**Carried into this release**: the manual browser pass listed under Tasks 41 & 42 §7 is still outstanding.
Five scripts and one stylesheet added across Tasks 35-42 are verified by markup-contract tests and textual
pinning, never by execution. Worth completing before announcing the release publicly.

---

## Tasks 41 & 42 — In-Preview Edit Switcher & Modal Fullscreen

> Requested as "Task 40". These are roadmap Tasks 41 and 42 (Milestone 8).

### 1. Both controls live in one partial

`Template/file/modal_actions.php` is rendered by all six modal templates
(`preview`, `markdown_preview`, `csv_preview`, `pdf_preview`, `excel_preview`, `edit`), so the controls
exist once rather than six times.

### 2. The Edit switcher needs no JavaScript at all

`assets/js/components/modal.js` already delegates clicks on `.js-modal-medium` and calls
`KB.modal.replace()` when a modal is open. So the switcher is just:

```html
<a href="/b/7/task/3/file/42/edit" class="js-modal-medium fic-edit-switcher">Edit File</a>
```

Core does the seamless swap. Writing a handler for this would have been redundant work — worth checking
core's existing classes before reaching for a listener.

**Two gates, in two different places, on purpose:**

| Gate | Where | Why |
|---|---|---|
| Format is editable | Controller (`FileEditValidationService::EDITABLE_EXTENSIONS`) | Testable in isolation; single source of truth |
| User may mutate attachments | Template (`hasProjectAccess('TaskFileController', 'remove')`) | Needs Kanboard's user helper. `PermissionService` defaults to a permissive mock — real ACL is core middleware, so the template check is the honest one. Mirrors what `dropdown.php` already does. |

Project-overview attachments are excluded: `FileEditController` resolves through `taskFileModel` only, so
there is no editable target without a task id.

### 3. Fullscreen — why `!important` is unavoidable

`.fic-modal-fullscreen` is toggled on Kanboard's `#modal-box`, which core builds with
`.style('width', width)` — an **inline** style. Inline styles beat every stylesheet selector regardless
of specificity, so `Assets/css/preview.css` has to use `!important` for the width/height overrides. The
alternative (setting inline styles from JS) would mean storing and restoring each original value on
every toggle, which is more fragile.

The same applies to the per-template inline `max-height` on each scroll wrapper, which is sized for the
normal modal and has to grow in fullscreen.

Styles ship as a registered `template:layout:css` asset rather than an inline `<style>`. Inline CSS *is*
permitted by the CSP (`style-src` allows `'unsafe-inline'`), but an asset is cached instead of re-sent
with every modal.

### 4. Out-of-scope defect found and fixed: the live editor never worked

While adding the fullscreen button to `edit.php` I found its entire client-side layer shipped as an
inline `<script>` — the **same** double failure as the Excel tabs in Task 39:

1. CSP refuses inline blocks (`default-src 'self'`, no `script-src 'unsafe-inline'`).
2. Modal content is injected via `element.innerHTML` (`assets/js/core/dom.js:82`), and an injected
   `<script>` never executes.

Confirmed the editor is served as a bare fragment, so it is definitely innerHTML-injected:

```
GET /b/1/task/1/file/4/edit  ->  no <html>, starts with <div class="page-header">, 1 inline <script>
```

So since v0.5.0 the line and character counters never updated, the gutter never tracked scrolling, JSON
was never re-validated on input, and — worst — the form fell back to a plain POST, navigating the browser
to the **raw JSON body** of the update response instead of staying in the modal.

Extracted to `Assets/js/editor.js` with delegated listeners. Three details:

- Translated strings and the JSON-mode flag now travel as `data-*` attributes, since a static asset
  cannot call `t()`. The JSON labels are emitted only for `.json`, so a plain-text editor carries no JSON
  vocabulary at all.
- `scroll` does not bubble, so the gutter sync listener uses the **capture** phase.
- The form gained `js-modal-ignore-form`. Core's `getForm()` selects
  `#modal-content form:not(.js-modal-ignore-form)` and POSTs it, replacing the modal with the response
  body — which for this endpoint is JSON. Without the class, core and our own handler would both fire.

This was outside the requested scope. It is reported separately in `CHANGELOG.md` for that reason.

### 5. Verification

**Live HTTP**, authenticated:

```
assets                -> preview-controls.js 200, editor.js 200, preview.css 200
                         all three injected into the task page with cache-busting mtimes
.md preview  (file 4) -> data-fic-edit-switcher + Edit File + href="/b/1/task/1/file/4/edit"
                         + data-fic-fullscreen-toggle
.pdf preview (file 29)-> data-fic-fullscreen-toggle only, NO edit switcher
csv / excel previews  -> fullscreen toggle present
editor modal (file 5) -> 0 inline <script>, js-modal-ignore-form,
                         data-format="json", data-label-valid, data-label-error
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 617 / 617 (100%)  —  1967 assertions   (was 562; +55 new)
```

**Teeth confirmed** by mutation: removing the `hasProjectAccess()` call fails
`testEditSwitcherIsWithheldWithoutWritePermission`; renaming the fullscreen class fails
`testFullscreenScriptTogglesTheClassOnTheModalBox`.

### 6. Test-harness work this required

- `PluginTest`'s own `FakeTemplateHelper::render()` took a **file path**, but templates now call
  `$this->render('FileInteractionCore:file/partial', …)` — which lands on that same method. It now accepts
  both spellings, as `Kanboard\Core\Template::getTemplateFile()` does.
- `FakeTemplateRenderer` gained a `form` helper (the editor calls `$this->form->csrf()`).
- `syntaxStatusOf()` compared raw markup, so the new `data-label-invalid` attribute tripped a
  "not contains" assertion. It now compares the element's **visible text**, which is what the test meant.
- Two "no inline script" assertions kept flagging their own explanatory comments. `InspectsPhpSource`
  (shared trait) strips PHP comment tokens via `token_get_all()`, and JS comment lines, before asserting.

### 7. Open item — unchanged and now larger

**None of the client-side behaviour added across Tasks 35-42 has been confirmed in a browser.** That is
now five scripts and one stylesheet, all verified by markup-contract tests and textual pinning of the
shipped files, never by execution — no browser automation has been available in any of these sessions and
the `php:8.1-cli` container has no JS runtime.

The editor fix in §4 especially deserves a real click-through, since it changes a save path:

1. Open a `.json` attachment in the editor; type invalid JSON — the indicator should turn red live.
2. Save — it should stay in the modal and reload the page, **not** navigate to raw JSON.
3. Click **Edit File** from a `.md` preview — the modal should swap to the editor in place.
4. Click **⛶ Fullscreen** in each of the six modals — the box should fill the viewport with a sticky header.

---

## Tasks 39 & 40 — Excel Sheet Tab Fix & v0.7.0 Release

> Requested as "Task 38". Roadmap numbering ran one ahead from Task 36 onward because Task 35 closed both
> the orphan dropdown action and the PDF stream routing that roadmap Task 37 covered.

### 1. Symptom

Clicking a sheet tab in the `.xlsx` preview did nothing. No console error, no partial switch — the tab
simply did not respond.

### 2. Root cause — dead code twice over

The switcher was an inline `<script>` at the foot of `Template/file/excel_preview.php`. **The JavaScript
itself was correct**; it could never run, for two independent reasons:

1. **CSP refuses it.** `cspRules` is `default-src 'self'` and `script-src` inherits it without
   `'unsafe-inline'` (verified in Task 35: `Content-Security-Policy: default-src 'self'; style-src 'self'
   'unsafe-inline'; img-src * data:;` — no `script-src` at all).
2. **`innerHTML` never executes it.** Modal content is injected by
   `assets/js/core/dom.js:82` → `element.innerHTML = html`, and per the HTML spec a `<script>` inserted
   that way is not executed. So the listeners would not bind even under a permissive CSP.

Either reason alone is fatal, and both fail silently. This is the fourth task in Milestone 7 to trace to
the same class of defect, so `CLAUDE.md` lesson 17 was expanded to state both halves explicitly.

### 3. Fix

The logic moved into the already-registered `Assets/js/preview-controls.js`, joining the Task 36 language
picker and Task 38 CSV controls, with a **delegated** `click` listener — the tabs do not exist when the
asset runs.

Two robustness improvements went in with it:

| Before | After |
|---|---|
| Panels matched by DOM **ordinal** among `.fic-sheet-panel` | Panels carry `data-sheet-index` and are paired by **value** |
| `document.querySelectorAll` — global | Scoped to the clicked tab's own strip, so two previews cannot drive each other |

The badge is still updated with `textContent`, never `innerHTML`: sheet names arrive pre-escaped from
`ExcelPreviewHandler`, so assigning them as markup would double-unescape them back into live markup.

### 4. Verification

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 562 / 562 (100%)  —  1816 assertions   (was 545; +17 new)
```

`tests/Integration/ExcelSheetSwitchingTest.php` pins the markup contract the asset depends on (tabs and
panels paired by index, panels as siblings of the strip, exactly one visible panel) and guards the
regression directly — `testTemplateShipsNoInlineScript` fails if an inline block returns.

**Teeth confirmed**: deleting the delegated `click` registration fails
`testSwitcherUsesDelegatedClickListener`.

One test needed care rather than a code change: the template now documents the defect in prose that
necessarily names `<script>`, so the "no inline script" assertion strips PHP comment tokens via
`token_get_all()` before checking — otherwise it flags its own explanation.

Two pre-existing `PluginTest` assertions counted `data-sheet-index=` occurrences and were updated for the
new panel attribute (3 tabs + 3 panels = 6). They now count `role="tab"` / `role="tabpanel"`, which is
unambiguous — `fic-sheet-tab` also matches the `fic-sheet-tabs` container.

### 5. Release v0.7.0

```
Plugin.php::getPluginVersion()          -> 0.7.0
PluginTest version assertion            -> 0.7.0
CHANGELOG.md                            -> [Unreleased] split; [0.7.0] - 2026-08-09 section added
dist/FileInteractionCore-0.7.0.zip      -> built by scripts/package-plugin.sh
```

`composer.json` deliberately carries no `version` field (it fails `composer validate --strict`), so
`Plugin.php::getPluginVersion()` remains the single source of truth the packaging script reads.

### 6. Open item carried into the release

**The client-side behaviour added across Tasks 35–39 has not been confirmed in a browser.** No browser
automation was available in any of these sessions, and the `php:8.1-cli` test container has no JS runtime,
so all four scripts' DOM behaviour is verified by markup-contract tests plus textual pinning of the
shipped files — never by execution.

Worth one manual pass at `http://localhost:8085` (admin/admin) before announcing the release:

1. PDF renders inline with no fallback banner (Chrome and Firefox).
2. Orphan **View file** entry is gone for a PDF, and still present for an `.mp4`/`.svg`.
3. The syntax picker visibly re-renders the modal.
4. The CSV delimiter picker and header toggle re-render the table in place.
5. Excel sheet tabs switch panels.

Every server response behind those interactions is already proven by the traces in the sections below.

Fixture cleanup (Tasks 35–38 test attachments on task 1):

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name in (\"task35_probe.pdf\",\"LICENSE\",\"dump.bak\",\"bundle.zip\")
           or name like \"%_data.csv\"");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task3*'
```

---

## Task 38 — Dynamic CSV Delimiter Selector & Header Toggle

> Requested as "Task 37". The roadmap's Task 37 (PDF stream routing / fullscreen download redirect) was
> already closed by Task 35, so this is the CSV controls task — roadmap Task 38.

### 1. Scope

A delimiter picker (Auto-detect, Comma, Semicolon, Tab, Pipe) and a "First row is header" checkbox in
the CSV preview modal, both re-rendering the table without closing the modal.

### 2. Design — tokens, not characters

Delimiters travel as opaque **tokens** (`comma`, `semicolon`, `tab`, `pipe`, `auto`), never as their
literal characters. Two reasons:

1. A raw tab or pipe survives neither URL encoding nor HTML attribute escaping reliably.
2. Accepting a raw character would feed an arbitrary request value straight into `str_getcsv()`.

`CsvDelimiterRegistry` validates the token against an allow-list first, so exactly one of four known
characters can ever reach the parser. Anything unrecognised collapses to auto-detection.
`testTokenCharactersMatchTheParserCandidateList` pins the registry to
`CsvParserService::CANDIDATE_DELIMITERS`, so the picker can never offer a delimiter the sniffer would
never return.

Re-rendering reuses the Task 36 mechanism: each control carries the fully-built preview URL for the
state it would select, and `KB.modal.replace()` swaps the content in place. Parsing stays server-side
where every cell is already entity-escaped.

**The checkbox carries its *toggled* URL** (`data-fic-url`), so the server always renders the correct
next target and the script holds no state of its own. The `<select>` keeps URLs in its option values.
Both are driven by one delegated `change` listener.

### 3. Two details that would have made the feature feel broken

**Auto-detect has to stay selected while it is active.** The effective delimiter resolves to a concrete
token (`semicolon`), so keying the `selected` attribute on that value would make the control silently
jump off "Auto-detect" after the first render — with no way back to it. `selected` is keyed on
`delimiterMode` (what the user chose) while the resolved token is surfaced separately as
"Auto-detected: SEMICOLON".

**Header off must not leave a headerless blob.** The template previously always `array_shift`-ed the
first row into a `<thead>`. With the toggle off there would be no `<thead>` at all, breaking the sticky
row and the `#` gutter alignment. It now renders 1-based column indices instead, and every row stays in
the body.

### 4. Asset consolidation

`Assets/js/preview-language-selector.js` was renamed to `Assets/js/preview-controls.js` and generalized
to `[data-fic-language-select], [data-fic-csv-control]`. The alternative — a second near-identical file
duplicating the `KB.modal.replace()`/navigation-fallback logic — would have been worse. Adding another
in-modal reload control now means adding its attribute to that script's `SELECTOR`.

### 5. Verification

**Live HTTP**, authenticated, with multi-delimiter fixtures in task 1. The delimiter override genuinely
re-parses rather than relabelling:

```
semicolon_data.csv, auto            -> Auto-detected: SEMICOLON, badge ";", columns: id | name | role
semicolon_data.csv, delimiter=comma -> columns: "id;name;role"      (one unsplit column)
semicolon_data.csv, header=0        -> thead: 1 | 2 | 3             (first row moved to the body)
pipe_data.csv,      delimiter=pipe  -> badge "|",   columns: id | name | role
tab_data.csv,       delimiter=tab   -> badge "TAB", columns: id | name | role
```

Controls and their URLs render as designed, and the parameter is not injectable:

```
data-fic-csv-control="delimiter"   data-fic-csv-control="header"   checked="checked"
data-fic-url=".../file_id=33&delimiter=auto&header=0"    <- toggled target while header is ON
delimiter=" onmouseover="alert(1)  -> rejected, falls back to auto-detect,
                                      no onmouseover in the response
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 545 / 545 (100%)  —  1764 assertions   (was 485; +60 new)
```

**The regression tests have teeth.** Neutralising the delimiter override
(`resolveDelimiter($requestedToken)` → `null`) fails
`testChoosingSemicolonSplitsWhereCommaWouldNot`; forcing `$showHeaderRow = true` fails
`testHeaderOffKeepsEveryRowAsData`.

One false positive was worth fixing in the test rather than the code: asserting a rejected token is
absent from the HTML tripped on `colon` being a substring of the `Semicolon` label. The assertion now
matches the `delimiter=` parameter itself.

### 6. Open item

Same boundary as Tasks 35 and 36: the controls' DOM behaviour is pinned textually, not executed — the
`php:8.1-cli` container has no JS runtime. **Not confirmed in a browser:** that changing the delimiter
or ticking the checkbox visibly re-renders the table in place. Every URL those controls carry is proven
to return the correct table by the traces above.

Remove the Task 38 fixtures when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name like \"%_data.csv\"");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task37_*'
```

---

## Task 36 — Dynamic Language Selector & Unknown Extension Handling

### 1. Scope

Two features: a syntax language picker in the Safe Preview modal header that switches highlighting on
the fly, and content-based classification for attachments whose extension the whitelist cannot place.

### 2. Design decision — switching happens server-side

The picker reloads the modal with a `lang=<id>` parameter rather than re-highlighting in the browser.
`assets/js/core/modal.js` already exposes `KB.modal.replace(url)` publicly, so an in-place content swap
costs nothing, and the tokenizer stays in PHP where the payload is already entity-escaped. The
alternative — porting `CodePreviewHandler::highlightSyntax()` to JavaScript — would mean two copies of
an XSS-sensitive code path drifting apart.

The `<select>` change handler ships as `Assets/js/preview-language-selector.js` registered on
`template:layout:js`, for the same CSP reason as Task 35: `default-src 'self'` with no
`script-src 'unsafe-inline'` means an inline handler is silently blocked.

`SyntaxLanguageRegistry` is the single source of truth for the option list, the per-extension default,
and — importantly — the per-language comment prefixes and keyword sets. Without that last part,
switching language would only change a CSS class and a badge; the feature would look implemented while
doing nothing.

### 3. Two implementation traps worth recording

**`resolveHandler()` will not force a handler onto an unsupported extension.**
Routing an explicit language choice through `format=code` silently produced `TextPreviewHandler` when
highlighting a `.txt` as Python: `FileInteractionManager::resolveHandler()` still calls `supports()`
unless the format is literally `text`/`raw`, and `CodePreviewHandler` declines `.txt`. Caught by
`testExplicitLanguageOverridesTheExtensionDefault`. An explicit choice now selects the handler by name
through `findHandlerByName()`.

**`.json` does not reach `CodePreviewHandler`.**
`JsonPreviewHandler` is registered ahead of it, so gating the picker on `[Code, Text]` disabled it for
exactly the format most likely to want it. The gate is now `[Code, Text, Json]`. Markdown is
deliberately excluded — its output is sanitized HTML, so a language selection would misrepresent what
is on screen.

### 4. Unknown extensions — and the memory bound they exposed

`BinaryContentDetector` inspects an 8 KB window for NUL bytes, a control-character ratio above 10%,
and invalid UTF-8. Text gets an escaped preview plus the picker; binary gets
`Template/file/binary_notice.php`, which renders **none** of the payload — only metadata.

Allowing arbitrary extensions through surfaced a latent DoS: `objectStorage->get()` buffers the entire
file before any size cap is consulted, so a 500 MB upload was suddenly a valid preview target.
`FilePreviewController::CONTENT_READ_CEILING_BYTES` now skips the read entirely based on the
attachment row's declared `size`, and that declared size also keeps `validateFileSize()` honest when
content was never loaded (otherwise an oversized file would have previewed as an empty buffer).

**Core-owned media stays rejected.** `FileValidationService::CORE_MEDIA_EXTENSIONS` excludes
images/audio/video from inspection: core already renders working viewers for them, and it guarantees no
URL can route active content (`svg`) into a preview path. This also preserves Task 35's dropdown
scoping — the `fic-safe-preview` marker is withheld for unclassified extensions, since core renders no
view action for them and there is no orphan to clean.

### 5. Verification

**Live HTTP**, authenticated, with fixtures inserted into task 1:

```
LICENSE     (no extension) -> Detected Text badge + picker
dump.bak    (unknown ext)  -> Detected Text badge + picker
bundle.zip  (binary)       -> "Binary File (Preview not supported, click Download)"
                              + "No file content was rendered", reason: null bytes
```

Switching language genuinely changes the tokenisation, not just a label:

```
file 31 default   -> TextPreviewHandler                     (escaped plain text)
file 31 lang=sql  -> CodePreviewHandler, language-sql,
                     tok-comment (--), tok-keyword (SELECT/FROM)
file 31 lang=json -> language-json, NO tok-comment          (JSON has no comment syntax)
```

Extension defaults resolve correctly, and the `lang` parameter is not injectable:

```
.env        -> lang=config selected      deploy.yml -> lang=yaml selected
*.json      -> lang=json selected
lang=<script>alert(1)</script> -> discarded, falls back to the extension default,
                                  no <script> in the response
asset       -> 200, injected as <script defer src=".../preview-language-selector.js?1786289072">
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 485 / 485 (100%)  —  1556 assertions   (was 351; +134 new)
```

### 6. Pre-existing tests that changed meaning

Three asserted the pre-Task-36 contract and were rewritten, not deleted:

| Test | Was | Now |
|---|---|---|
| `testShowStillRejectsDisallowedBinaryFormats` | `.zip`/`.exe`/`.docx` throw `InvalidFileException` | renamed `...ServesBinaryNoticeFor...`; asserts `BinaryNotice`, `isBinary`, and that **no bytes** are rendered |
| `testRendersErrorModalInsteadOfThrowingForDisallowedExtension` | `.docx` renders the error modal | renamed `...RendersBinaryNoticeFor...`; a sibling test keeps the error-modal assertion for core media |
| `testNoMarkerForFormatsSafePreviewDoesNotHandle` | `.docx`/`.zip`/`LICENSE` get no Safe Preview entry | split: core media gets **no entry**, unclassified gets an **entry without a marker** |

The old fixtures used ASCII payloads (`'PK binary payload'`, `'binary'`) that now correctly classify as
text; the replacements use real binary headers.

### 7. Open item

As with Task 35's cleanup script, the picker's DOM behaviour is pinned textually rather than executed —
the `php:8.1-cli` container has no JS runtime. **Not yet confirmed in a browser:** that changing the
`<select>` visibly swaps the modal content. The server side of that round trip is proven by the traces
above (every option URL returns correctly-highlighted HTML).

Remove the Task 36 fixtures when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name in (\"LICENSE\",\"dump.bak\",\"bundle.zip\")");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task36_*'
```

---

## Task 35 — PDF Stream Routing Fix & Orphan Action Cleanup

### 1. Symptoms

1. PDF attachments opened the modal but showed the *"Inline PDF viewing is not supported by your
   browser or plugin"* fallback banner instead of the document — on current Chrome and Firefox.
2. The modal's **Open Fullscreen / Download** control triggered a save dialog rather than showing
   the PDF.
3. The attachment dropdown listed a redundant core **View file** action next to **Safe Preview**.

### 2. Root cause — the inline banner was never a URL problem

Task 23/24 had already pointed `<object data>` at core's inline `browser` action, and that action is
correct: `FileViewerController::browser()` resolves the MIME type through
`FileHelper::getBrowserViewType()`, which returns `application/pdf` for `.pdf`.

The blocker is a **response header**. `BootstrapMiddleware::sendHeaders()` runs for every request and
queues `X-Frame-Options: DENY` on the shared `Response` singleton whenever `ENABLE_XFRAME` is on —
and `app/constants.php:128` defaults it to `true`. Every core response therefore carries it,
including the PDF byte stream. Browsers render an embedded PDF inside a nested browsing context, so
`DENY` aborts that navigation and `<object>` falls through to its child content.

Confirmed live against `kanboard/kanboard:v1.2.37` on an authenticated session (file_id 29):

```
GET /?controller=FileViewerController&action=browser&task_id=1&file_id=29
HTTP/1.1 200 OK
Content-Type: application/pdf          <- correct
X-Frame-Options: DENY                  <- blocks <object> from rendering it
Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:;
```

A static asset returns **no** framing headers, proving they come from PHP rather than nginx — so a
plugin-owned response can control them:

```
GET /assets/css/print.min.css   -> no X-Frame-Options, no Content-Security-Policy
```

No `data` attribute can work around a response header, and core must not be patched. Hence a
plugin-owned stream route.

### 3. Root cause — the orphan entry cannot be removed server-side

`app/Template/task_file/files.php` renders its own view `<li>` at line 16-25 and only calls
`$this->hook->render('template:task-file:documents:dropdown', …)` at **line 34** — our hook output is
appended *after* core's entry, inside the same `<ul>`. There is no core-free way to suppress it from
PHP, so it must be pruned in the DOM.

Two constraints shaped that fix:

- **Inline `<script>` is dead on arrival.** `cspRules` (`app/ServiceProvider/ClassProvider.php:185`)
  is `default-src 'self'`, and `script-src` falls back to it — no `'unsafe-inline'`. (`style-src`
  *does* allow inline, but CSS alone cannot scope the fix; see below.) The cleanup therefore ships as
  a served asset registered on `template:layout:js`.
- **The fix must be per-file.** Core also offers *View file* for formats Safe Preview does **not**
  handle — `mp3 ogg flac wav avi webm mov m4v mp4 svg` — and those entries are the only way to open
  them. A blanket rule keyed on the href would wrongly hide them, so cleanup is gated on a marker
  `<li>` that the dropdown template emits only for formats this plugin claims.

### 4. Fixes applied

| File | Change |
|---|---|
| `src/Controller/FileStreamController.php` | **New** — `inline` action streaming allow-listed binaries. Drops `X-Frame-Options` and replaces it with `default-src 'none'; frame-ancestors 'self'`. Gates on ACL, a `pdf`-only allow-list, the 10 MB cap, and a `%PDF` magic-byte check. |
| `src/Core/Contract/StreamEmitterInterface.php` | **New** — injectable header/body sink, so the emitted header set is assertable. Needed because Kanboard's `Response` cannot unset a queued header, and `Response::send()` would re-emit `DENY`. |
| `src/Service/HttpStreamEmitter.php` | **New** — production emitter over `header()` / `header_remove()`, guarded by `headers_sent()`. |
| `Plugin.php` | Registered the `/b/:project_id/task/:task_id/file/:file_id/stream` route and the `template:layout:js` asset hook. |
| `Template/file/pdf_preview.php` | `<object data>` now targets the plugin stream route. Split the combined *Open Fullscreen / Download* link into a **Fullscreen** action (inline stream) and a **Download** action (core `download`). |
| `Template/file/dropdown.php` | Safe Preview `<li>` now carries `class="fic-safe-preview" data-fic-ext="…"` as the cleanup gate. |
| `Assets/js/dropdown-cleanup.js` | **New** — removes sibling `<li>`s linking to core `action=show`/`action=browser` within the marker's own `<ul>`. Leaves Download, Remove, and unmarked dropdowns untouched. Re-runs on DOM mutation for ajax-rendered tables. |
| `scripts/agent-verify.sh` | Added `--memory-limit` to PHPStan. `php:8.1-cli` defaults to 128 M, which the grown `src/` now exceeds — it surfaced as an opaque `Child process error (exit code 255)`. |
| `tests/stubs/FakeTemplateRenderer.php` | **New** — renders plugin templates the way `Core\Template::render()` does, with a `FakeUrlHelper` that reproduces `Route::findUrl()` matching rules faithfully. |

### 5. Verification

**Live HTTP** — the new route, authenticated, against the running instance:

```
GET /b/1/task/1/file/29/stream
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: inline; filename="task35_probe.pdf"
Content-Security-Policy: default-src 'none'; frame-ancestors 'self'
X-Content-Type-Options: nosniff
Cache-Control: private, max-age=300
                                       <- X-Frame-Options absent (was DENY)
body: %PDF-1.4...                      <- correct bytes
```

Boundaries hold:

```
unauthenticated            -> 302 /login                (core middleware, no leak)
file 2 (.html) via stream  -> 400 text/plain "File extension ".html" cannot be streamed inline"
asset                      -> 200 application/javascript
task page                  -> <script defer src="/plugins/.../dropdown-cleanup.js?1786286714">
markers emitted            -> pdf×2 md×3 csv×2 json×2 xlsx×2 yml×2 txt env html  (none for .docx/.zip)
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 350 / 350 (100%)  —  1157 assertions   (was 280; +70 new)
```

Two pre-existing tests in `tests/Integration/PluginTest.php` asserted the old behaviour
(`action=browser` in `<object data>`, and `project_id` omitted entirely) and were rewritten to the
corrected contract — the stream route needs all three params present or `Route::findUrl()` cannot
match and the URL silently degrades to a query string.

**The regression tests have teeth.** Restoring the old `<object data>` target fails 7 tests:

```
PdfPreviewTemplateTest::testObjectStreamsThroughPluginStreamRoute
PdfPreviewTemplateTest::testObjectDoesNotUseCoreFileViewerController
PdfPreviewTemplateTest::testFullscreenLinkOpensInlineStreamNotDownload
PdfPreviewTemplateTest::testStreamUrlIsGeneratedWhenProjectIdIsZero
PdfPreviewTemplateTest::testStreamUrlFallsBackToQueryStringCarryingPluginParam
PluginTest::testPdfPreviewTemplateEmbedsInlineStreamActionNotDownload
PluginTest::testPdfPreviewTemplateOmitsUnknownProjectIdOnDownloadAction
```

### 5b. Coverage boundary on the JavaScript

Worth recording, because the first attempt at this was misleading: gutting `entry.remove()` from
`Assets/js/dropdown-cleanup.js` initially failed **zero** tests.
`tests/Integration/DropdownCleanupTest.php` asserts the removal rules against the real assembled
markup, but it does so through a **PHP transcription** of the script's logic — so it verifies the
rules, not the shipped file. `testCleanupPredicateMatchesTheShippedScript` binds the href predicate
to the real script, and `testCleanupScriptStillPerformsTheRemoval` now pins the operations the script
must perform (verified to fail when `entry.remove()` is removed).

Executing the real script would need a JS DOM runtime, which the `php:8.1-cli` test container does not
provide. **The script's live DOM behaviour is therefore covered by transcription plus textual pinning,
not by execution** — the manual browser pass below is what closes it.

### 6. Open item

The header-level cause and fix are proven by the traces above, but **two things still need a real
browser** — no browser automation was available in this session:

1. That the PDF renders inline with no fallback banner (Chrome and Firefox).
2. That the orphan **View file** entry actually disappears from the dropdown, while it survives for an
   `.mp4`/`.svg`/`.mp3` attachment.

Manual pass at `http://localhost:8085` (admin/admin); task 1 has a `task35_probe.pdf` fixture
inserted for exactly this.

Remove the fixture when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name = \"task35_probe.pdf\"");'
docker exec kanboard_test_instance rm -f /var/www/app/data/files/tasks/1/fixture_task35_probe.pdf
```

---

## Milestone 9: DOCX & PPTX Document Preview Engine (v0.9.0)

High-fidelity in-app document and presentation preview engines for Microsoft Office files (`.docx`, `.dotx`, `.doc`, `.pptx`, `.potx`, `.ppt`).

### 1. High-Fidelity In-Browser Rendering Engines (docx-preview & pptx-viewer)
- **Word Document High-Fidelity Renderer (`Assets/js/vendor/docx-preview.min.js` + `Assets/js/vendor/jszip.min.js`)**:
  - Full client-side DOM rendering preserving Word pagination, margins, typography, tables, drawings, shapes, and embedded images.
  - Initialized asynchronously via `docx.renderAsync()` with progress spinner and fallback error handling in `Assets/js/office-viewer.js`.
- **PowerPoint Presentation High-Fidelity Renderer (`Assets/js/vendor/pptx-viewer.umd.js`)**:
  - Full client-side presentation rendering preserving slide aspect ratio, background colors, shapes, typography, tables, and slide deck layout.
  - **Text Whitespace Preservation**: Patched OpenXML text extraction (`W(e)`) to preserve intra-run whitespace without global trimming so multi-run words (e.g. bold or colored spans) remain cleanly spaced.
  - **Paragraph Text Flow & Alignment**: Added explicit `text-align` on inner flex span containers and updated non-bullet paragraphs to block flow so centered, right-aligned, and justified text render accurately without left-alignment drift.
  - **Manual Line Break (`<a:br>`) Support**: Preserved document order traversal of paragraph child nodes (`r`, `br`, `fld`) so manual line breaks insert `<br>` elements instead of collapsing onto a single line.
  - **Shape Mirroring (`flipH` / `flipV`) Matrix Transforms**: Extracted `flipH` and `flipV` flags from shape transforms (`<a:xfrm>`) and applied scale mirroring in SVG coordinate transforms (`so(e.bounds, e.rotation)`) so flipped shapes, connectors, and diagrams render in their correct orientation.
  - Interactive presentation toolbar featuring **Prev**, **Next**, **Slide X of Y** counter badge, slide tabs strip, and keyboard navigation (`ArrowLeft`, `ArrowRight`, `PageUp`, `PageDown`, `Space`).
- **Binary Stream Integration (`FileStreamController.php`)**:
  - Added `docx`, `dotx`, `pptx`, `potx` to `INLINE_MIME_TYPES` and `MAGIC_SIGNATURES` with `PK` magic byte validation, 10MB/15MB size caps, and same-origin framing CSP (`frame-ancestors 'self'`).
  - Served directly via `/b/:project_id/task/:task_id/file/:file_id/stream`.

### 2. Pure-PHP Memory-Safe OpenXML Structural Parsers (Server-Side Fallback & Metadata)
- **Word Document Parser (`src/Service/DocxParserService.php`)**:
  - OpenXML DOM traversal over `word/document.xml` using `LIBXML_NONET | LIBXML_NOBLANKS` to prevent XXE / SSRF vulnerabilities.
  - Extracts structural headings (H1-H4), styled text runs, bullet/numbered lists, and tables with pre-escaped HTML (`htmlspecialchars()`).
  - Computes document statistics: `headingCount`, `paragraphCount`, `tableCount`, `wordCount`.
- **PowerPoint Presentation Parser (`src/Service/PptxParserService.php`)**:
  - Resolves slide ordering via `ppt/presentation.xml` & `ppt/_rels/presentation.xml.rels`, extracting slide titles, bullet points, text blocks, and structured slide tables.
- **Legacy Format Graceful Fallback**:
  - Detects legacy `.doc` and `.ppt` (OLE2 binary format) in `DocxPreviewHandler.php` and `PptxPreviewHandler.php` (`isLegacyFormat = true`), displaying clean download notices without memory bloat.

### 3. Verification Evidence
- **Automated Agent Verification Pipeline (`bash scripts/agent-verify.sh`)**:
  - PHP Syntax: OK
  - Composer Validation: OK
  - PHPStan Static Analysis (Level 8): **0 errors**
  - PHPUnit Test Suite: **755 tests passing, 2562 assertions, 0 errors, 0 failures**
- **Release Packaging**:
  - Packaged `dist/FileInteractionCore-0.9.0.zip` ready for distribution.

---

## Attachment Interaction Fixes & In-App Spreadsheet & Text Editing (PDF, XLSX, CSV, Text, Code, Rendered HTML/MD)

Comprehensive fixes for file attachment interactions, in-modal Rendered/Raw view mode switching, in-browser interactive spreadsheet grid editing for XLSX & CSV, complete save/cancel/CSRF edit engine overhaul, standalone Excel sheet tab switching, and unified "Open in new tab" action.

### 1. In-Modal Rendered / Raw View Mode Toggle Fix
- **Problem**: Clicking the Rendered / Raw toggle button (`fic-btn-view-mode`) on HTML or Markdown attachments triggered a browser navigation that loaded the standalone new-tab page view instead of swapping the modal content in place.
- **Solution**:
  - Added `class="js-modal-medium fic-btn-view-mode"` in `Template/file/modal_actions.php`.
  - Added delegated `click` listener in `Assets/js/preview-controls.js` that intercepts clicks on `[data-fic-view-mode]` / `.fic-btn-view-mode`, stops default navigation (`event.preventDefault()`), and executes `window.KB.modal.replace(url)`.
  - The modal content switches smoothly between Rendered and Raw views in place without closing the modal or navigating the window.

### 2. Standalone XLSX Sheet Tab Switching Fix
- **Problem**: In standalone (new tab) view mode, switching sheets in a multi-sheet spreadsheet was unable to target the sibling panel.
- **Solution**:
  - Wrapped sheet tabs and panels in dedicated container `<div class="fic-sheet-container">` in `Template/file/excel_preview.php`.
  - Updated `onSheetTabClick` in `Assets/js/preview-controls.js` to look for the common root container, ensuring sheet tab clicking works seamlessly both inside Kanboard modals and on standalone full-window pages.

### 3. Complete Edit / Save / Cancel Overhaul Across All File Types
- **Save Engine Fix**: `FileEditController::update()` extracts raw form parameters directly via `$this->request->getRawFormValues()` / `$_POST` before Kanboard core's `Request::getValues()` strips the CSRF token.
- **CSRF Token Validation**: Validates submitted form CSRF tokens via `$this->token->validateCSRFToken(...)` and `$this->token->validateReusableCSRFToken(...)`.
- **Standalone Layout Rendering Fix**: Fixed container helper resolution (`is_object($this->helper->layout ?? null)` and `method_exists($layout, 'app')`), ensuring standalone (new tab) editing loads Kanboard's full application shell with all CSS (`preview.css`) and JavaScript (`editor.js`, `preview-controls.js`).
- **Non-AJAX Fallback**: Standard browser form POST submissions redirect with HTTP 302 and flash success messages instead of dumping raw JSON.
- **Cancel Button Fix**: Replaced invalid `.close-popover` class with `class="js-modal-close btn btn-link fic-edit-cancel"`, properly triggering modal close (`KB.modal.close()`) or `history.back()`.
- **Form Submission**: Submits `FormData(form)` over AJAX, displaying server alerts on failure, and on success closes the modal and refreshes the file list.

### 4. In-Browser Interactive Spreadsheet Grid Editor (XLSX & CSV)
- **Interactive Grid Interface**: When editing `.xlsx`, `.xls`, `.csv`, or `.tsv`:
  - Renders an interactive spreadsheet grid in `Template/file/edit.php` with sticky column letter headers (`A`, `B`, `C`...) and row numbers (`1`, `2`, `3`...).
  - Features an active cell formula bar displaying the current coordinate (e.g. `A1`), `fx` symbol, and inline cell editor with bidirectional sync.
  - Toolbar with quick actions: **+ Row**, **+ Column**, **- Row**, **- Column**, **Clear Cell**.
  - **CSV Single-Sheet Rules**: CSV / TSV files hide `+ Sheet` and sheet tabs since CSV is flat tabular data.
  - **Multi-Sheet XLSX Management**: XLSX files feature full sheet lifecycle management: **Add Sheet** (`+ Sheet`), **Rename Sheet** (pencil icon with prompt), and **Delete Sheet** (`×` button on tabs with confirmation).
  - Keyboard navigation (`Tab`, `Shift+Tab`, `Enter`, `Shift+Enter`, Arrow keys) and real-time synchronization.
- **Backend OpenXML & CSV Packaging**:
  - `ExcelWriterService` (`src/Service/ExcelWriterService.php`) converts multi-sheet structured JSON (`grid_data`) or tabular CSV text back into standard OpenXML `.xlsx` binary packages using a pure-PHP PKZIP packager.

### 5. Verification Evidence
- Automated Verification Pipeline (`bash scripts/agent-verify.sh`):
  - PHP Syntax: OK
  - Composer Validation: OK
  - PHPStan Static Analysis (Level 8): **0 errors**
  - PHPUnit Suite: **725 tests passing, 2427 assertions, 0 failures, 0 errors**

---

## Unified UI Action Panel & View Mode Toggle — Release v0.7.1

> Requested as "Task 40". Released as `0.7.1` **after** `0.8.0`, at explicit request — see the version
> note at the end.

### 1. Unified bottom action bar

One `.panel-meta` container in `Template/file/modal_actions.php`, rendered by all five preview modals plus
the editor and the binary notice. The v0.8.0 top-right fullscreen button is gone.

The Fullscreen control changed shape: it is now an `<a class="fic-btn-fullscreen">` rather than a
`<button>`. It keeps a **real `href`** (the preview URL for the current view) so a middle-click or a
JavaScript-less browser still goes somewhere sensible, while `preview-controls.js` intercepts the click
and toggles `.fic-modal-fullscreen` in place. `target="_blank"` is deliberately *not* set on it — the
requirement's sample markup includes it, but for four of the five modals the preview route returns a bare
modal fragment, so a new tab would show unstyled HTML. PDF is the one case where a new tab genuinely
works (the stream is a real standalone document), so it keeps a separate "Open in new tab" link.

### 2. Removing the technical labels

Handler class names, the inline language badge (`BASH`) and all five "Safe …" boilerplate lines are gone.
`PreviewViewModeRegistry::getTypeLabel()` replaces them with friendly names — "PDF Document", "CSV Table",
"Spreadsheet" — and is written so it *cannot* return a class name: an unrecognised handler falls back to
the neutral "File". `testNeverReturnsAnInternalClassName` sweeps every handler × extension combination.

### 3. The view-mode toggle, and the trap in it

`view=raw` swaps the rich handler for `CodePreviewHandler`. The subtle part: **availability cannot be
keyed on the handler in use**, because after the swap that is always `CodePreviewHandler` — the toggle
would disappear the instant it was used, with no way back. `FilePreviewController` keeps
`$renderedHandlerName` from before the swap for exactly this. `testToggleRemainsAvailableInRawMode` locks
it down.

Two deliberate scope decisions:

- **Excel raw view.** An `.xlsx` is a ZIP, so its "source" is binary. Rather than hide the toggle (which
  the requirement asks for on Excel) or dump mojibake, a raw request whose bytes are binary answers with
  the existing "Binary File" notice plus a **Render** button back to the grid. That reuses
  `BinaryContentDetector`, so the branch is keyed on content rather than on the extension — a text-backed
  file still renders raw normally.
- **Diagrams.** The requirement lists them among rich formats. This plugin has no diagram renderer, so
  there is nothing to toggle. Noted in the CHANGELOG under "Not implemented"; the registry is the one
  place to add it later.

PDF, plain text and JSON get no toggle: the first has no text source, the others already *are* source.

### 4. Verification

**Live HTTP**, authenticated, all five modals:

```
                  bars  friendly label        handler-name / boilerplate leaks
.md   (file 4)      1   Markdown Modal        none
.pdf  (file 29)     1   PDF Document Modal    none
.csv  (file 33)     1   CSV Table Modal       none
.xlsx (file 27)     1   Spreadsheet Modal     none
.json (file 5)      1   JSON Modal            none

.md rendered -> fa-toggle-on,  link to view=raw,      Edit + Fullscreen + Download present
.md raw      -> fa-toggle-off, link to view=rendered, code-highlight present, zero <h1>
.xlsx raw    -> "Binary File (Preview not supported, click Download)" + fic-btn-render + view=rendered
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 694 / 694 (100%)  —  2292 assertions   (was 617; +77 new)
```

### 5. Pre-existing tests that changed meaning

Five encoded the old design and were rewritten, not deleted:

| Test | Was | Now |
|---|---|---|
| `testFullscreenToggleIsAButtonThatCannotSubmitAForm` | asserted `<button type="button">` | renamed `…IsALinkInTheActionBar`; asserts the anchor **and** that no `<button>` carries the toggle attribute |
| `testEditSwitcherIsOfferedForEditableFormats` | asserted the label "Edit File" | asserts "Edit" |
| `testPickerAlsoRendersInTheHighlightedCodeView` | asserted the `PYTHON` badge | asserts the badge is **absent** and the picker still shows "Python" |
| `testFullscreenLinkOpensInlineStreamNotDownload` | Fullscreen href == stream URL | split: `…OpenInNewTabLinkTargetsTheInlineStream` + `…FullscreenControlIsTheSharedInModalToggle` |
| `testMarkdownPreviewTemplateRendersCodeViewVariant` | asserted `SH` badge + "Safe Read-Only…" | asserts both are absent |

One over-broad assertion I added and then fixed: a blanket `assertStringNotContainsString('<button type="submit"')`
failed on the editor, which has a legitimate Save button. It now targets the toggle attribute specifically.

### 6. Version note

**This release is numbered below its predecessor.** `0.8.0` was published in the previous step; this work
is additive UI change, so under SemVer it belongs at `0.8.1` or `0.9.0`. `0.7.1` was requested explicitly
in two of the five requirements, so that is what shipped — but the history now reads 0.7.0 → 0.8.0 → 0.7.1,
and the `dist/` directory holds a `0.8.0` zip that is *older* than the `0.7.1` one. Renumbering is four
small edits (`Plugin.php`, the `PluginTest` assertion, the CHANGELOG heading, the README refs) plus a
repackage.

**Carried forward**: the manual browser pass is still outstanding, and this release changes the fullscreen
control from a button to a link — worth including in that pass.

---

## Task 43 — Release v0.8.0

> Requested as "Task 41". Roadmap Task 43.

```
Plugin.php::getPluginVersion()          -> 0.8.0
PluginTest version assertion            -> 0.8.0
CHANGELOG.md                            -> [0.8.0] - 2026-08-09 section; [Unreleased] now holds
                                           only the v0.9.0 and v1.0.0 plans
README.md                               -> badge, release heading, install paths, test count
dist/FileInteractionCore-0.8.0.zip      -> built by scripts/package-plugin.sh
```

`composer.json` deliberately carries no `version` field (it fails `composer validate --strict`), so
`Plugin.php::getPluginVersion()` stays the single source of truth the packaging script reads.

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 617 / 617 (100%)  —  1967 assertions
```

Archive contents verified: all five JS/CSS assets and all ten templates present, zero dev files
(`tests/`, `vendor/`, `scripts/`, `phpunit.xml`, `phpstan.neon` all excluded).

**Carried into this release**: the manual browser pass listed under Tasks 41 & 42 §7 is still outstanding.
Five scripts and one stylesheet added across Tasks 35-42 are verified by markup-contract tests and textual
pinning, never by execution. Worth completing before announcing the release publicly.

---

## Tasks 41 & 42 — In-Preview Edit Switcher & Modal Fullscreen

> Requested as "Task 40". These are roadmap Tasks 41 and 42 (Milestone 8).

### 1. Both controls live in one partial

`Template/file/modal_actions.php` is rendered by all six modal templates
(`preview`, `markdown_preview`, `csv_preview`, `pdf_preview`, `excel_preview`, `edit`), so the controls
exist once rather than six times.

### 2. The Edit switcher needs no JavaScript at all

`assets/js/components/modal.js` already delegates clicks on `.js-modal-medium` and calls
`KB.modal.replace()` when a modal is open. So the switcher is just:

```html
<a href="/b/7/task/3/file/42/edit" class="js-modal-medium fic-edit-switcher">Edit File</a>
```

Core does the seamless swap. Writing a handler for this would have been redundant work — worth checking
core's existing classes before reaching for a listener.

**Two gates, in two different places, on purpose:**

| Gate | Where | Why |
|---|---|---|
| Format is editable | Controller (`FileEditValidationService::EDITABLE_EXTENSIONS`) | Testable in isolation; single source of truth |
| User may mutate attachments | Template (`hasProjectAccess('TaskFileController', 'remove')`) | Needs Kanboard's user helper. `PermissionService` defaults to a permissive mock — real ACL is core middleware, so the template check is the honest one. Mirrors what `dropdown.php` already does. |

Project-overview attachments are excluded: `FileEditController` resolves through `taskFileModel` only, so
there is no editable target without a task id.

### 3. Fullscreen — why `!important` is unavoidable

`.fic-modal-fullscreen` is toggled on Kanboard's `#modal-box`, which core builds with
`.style('width', width)` — an **inline** style. Inline styles beat every stylesheet selector regardless
of specificity, so `Assets/css/preview.css` has to use `!important` for the width/height overrides. The
alternative (setting inline styles from JS) would mean storing and restoring each original value on
every toggle, which is more fragile.

The same applies to the per-template inline `max-height` on each scroll wrapper, which is sized for the
normal modal and has to grow in fullscreen.

Styles ship as a registered `template:layout:css` asset rather than an inline `<style>`. Inline CSS *is*
permitted by the CSP (`style-src` allows `'unsafe-inline'`), but an asset is cached instead of re-sent
with every modal.

### 4. Out-of-scope defect found and fixed: the live editor never worked

While adding the fullscreen button to `edit.php` I found its entire client-side layer shipped as an
inline `<script>` — the **same** double failure as the Excel tabs in Task 39:

1. CSP refuses inline blocks (`default-src 'self'`, no `script-src 'unsafe-inline'`).
2. Modal content is injected via `element.innerHTML` (`assets/js/core/dom.js:82`), and an injected
   `<script>` never executes.

Confirmed the editor is served as a bare fragment, so it is definitely innerHTML-injected:

```
GET /b/1/task/1/file/4/edit  ->  no <html>, starts with <div class="page-header">, 1 inline <script>
```

So since v0.5.0 the line and character counters never updated, the gutter never tracked scrolling, JSON
was never re-validated on input, and — worst — the form fell back to a plain POST, navigating the browser
to the **raw JSON body** of the update response instead of staying in the modal.

Extracted to `Assets/js/editor.js` with delegated listeners. Three details:

- Translated strings and the JSON-mode flag now travel as `data-*` attributes, since a static asset
  cannot call `t()`. The JSON labels are emitted only for `.json`, so a plain-text editor carries no JSON
  vocabulary at all.
- `scroll` does not bubble, so the gutter sync listener uses the **capture** phase.
- The form gained `js-modal-ignore-form`. Core's `getForm()` selects
  `#modal-content form:not(.js-modal-ignore-form)` and POSTs it, replacing the modal with the response
  body — which for this endpoint is JSON. Without the class, core and our own handler would both fire.

This was outside the requested scope. It is reported separately in `CHANGELOG.md` for that reason.

### 5. Verification

**Live HTTP**, authenticated:

```
assets                -> preview-controls.js 200, editor.js 200, preview.css 200
                         all three injected into the task page with cache-busting mtimes
.md preview  (file 4) -> data-fic-edit-switcher + Edit File + href="/b/1/task/1/file/4/edit"
                         + data-fic-fullscreen-toggle
.pdf preview (file 29)-> data-fic-fullscreen-toggle only, NO edit switcher
csv / excel previews  -> fullscreen toggle present
editor modal (file 5) -> 0 inline <script>, js-modal-ignore-form,
                         data-format="json", data-label-valid, data-label-error
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 617 / 617 (100%)  —  1967 assertions   (was 562; +55 new)
```

**Teeth confirmed** by mutation: removing the `hasProjectAccess()` call fails
`testEditSwitcherIsWithheldWithoutWritePermission`; renaming the fullscreen class fails
`testFullscreenScriptTogglesTheClassOnTheModalBox`.

### 6. Test-harness work this required

- `PluginTest`'s own `FakeTemplateHelper::render()` took a **file path**, but templates now call
  `$this->render('FileInteractionCore:file/partial', …)` — which lands on that same method. It now accepts
  both spellings, as `Kanboard\Core\Template::getTemplateFile()` does.
- `FakeTemplateRenderer` gained a `form` helper (the editor calls `$this->form->csrf()`).
- `syntaxStatusOf()` compared raw markup, so the new `data-label-invalid` attribute tripped a
  "not contains" assertion. It now compares the element's **visible text**, which is what the test meant.
- Two "no inline script" assertions kept flagging their own explanatory comments. `InspectsPhpSource`
  (shared trait) strips PHP comment tokens via `token_get_all()`, and JS comment lines, before asserting.

### 7. Open item — unchanged and now larger

**None of the client-side behaviour added across Tasks 35-42 has been confirmed in a browser.** That is
now five scripts and one stylesheet, all verified by markup-contract tests and textual pinning of the
shipped files, never by execution — no browser automation has been available in any of these sessions and
the `php:8.1-cli` container has no JS runtime.

The editor fix in §4 especially deserves a real click-through, since it changes a save path:

1. Open a `.json` attachment in the editor; type invalid JSON — the indicator should turn red live.
2. Save — it should stay in the modal and reload the page, **not** navigate to raw JSON.
3. Click **Edit File** from a `.md` preview — the modal should swap to the editor in place.
4. Click **⛶ Fullscreen** in each of the six modals — the box should fill the viewport with a sticky header.

---

## Tasks 39 & 40 — Excel Sheet Tab Fix & v0.7.0 Release

> Requested as "Task 38". Roadmap numbering ran one ahead from Task 36 onward because Task 35 closed both
> the orphan dropdown action and the PDF stream routing that roadmap Task 37 covered.

### 1. Symptom

Clicking a sheet tab in the `.xlsx` preview did nothing. No console error, no partial switch — the tab
simply did not respond.

### 2. Root cause — dead code twice over

The switcher was an inline `<script>` at the foot of `Template/file/excel_preview.php`. **The JavaScript
itself was correct**; it could never run, for two independent reasons:

1. **CSP refuses it.** `cspRules` is `default-src 'self'` and `script-src` inherits it without
   `'unsafe-inline'` (verified in Task 35: `Content-Security-Policy: default-src 'self'; style-src 'self'
   'unsafe-inline'; img-src * data:;` — no `script-src` at all).
2. **`innerHTML` never executes it.** Modal content is injected by
   `assets/js/core/dom.js:82` → `element.innerHTML = html`, and per the HTML spec a `<script>` inserted
   that way is not executed. So the listeners would not bind even under a permissive CSP.

Either reason alone is fatal, and both fail silently. This is the fourth task in Milestone 7 to trace to
the same class of defect, so `CLAUDE.md` lesson 17 was expanded to state both halves explicitly.

### 3. Fix

The logic moved into the already-registered `Assets/js/preview-controls.js`, joining the Task 36 language
picker and Task 38 CSV controls, with a **delegated** `click` listener — the tabs do not exist when the
asset runs.

Two robustness improvements went in with it:

| Before | After |
|---|---|
| Panels matched by DOM **ordinal** among `.fic-sheet-panel` | Panels carry `data-sheet-index` and are paired by **value** |
| `document.querySelectorAll` — global | Scoped to the clicked tab's own strip, so two previews cannot drive each other |

The badge is still updated with `textContent`, never `innerHTML`: sheet names arrive pre-escaped from
`ExcelPreviewHandler`, so assigning them as markup would double-unescape them back into live markup.

### 4. Verification

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 562 / 562 (100%)  —  1816 assertions   (was 545; +17 new)
```

`tests/Integration/ExcelSheetSwitchingTest.php` pins the markup contract the asset depends on (tabs and
panels paired by index, panels as siblings of the strip, exactly one visible panel) and guards the
regression directly — `testTemplateShipsNoInlineScript` fails if an inline block returns.

**Teeth confirmed**: deleting the delegated `click` registration fails
`testSwitcherUsesDelegatedClickListener`.

One test needed care rather than a code change: the template now documents the defect in prose that
necessarily names `<script>`, so the "no inline script" assertion strips PHP comment tokens via
`token_get_all()` before checking — otherwise it flags its own explanation.

Two pre-existing `PluginTest` assertions counted `data-sheet-index=` occurrences and were updated for the
new panel attribute (3 tabs + 3 panels = 6). They now count `role="tab"` / `role="tabpanel"`, which is
unambiguous — `fic-sheet-tab` also matches the `fic-sheet-tabs` container.

### 5. Release v0.7.0

```
Plugin.php::getPluginVersion()          -> 0.7.0
PluginTest version assertion            -> 0.7.0
CHANGELOG.md                            -> [Unreleased] split; [0.7.0] - 2026-08-09 section added
dist/FileInteractionCore-0.7.0.zip      -> built by scripts/package-plugin.sh
```

`composer.json` deliberately carries no `version` field (it fails `composer validate --strict`), so
`Plugin.php::getPluginVersion()` remains the single source of truth the packaging script reads.

### 6. Open item carried into the release

**The client-side behaviour added across Tasks 35–39 has not been confirmed in a browser.** No browser
automation was available in any of these sessions, and the `php:8.1-cli` test container has no JS runtime,
so all four scripts' DOM behaviour is verified by markup-contract tests plus textual pinning of the
shipped files — never by execution.

Worth one manual pass at `http://localhost:8085` (admin/admin) before announcing the release:

1. PDF renders inline with no fallback banner (Chrome and Firefox).
2. Orphan **View file** entry is gone for a PDF, and still present for an `.mp4`/`.svg`.
3. The syntax picker visibly re-renders the modal.
4. The CSV delimiter picker and header toggle re-render the table in place.
5. Excel sheet tabs switch panels.

Every server response behind those interactions is already proven by the traces in the sections below.

Fixture cleanup (Tasks 35–38 test attachments on task 1):

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name in (\"task35_probe.pdf\",\"LICENSE\",\"dump.bak\",\"bundle.zip\")
           or name like \"%_data.csv\"");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task3*'
```

---

## Task 38 — Dynamic CSV Delimiter Selector & Header Toggle

> Requested as "Task 37". The roadmap's Task 37 (PDF stream routing / fullscreen download redirect) was
> already closed by Task 35, so this is the CSV controls task — roadmap Task 38.

### 1. Scope

A delimiter picker (Auto-detect, Comma, Semicolon, Tab, Pipe) and a "First row is header" checkbox in
the CSV preview modal, both re-rendering the table without closing the modal.

### 2. Design — tokens, not characters

Delimiters travel as opaque **tokens** (`comma`, `semicolon`, `tab`, `pipe`, `auto`), never as their
literal characters. Two reasons:

1. A raw tab or pipe survives neither URL encoding nor HTML attribute escaping reliably.
2. Accepting a raw character would feed an arbitrary request value straight into `str_getcsv()`.

`CsvDelimiterRegistry` validates the token against an allow-list first, so exactly one of four known
characters can ever reach the parser. Anything unrecognised collapses to auto-detection.
`testTokenCharactersMatchTheParserCandidateList` pins the registry to
`CsvParserService::CANDIDATE_DELIMITERS`, so the picker can never offer a delimiter the sniffer would
never return.

Re-rendering reuses the Task 36 mechanism: each control carries the fully-built preview URL for the
state it would select, and `KB.modal.replace()` swaps the content in place. Parsing stays server-side
where every cell is already entity-escaped.

**The checkbox carries its *toggled* URL** (`data-fic-url`), so the server always renders the correct
next target and the script holds no state of its own. The `<select>` keeps URLs in its option values.
Both are driven by one delegated `change` listener.

### 3. Two details that would have made the feature feel broken

**Auto-detect has to stay selected while it is active.** The effective delimiter resolves to a concrete
token (`semicolon`), so keying the `selected` attribute on that value would make the control silently
jump off "Auto-detect" after the first render — with no way back to it. `selected` is keyed on
`delimiterMode` (what the user chose) while the resolved token is surfaced separately as
"Auto-detected: SEMICOLON".

**Header off must not leave a headerless blob.** The template previously always `array_shift`-ed the
first row into a `<thead>`. With the toggle off there would be no `<thead>` at all, breaking the sticky
row and the `#` gutter alignment. It now renders 1-based column indices instead, and every row stays in
the body.

### 4. Asset consolidation

`Assets/js/preview-language-selector.js` was renamed to `Assets/js/preview-controls.js` and generalized
to `[data-fic-language-select], [data-fic-csv-control]`. The alternative — a second near-identical file
duplicating the `KB.modal.replace()`/navigation-fallback logic — would have been worse. Adding another
in-modal reload control now means adding its attribute to that script's `SELECTOR`.

### 5. Verification

**Live HTTP**, authenticated, with multi-delimiter fixtures in task 1. The delimiter override genuinely
re-parses rather than relabelling:

```
semicolon_data.csv, auto            -> Auto-detected: SEMICOLON, badge ";", columns: id | name | role
semicolon_data.csv, delimiter=comma -> columns: "id;name;role"      (one unsplit column)
semicolon_data.csv, header=0        -> thead: 1 | 2 | 3             (first row moved to the body)
pipe_data.csv,      delimiter=pipe  -> badge "|",   columns: id | name | role
tab_data.csv,       delimiter=tab   -> badge "TAB", columns: id | name | role
```

Controls and their URLs render as designed, and the parameter is not injectable:

```
data-fic-csv-control="delimiter"   data-fic-csv-control="header"   checked="checked"
data-fic-url=".../file_id=33&delimiter=auto&header=0"    <- toggled target while header is ON
delimiter=" onmouseover="alert(1)  -> rejected, falls back to auto-detect,
                                      no onmouseover in the response
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 545 / 545 (100%)  —  1764 assertions   (was 485; +60 new)
```

**The regression tests have teeth.** Neutralising the delimiter override
(`resolveDelimiter($requestedToken)` → `null`) fails
`testChoosingSemicolonSplitsWhereCommaWouldNot`; forcing `$showHeaderRow = true` fails
`testHeaderOffKeepsEveryRowAsData`.

One false positive was worth fixing in the test rather than the code: asserting a rejected token is
absent from the HTML tripped on `colon` being a substring of the `Semicolon` label. The assertion now
matches the `delimiter=` parameter itself.

### 6. Open item

Same boundary as Tasks 35 and 36: the controls' DOM behaviour is pinned textually, not executed — the
`php:8.1-cli` container has no JS runtime. **Not confirmed in a browser:** that changing the delimiter
or ticking the checkbox visibly re-renders the table in place. Every URL those controls carry is proven
to return the correct table by the traces above.

Remove the Task 38 fixtures when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name like \"%_data.csv\"");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task37_*'
```

---

## Task 36 — Dynamic Language Selector & Unknown Extension Handling

### 1. Scope

Two features: a syntax language picker in the Safe Preview modal header that switches highlighting on
the fly, and content-based classification for attachments whose extension the whitelist cannot place.

### 2. Design decision — switching happens server-side

The picker reloads the modal with a `lang=<id>` parameter rather than re-highlighting in the browser.
`assets/js/core/modal.js` already exposes `KB.modal.replace(url)` publicly, so an in-place content swap
costs nothing, and the tokenizer stays in PHP where the payload is already entity-escaped. The
alternative — porting `CodePreviewHandler::highlightSyntax()` to JavaScript — would mean two copies of
an XSS-sensitive code path drifting apart.

The `<select>` change handler ships as `Assets/js/preview-language-selector.js` registered on
`template:layout:js`, for the same CSP reason as Task 35: `default-src 'self'` with no
`script-src 'unsafe-inline'` means an inline handler is silently blocked.

`SyntaxLanguageRegistry` is the single source of truth for the option list, the per-extension default,
and — importantly — the per-language comment prefixes and keyword sets. Without that last part,
switching language would only change a CSS class and a badge; the feature would look implemented while
doing nothing.

### 3. Two implementation traps worth recording

**`resolveHandler()` will not force a handler onto an unsupported extension.**
Routing an explicit language choice through `format=code` silently produced `TextPreviewHandler` when
highlighting a `.txt` as Python: `FileInteractionManager::resolveHandler()` still calls `supports()`
unless the format is literally `text`/`raw`, and `CodePreviewHandler` declines `.txt`. Caught by
`testExplicitLanguageOverridesTheExtensionDefault`. An explicit choice now selects the handler by name
through `findHandlerByName()`.

**`.json` does not reach `CodePreviewHandler`.**
`JsonPreviewHandler` is registered ahead of it, so gating the picker on `[Code, Text]` disabled it for
exactly the format most likely to want it. The gate is now `[Code, Text, Json]`. Markdown is
deliberately excluded — its output is sanitized HTML, so a language selection would misrepresent what
is on screen.

### 4. Unknown extensions — and the memory bound they exposed

`BinaryContentDetector` inspects an 8 KB window for NUL bytes, a control-character ratio above 10%,
and invalid UTF-8. Text gets an escaped preview plus the picker; binary gets
`Template/file/binary_notice.php`, which renders **none** of the payload — only metadata.

Allowing arbitrary extensions through surfaced a latent DoS: `objectStorage->get()` buffers the entire
file before any size cap is consulted, so a 500 MB upload was suddenly a valid preview target.
`FilePreviewController::CONTENT_READ_CEILING_BYTES` now skips the read entirely based on the
attachment row's declared `size`, and that declared size also keeps `validateFileSize()` honest when
content was never loaded (otherwise an oversized file would have previewed as an empty buffer).

**Core-owned media stays rejected.** `FileValidationService::CORE_MEDIA_EXTENSIONS` excludes
images/audio/video from inspection: core already renders working viewers for them, and it guarantees no
URL can route active content (`svg`) into a preview path. This also preserves Task 35's dropdown
scoping — the `fic-safe-preview` marker is withheld for unclassified extensions, since core renders no
view action for them and there is no orphan to clean.

### 5. Verification

**Live HTTP**, authenticated, with fixtures inserted into task 1:

```
LICENSE     (no extension) -> Detected Text badge + picker
dump.bak    (unknown ext)  -> Detected Text badge + picker
bundle.zip  (binary)       -> "Binary File (Preview not supported, click Download)"
                              + "No file content was rendered", reason: null bytes
```

Switching language genuinely changes the tokenisation, not just a label:

```
file 31 default   -> TextPreviewHandler                     (escaped plain text)
file 31 lang=sql  -> CodePreviewHandler, language-sql,
                     tok-comment (--), tok-keyword (SELECT/FROM)
file 31 lang=json -> language-json, NO tok-comment          (JSON has no comment syntax)
```

Extension defaults resolve correctly, and the `lang` parameter is not injectable:

```
.env        -> lang=config selected      deploy.yml -> lang=yaml selected
*.json      -> lang=json selected
lang=<script>alert(1)</script> -> discarded, falls back to the extension default,
                                  no <script> in the response
asset       -> 200, injected as <script defer src=".../preview-language-selector.js?1786289072">
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 485 / 485 (100%)  —  1556 assertions   (was 351; +134 new)
```

### 6. Pre-existing tests that changed meaning

Three asserted the pre-Task-36 contract and were rewritten, not deleted:

| Test | Was | Now |
|---|---|---|
| `testShowStillRejectsDisallowedBinaryFormats` | `.zip`/`.exe`/`.docx` throw `InvalidFileException` | renamed `...ServesBinaryNoticeFor...`; asserts `BinaryNotice`, `isBinary`, and that **no bytes** are rendered |
| `testRendersErrorModalInsteadOfThrowingForDisallowedExtension` | `.docx` renders the error modal | renamed `...RendersBinaryNoticeFor...`; a sibling test keeps the error-modal assertion for core media |
| `testNoMarkerForFormatsSafePreviewDoesNotHandle` | `.docx`/`.zip`/`LICENSE` get no Safe Preview entry | split: core media gets **no entry**, unclassified gets an **entry without a marker** |

The old fixtures used ASCII payloads (`'PK binary payload'`, `'binary'`) that now correctly classify as
text; the replacements use real binary headers.

### 7. Open item

As with Task 35's cleanup script, the picker's DOM behaviour is pinned textually rather than executed —
the `php:8.1-cli` container has no JS runtime. **Not yet confirmed in a browser:** that changing the
`<select>` visibly swaps the modal content. The server side of that round trip is proven by the traces
above (every option URL returns correctly-highlighted HTML).

Remove the Task 36 fixtures when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name in (\"LICENSE\",\"dump.bak\",\"bundle.zip\")");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task36_*'
```

---

## Task 35 — PDF Stream Routing Fix & Orphan Action Cleanup

### 1. Symptoms

1. PDF attachments opened the modal but showed the *"Inline PDF viewing is not supported by your
   browser or plugin"* fallback banner instead of the document — on current Chrome and Firefox.
2. The modal's **Open Fullscreen / Download** control triggered a save dialog rather than showing
   the PDF.
3. The attachment dropdown listed a redundant core **View file** action next to **Safe Preview**.

### 2. Root cause — the inline banner was never a URL problem

Task 23/24 had already pointed `<object data>` at core's inline `browser` action, and that action is
correct: `FileViewerController::browser()` resolves the MIME type through
`FileHelper::getBrowserViewType()`, which returns `application/pdf` for `.pdf`.

The blocker is a **response header**. `BootstrapMiddleware::sendHeaders()` runs for every request and
queues `X-Frame-Options: DENY` on the shared `Response` singleton whenever `ENABLE_XFRAME` is on —
and `app/constants.php:128` defaults it to `true`. Every core response therefore carries it,
including the PDF byte stream. Browsers render an embedded PDF inside a nested browsing context, so
`DENY` aborts that navigation and `<object>` falls through to its child content.

Confirmed live against `kanboard/kanboard:v1.2.37` on an authenticated session (file_id 29):

```
GET /?controller=FileViewerController&action=browser&task_id=1&file_id=29
HTTP/1.1 200 OK
Content-Type: application/pdf          <- correct
X-Frame-Options: DENY                  <- blocks <object> from rendering it
Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:;
```

A static asset returns **no** framing headers, proving they come from PHP rather than nginx — so a
plugin-owned response can control them:

```
GET /assets/css/print.min.css   -> no X-Frame-Options, no Content-Security-Policy
```

No `data` attribute can work around a response header, and core must not be patched. Hence a
plugin-owned stream route.

### 3. Root cause — the orphan entry cannot be removed server-side

`app/Template/task_file/files.php` renders its own view `<li>` at line 16-25 and only calls
`$this->hook->render('template:task-file:documents:dropdown', …)` at **line 34** — our hook output is
appended *after* core's entry, inside the same `<ul>`. There is no core-free way to suppress it from
PHP, so it must be pruned in the DOM.

Two constraints shaped that fix:

- **Inline `<script>` is dead on arrival.** `cspRules` (`app/ServiceProvider/ClassProvider.php:185`)
  is `default-src 'self'`, and `script-src` falls back to it — no `'unsafe-inline'`. (`style-src`
  *does* allow inline, but CSS alone cannot scope the fix; see below.) The cleanup therefore ships as
  a served asset registered on `template:layout:js`.
- **The fix must be per-file.** Core also offers *View file* for formats Safe Preview does **not**
  handle — `mp3 ogg flac wav avi webm mov m4v mp4 svg` — and those entries are the only way to open
  them. A blanket rule keyed on the href would wrongly hide them, so cleanup is gated on a marker
  `<li>` that the dropdown template emits only for formats this plugin claims.

### 4. Fixes applied

| File | Change |
|---|---|
| `src/Controller/FileStreamController.php` | **New** — `inline` action streaming allow-listed binaries. Drops `X-Frame-Options` and replaces it with `default-src 'none'; frame-ancestors 'self'`. Gates on ACL, a `pdf`-only allow-list, the 10 MB cap, and a `%PDF` magic-byte check. |
| `src/Core/Contract/StreamEmitterInterface.php` | **New** — injectable header/body sink, so the emitted header set is assertable. Needed because Kanboard's `Response` cannot unset a queued header, and `Response::send()` would re-emit `DENY`. |
| `src/Service/HttpStreamEmitter.php` | **New** — production emitter over `header()` / `header_remove()`, guarded by `headers_sent()`. |
| `Plugin.php` | Registered the `/b/:project_id/task/:task_id/file/:file_id/stream` route and the `template:layout:js` asset hook. |
| `Template/file/pdf_preview.php` | `<object data>` now targets the plugin stream route. Split the combined *Open Fullscreen / Download* link into a **Fullscreen** action (inline stream) and a **Download** action (core `download`). |
| `Template/file/dropdown.php` | Safe Preview `<li>` now carries `class="fic-safe-preview" data-fic-ext="…"` as the cleanup gate. |
| `Assets/js/dropdown-cleanup.js` | **New** — removes sibling `<li>`s linking to core `action=show`/`action=browser` within the marker's own `<ul>`. Leaves Download, Remove, and unmarked dropdowns untouched. Re-runs on DOM mutation for ajax-rendered tables. |
| `scripts/agent-verify.sh` | Added `--memory-limit` to PHPStan. `php:8.1-cli` defaults to 128 M, which the grown `src/` now exceeds — it surfaced as an opaque `Child process error (exit code 255)`. |
| `tests/stubs/FakeTemplateRenderer.php` | **New** — renders plugin templates the way `Core\Template::render()` does, with a `FakeUrlHelper` that reproduces `Route::findUrl()` matching rules faithfully. |

### 5. Verification

**Live HTTP** — the new route, authenticated, against the running instance:

```
GET /b/1/task/1/file/29/stream
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: inline; filename="task35_probe.pdf"
Content-Security-Policy: default-src 'none'; frame-ancestors 'self'
X-Content-Type-Options: nosniff
Cache-Control: private, max-age=300
                                       <- X-Frame-Options absent (was DENY)
body: %PDF-1.4...                      <- correct bytes
```

Boundaries hold:

```
unauthenticated            -> 302 /login                (core middleware, no leak)
file 2 (.html) via stream  -> 400 text/plain "File extension ".html" cannot be streamed inline"
asset                      -> 200 application/javascript
task page                  -> <script defer src="/plugins/.../dropdown-cleanup.js?1786286714">
markers emitted            -> pdf×2 md×3 csv×2 json×2 xlsx×2 yml×2 txt env html  (none for .docx/.zip)
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 350 / 350 (100%)  —  1157 assertions   (was 280; +70 new)
```

Two pre-existing tests in `tests/Integration/PluginTest.php` asserted the old behaviour
(`action=browser` in `<object data>`, and `project_id` omitted entirely) and were rewritten to the
corrected contract — the stream route needs all three params present or `Route::findUrl()` cannot
match and the URL silently degrades to a query string.

**The regression tests have teeth.** Restoring the old `<object data>` target fails 7 tests:

```
PdfPreviewTemplateTest::testObjectStreamsThroughPluginStreamRoute
PdfPreviewTemplateTest::testObjectDoesNotUseCoreFileViewerController
PdfPreviewTemplateTest::testFullscreenLinkOpensInlineStreamNotDownload
PdfPreviewTemplateTest::testStreamUrlIsGeneratedWhenProjectIdIsZero
PdfPreviewTemplateTest::testStreamUrlFallsBackToQueryStringCarryingPluginParam
PluginTest::testPdfPreviewTemplateEmbedsInlineStreamActionNotDownload
PluginTest::testPdfPreviewTemplateOmitsUnknownProjectIdOnDownloadAction
```

### 5b. Coverage boundary on the JavaScript

Worth recording, because the first attempt at this was misleading: gutting `entry.remove()` from
`Assets/js/dropdown-cleanup.js` initially failed **zero** tests.
`tests/Integration/DropdownCleanupTest.php` asserts the removal rules against the real assembled
markup, but it does so through a **PHP transcription** of the script's logic — so it verifies the
rules, not the shipped file. `testCleanupPredicateMatchesTheShippedScript` binds the href predicate
to the real script, and `testCleanupScriptStillPerformsTheRemoval` now pins the operations the script
must perform (verified to fail when `entry.remove()` is removed).

Executing the real script would need a JS DOM runtime, which the `php:8.1-cli` test container does not
provide. **The script's live DOM behaviour is therefore covered by transcription plus textual pinning,
not by execution** — the manual browser pass below is what closes it.

### 6. Open item

The header-level cause and fix are proven by the traces above, but **two things still need a real
browser** — no browser automation was available in this session:

1. That the PDF renders inline with no fallback banner (Chrome and Firefox).
2. That the orphan **View file** entry actually disappears from the dropdown, while it survives for an
   `.mp4`/`.svg`/`.mp3` attachment.

Manual pass at `http://localhost:8085` (admin/admin); task 1 has a `task35_probe.pdf` fixture
inserted for exactly this.

Remove the fixture when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name = \"task35_probe.pdf\"");'
docker exec kanboard_test_instance rm -f /var/www/app/data/files/tasks/1/fixture_task35_probe.pdf
```

---

## Milestone 9: DOCX & PPTX Document Preview Engine (v0.9.0)

High-fidelity in-app document and presentation preview engines for Microsoft Office files (`.docx`, `.dotx`, `.doc`, `.pptx`, `.potx`, `.ppt`).

### 1. High-Fidelity In-Browser Rendering Engines (docx-preview & pptx-viewer)
- **Word Document High-Fidelity Renderer (`Assets/js/vendor/docx-preview.min.js` + `Assets/js/vendor/jszip.min.js`)**:
  - Full client-side DOM rendering preserving Word pagination, margins, typography, tables, drawings, shapes, and embedded images.
  - Initialized asynchronously via `docx.renderAsync()` with progress spinner and fallback error handling in `Assets/js/office-viewer.js`.
- **PowerPoint Presentation High-Fidelity Renderer (`Assets/js/vendor/pptx-viewer.umd.js`)**:
  - Full client-side presentation rendering preserving slide aspect ratio, background colors, shapes, typography, tables, and slide deck layout.
  - **Text Whitespace Preservation**: Patched OpenXML text extraction (`W(e)`) to preserve intra-run whitespace without global trimming so multi-run words (e.g. bold or colored spans) remain cleanly spaced.
  - **Paragraph Text Flow & Alignment**: Added explicit `text-align` on inner flex span containers and updated non-bullet paragraphs to block flow so centered, right-aligned, and justified text render accurately without left-alignment drift.
  - **Manual Line Break (`<a:br>`) Support**: Preserved document order traversal of paragraph child nodes (`r`, `br`, `fld`) so manual line breaks insert `<br>` elements instead of collapsing onto a single line.
  - **Shape Mirroring (`flipH` / `flipV`) Matrix Transforms**: Extracted `flipH` and `flipV` flags from shape transforms (`<a:xfrm>`) and applied scale mirroring in SVG coordinate transforms (`so(e.bounds, e.rotation)`) so flipped shapes, connectors, and diagrams render in their correct orientation.
  - Interactive presentation toolbar featuring **Prev**, **Next**, **Slide X of Y** counter badge, slide tabs strip, and keyboard navigation (`ArrowLeft`, `ArrowRight`, `PageUp`, `PageDown`, `Space`).
- **Binary Stream Integration (`FileStreamController.php`)**:
  - Added `docx`, `dotx`, `pptx`, `potx` to `INLINE_MIME_TYPES` and `MAGIC_SIGNATURES` with `PK` magic byte validation, 10MB/15MB size caps, and same-origin framing CSP (`frame-ancestors 'self'`).
  - Served directly via `/b/:project_id/task/:task_id/file/:file_id/stream`.

### 2. Pure-PHP Memory-Safe OpenXML Structural Parsers (Server-Side Fallback & Metadata)
- **Word Document Parser (`src/Service/DocxParserService.php`)**:
  - OpenXML DOM traversal over `word/document.xml` using `LIBXML_NONET | LIBXML_NOBLANKS` to prevent XXE / SSRF vulnerabilities.
  - Extracts structural headings (H1-H4), styled text runs, bullet/numbered lists, and tables with pre-escaped HTML (`htmlspecialchars()`).
  - Computes document statistics: `headingCount`, `paragraphCount`, `tableCount`, `wordCount`.
- **PowerPoint Presentation Parser (`src/Service/PptxParserService.php`)**:
  - Resolves slide ordering via `ppt/presentation.xml` & `ppt/_rels/presentation.xml.rels`, extracting slide titles, bullet points, text blocks, and structured slide tables.
- **Legacy Format Graceful Fallback**:
  - Detects legacy `.doc` and `.ppt` (OLE2 binary format) in `DocxPreviewHandler.php` and `PptxPreviewHandler.php` (`isLegacyFormat = true`), displaying clean download notices without memory bloat.

### 3. Verification Evidence
- **Automated Agent Verification Pipeline (`bash scripts/agent-verify.sh`)**:
  - PHP Syntax: OK
  - Composer Validation: OK
  - PHPStan Static Analysis (Level 8): **0 errors**
  - PHPUnit Test Suite: **755 tests passing, 2562 assertions, 0 errors, 0 failures**
- **Release Packaging**:
  - Packaged `dist/FileInteractionCore-0.9.0.zip` ready for distribution.

---

## Attachment Interaction Fixes & In-App Spreadsheet & Text Editing (PDF, XLSX, CSV, Text, Code, Rendered HTML/MD)

Comprehensive fixes for file attachment interactions, in-modal Rendered/Raw view mode switching, in-browser interactive spreadsheet grid editing for XLSX & CSV, complete save/cancel/CSRF edit engine overhaul, standalone Excel sheet tab switching, and unified "Open in new tab" action.

### 1. In-Modal Rendered / Raw View Mode Toggle Fix
- **Problem**: Clicking the Rendered / Raw toggle button (`fic-btn-view-mode`) on HTML or Markdown attachments triggered a browser navigation that loaded the standalone new-tab page view instead of swapping the modal content in place.
- **Solution**:
  - Added `class="js-modal-medium fic-btn-view-mode"` in `Template/file/modal_actions.php`.
  - Added delegated `click` listener in `Assets/js/preview-controls.js` that intercepts clicks on `[data-fic-view-mode]` / `.fic-btn-view-mode`, stops default navigation (`event.preventDefault()`), and executes `window.KB.modal.replace(url)`.
  - The modal content switches smoothly between Rendered and Raw views in place without closing the modal or navigating the window.

### 2. Standalone XLSX Sheet Tab Switching Fix
- **Problem**: In standalone (new tab) view mode, switching sheets in a multi-sheet spreadsheet was unable to target the sibling panel.
- **Solution**:
  - Wrapped sheet tabs and panels in dedicated container `<div class="fic-sheet-container">` in `Template/file/excel_preview.php`.
  - Updated `onSheetTabClick` in `Assets/js/preview-controls.js` to look for the common root container, ensuring sheet tab clicking works seamlessly both inside Kanboard modals and on standalone full-window pages.

### 3. Complete Edit / Save / Cancel Overhaul Across All File Types
- **Save Engine Fix**: `FileEditController::update()` extracts raw form parameters directly via `$this->request->getRawFormValues()` / `$_POST` before Kanboard core's `Request::getValues()` strips the CSRF token.
- **CSRF Token Validation**: Validates submitted form CSRF tokens via `$this->token->validateCSRFToken(...)` and `$this->token->validateReusableCSRFToken(...)`.
- **Standalone Layout Rendering Fix**: Fixed container helper resolution (`is_object($this->helper->layout ?? null)` and `method_exists($layout, 'app')`), ensuring standalone (new tab) editing loads Kanboard's full application shell with all CSS (`preview.css`) and JavaScript (`editor.js`, `preview-controls.js`).
- **Non-AJAX Fallback**: Standard browser form POST submissions redirect with HTTP 302 and flash success messages instead of dumping raw JSON.
- **Cancel Button Fix**: Replaced invalid `.close-popover` class with `class="js-modal-close btn btn-link fic-edit-cancel"`, properly triggering modal close (`KB.modal.close()`) or `history.back()`.
- **Form Submission**: Submits `FormData(form)` over AJAX, displaying server alerts on failure, and on success closes the modal and refreshes the file list.

### 4. In-Browser Interactive Spreadsheet Grid Editor (XLSX & CSV)
- **Interactive Grid Interface**: When editing `.xlsx`, `.xls`, `.csv`, or `.tsv`:
  - Renders an interactive spreadsheet grid in `Template/file/edit.php` with sticky column letter headers (`A`, `B`, `C`...) and row numbers (`1`, `2`, `3`...).
  - Features an active cell formula bar displaying the current coordinate (e.g. `A1`), `fx` symbol, and inline cell editor with bidirectional sync.
  - Toolbar with quick actions: **+ Row**, **+ Column**, **- Row**, **- Column**, **Clear Cell**.
  - **CSV Single-Sheet Rules**: CSV / TSV files hide `+ Sheet` and sheet tabs since CSV is flat tabular data.
  - **Multi-Sheet XLSX Management**: XLSX files feature full sheet lifecycle management: **Add Sheet** (`+ Sheet`), **Rename Sheet** (pencil icon with prompt), and **Delete Sheet** (`×` button on tabs with confirmation).
  - Keyboard navigation (`Tab`, `Shift+Tab`, `Enter`, `Shift+Enter`, Arrow keys) and real-time synchronization.
- **Backend OpenXML & CSV Packaging**:
  - `ExcelWriterService` (`src/Service/ExcelWriterService.php`) converts multi-sheet structured JSON (`grid_data`) or tabular CSV text back into standard OpenXML `.xlsx` binary packages using a pure-PHP PKZIP packager.

### 5. Verification Evidence
- Automated Verification Pipeline (`bash scripts/agent-verify.sh`):
  - PHP Syntax: OK
  - Composer Validation: OK
  - PHPStan Static Analysis (Level 8): **0 errors**
  - PHPUnit Suite: **725 tests passing, 2427 assertions, 0 failures, 0 errors**

---

## Unified UI Action Panel & View Mode Toggle — Release v0.7.1

> Requested as "Task 40". Released as `0.7.1` **after** `0.8.0`, at explicit request — see the version
> note at the end.

### 1. Unified bottom action bar

One `.panel-meta` container in `Template/file/modal_actions.php`, rendered by all five preview modals plus
the editor and the binary notice. The v0.8.0 top-right fullscreen button is gone.

The Fullscreen control changed shape: it is now an `<a class="fic-btn-fullscreen">` rather than a
`<button>`. It keeps a **real `href`** (the preview URL for the current view) so a middle-click or a
JavaScript-less browser still goes somewhere sensible, while `preview-controls.js` intercepts the click
and toggles `.fic-modal-fullscreen` in place. `target="_blank"` is deliberately *not* set on it — the
requirement's sample markup includes it, but for four of the five modals the preview route returns a bare
modal fragment, so a new tab would show unstyled HTML. PDF is the one case where a new tab genuinely
works (the stream is a real standalone document), so it keeps a separate "Open in new tab" link.

### 2. Removing the technical labels

Handler class names, the inline language badge (`BASH`) and all five "Safe …" boilerplate lines are gone.
`PreviewViewModeRegistry::getTypeLabel()` replaces them with friendly names — "PDF Document", "CSV Table",
"Spreadsheet" — and is written so it *cannot* return a class name: an unrecognised handler falls back to
the neutral "File". `testNeverReturnsAnInternalClassName` sweeps every handler × extension combination.

### 3. The view-mode toggle, and the trap in it

`view=raw` swaps the rich handler for `CodePreviewHandler`. The subtle part: **availability cannot be
keyed on the handler in use**, because after the swap that is always `CodePreviewHandler` — the toggle
would disappear the instant it was used, with no way back. `FilePreviewController` keeps
`$renderedHandlerName` from before the swap for exactly this. `testToggleRemainsAvailableInRawMode` locks
it down.

Two deliberate scope decisions:

- **Excel raw view.** An `.xlsx` is a ZIP, so its "source" is binary. Rather than hide the toggle (which
  the requirement asks for on Excel) or dump mojibake, a raw request whose bytes are binary answers with
  the existing "Binary File" notice plus a **Render** button back to the grid. That reuses
  `BinaryContentDetector`, so the branch is keyed on content rather than on the extension — a text-backed
  file still renders raw normally.
- **Diagrams.** The requirement lists them among rich formats. This plugin has no diagram renderer, so
  there is nothing to toggle. Noted in the CHANGELOG under "Not implemented"; the registry is the one
  place to add it later.

PDF, plain text and JSON get no toggle: the first has no text source, the others already *are* source.

### 4. Verification

**Live HTTP**, authenticated, all five modals:

```
                  bars  friendly label        handler-name / boilerplate leaks
.md   (file 4)      1   Markdown Modal        none
.pdf  (file 29)     1   PDF Document Modal    none
.csv  (file 33)     1   CSV Table Modal       none
.xlsx (file 27)     1   Spreadsheet Modal     none
.json (file 5)      1   JSON Modal            none

.md rendered -> fa-toggle-on,  link to view=raw,      Edit + Fullscreen + Download present
.md raw      -> fa-toggle-off, link to view=rendered, code-highlight present, zero <h1>
.xlsx raw    -> "Binary File (Preview not supported, click Download)" + fic-btn-render + view=rendered
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 694 / 694 (100%)  —  2292 assertions   (was 617; +77 new)
```

### 5. Pre-existing tests that changed meaning

Five encoded the old design and were rewritten, not deleted:

| Test | Was | Now |
|---|---|---|
| `testFullscreenToggleIsAButtonThatCannotSubmitAForm` | asserted `<button type="button">` | renamed `…IsALinkInTheActionBar`; asserts the anchor **and** that no `<button>` carries the toggle attribute |
| `testEditSwitcherIsOfferedForEditableFormats` | asserted the label "Edit File" | asserts "Edit" |
| `testPickerAlsoRendersInTheHighlightedCodeView` | asserted the `PYTHON` badge | asserts the badge is **absent** and the picker still shows "Python" |
| `testFullscreenLinkOpensInlineStreamNotDownload` | Fullscreen href == stream URL | split: `…OpenInNewTabLinkTargetsTheInlineStream` + `…FullscreenControlIsTheSharedInModalToggle` |
| `testMarkdownPreviewTemplateRendersCodeViewVariant` | asserted `SH` badge + "Safe Read-Only…" | asserts both are absent |

One over-broad assertion I added and then fixed: a blanket `assertStringNotContainsString('<button type="submit"')`
failed on the editor, which has a legitimate Save button. It now targets the toggle attribute specifically.

### 6. Version note

**This release is numbered below its predecessor.** `0.8.0` was published in the previous step; this work
is additive UI change, so under SemVer it belongs at `0.8.1` or `0.9.0`. `0.7.1` was requested explicitly
in two of the five requirements, so that is what shipped — but the history now reads 0.7.0 → 0.8.0 → 0.7.1,
and the `dist/` directory holds a `0.8.0` zip that is *older* than the `0.7.1` one. Renumbering is four
small edits (`Plugin.php`, the `PluginTest` assertion, the CHANGELOG heading, the README refs) plus a
repackage.

**Carried forward**: the manual browser pass is still outstanding, and this release changes the fullscreen
control from a button to a link — worth including in that pass.

---

## Task 43 — Release v0.8.0

> Requested as "Task 41". Roadmap Task 43.

```
Plugin.php::getPluginVersion()          -> 0.8.0
PluginTest version assertion            -> 0.8.0
CHANGELOG.md                            -> [0.8.0] - 2026-08-09 section; [Unreleased] now holds
                                           only the v0.9.0 and v1.0.0 plans
README.md                               -> badge, release heading, install paths, test count
dist/FileInteractionCore-0.8.0.zip      -> built by scripts/package-plugin.sh
```

`composer.json` deliberately carries no `version` field (it fails `composer validate --strict`), so
`Plugin.php::getPluginVersion()` stays the single source of truth the packaging script reads.

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 617 / 617 (100%)  —  1967 assertions
```

Archive contents verified: all five JS/CSS assets and all ten templates present, zero dev files
(`tests/`, `vendor/`, `scripts/`, `phpunit.xml`, `phpstan.neon` all excluded).

**Carried into this release**: the manual browser pass listed under Tasks 41 & 42 §7 is still outstanding.
Five scripts and one stylesheet added across Tasks 35-42 are verified by markup-contract tests and textual
pinning, never by execution. Worth completing before announcing the release publicly.

---

## Tasks 41 & 42 — In-Preview Edit Switcher & Modal Fullscreen

> Requested as "Task 40". These are roadmap Tasks 41 and 42 (Milestone 8).

### 1. Both controls live in one partial

`Template/file/modal_actions.php` is rendered by all six modal templates
(`preview`, `markdown_preview`, `csv_preview`, `pdf_preview`, `excel_preview`, `edit`), so the controls
exist once rather than six times.

### 2. The Edit switcher needs no JavaScript at all

`assets/js/components/modal.js` already delegates clicks on `.js-modal-medium` and calls
`KB.modal.replace()` when a modal is open. So the switcher is just:

```html
<a href="/b/7/task/3/file/42/edit" class="js-modal-medium fic-edit-switcher">Edit File</a>
```

Core does the seamless swap. Writing a handler for this would have been redundant work — worth checking
core's existing classes before reaching for a listener.

**Two gates, in two different places, on purpose:**

| Gate | Where | Why |
|---|---|---|
| Format is editable | Controller (`FileEditValidationService::EDITABLE_EXTENSIONS`) | Testable in isolation; single source of truth |
| User may mutate attachments | Template (`hasProjectAccess('TaskFileController', 'remove')`) | Needs Kanboard's user helper. `PermissionService` defaults to a permissive mock — real ACL is core middleware, so the template check is the honest one. Mirrors what `dropdown.php` already does. |

Project-overview attachments are excluded: `FileEditController` resolves through `taskFileModel` only, so
there is no editable target without a task id.

### 3. Fullscreen — why `!important` is unavoidable

`.fic-modal-fullscreen` is toggled on Kanboard's `#modal-box`, which core builds with
`.style('width', width)` — an **inline** style. Inline styles beat every stylesheet selector regardless
of specificity, so `Assets/css/preview.css` has to use `!important` for the width/height overrides. The
alternative (setting inline styles from JS) would mean storing and restoring each original value on
every toggle, which is more fragile.

The same applies to the per-template inline `max-height` on each scroll wrapper, which is sized for the
normal modal and has to grow in fullscreen.

Styles ship as a registered `template:layout:css` asset rather than an inline `<style>`. Inline CSS *is*
permitted by the CSP (`style-src` allows `'unsafe-inline'`), but an asset is cached instead of re-sent
with every modal.

### 4. Out-of-scope defect found and fixed: the live editor never worked

While adding the fullscreen button to `edit.php` I found its entire client-side layer shipped as an
inline `<script>` — the **same** double failure as the Excel tabs in Task 39:

1. CSP refuses inline blocks (`default-src 'self'`, no `script-src 'unsafe-inline'`).
2. Modal content is injected via `element.innerHTML` (`assets/js/core/dom.js:82`), and an injected
   `<script>` never executes.

Confirmed the editor is served as a bare fragment, so it is definitely innerHTML-injected:

```
GET /b/1/task/1/file/4/edit  ->  no <html>, starts with <div class="page-header">, 1 inline <script>
```

So since v0.5.0 the line and character counters never updated, the gutter never tracked scrolling, JSON
was never re-validated on input, and — worst — the form fell back to a plain POST, navigating the browser
to the **raw JSON body** of the update response instead of staying in the modal.

Extracted to `Assets/js/editor.js` with delegated listeners. Three details:

- Translated strings and the JSON-mode flag now travel as `data-*` attributes, since a static asset
  cannot call `t()`. The JSON labels are emitted only for `.json`, so a plain-text editor carries no JSON
  vocabulary at all.
- `scroll` does not bubble, so the gutter sync listener uses the **capture** phase.
- The form gained `js-modal-ignore-form`. Core's `getForm()` selects
  `#modal-content form:not(.js-modal-ignore-form)` and POSTs it, replacing the modal with the response
  body — which for this endpoint is JSON. Without the class, core and our own handler would both fire.

This was outside the requested scope. It is reported separately in `CHANGELOG.md` for that reason.

### 5. Verification

**Live HTTP**, authenticated:

```
assets                -> preview-controls.js 200, editor.js 200, preview.css 200
                         all three injected into the task page with cache-busting mtimes
.md preview  (file 4) -> data-fic-edit-switcher + Edit File + href="/b/1/task/1/file/4/edit"
                         + data-fic-fullscreen-toggle
.pdf preview (file 29)-> data-fic-fullscreen-toggle only, NO edit switcher
csv / excel previews  -> fullscreen toggle present
editor modal (file 5) -> 0 inline <script>, js-modal-ignore-form,
                         data-format="json", data-label-valid, data-label-error
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 617 / 617 (100%)  —  1967 assertions   (was 562; +55 new)
```

**Teeth confirmed** by mutation: removing the `hasProjectAccess()` call fails
`testEditSwitcherIsWithheldWithoutWritePermission`; renaming the fullscreen class fails
`testFullscreenScriptTogglesTheClassOnTheModalBox`.

### 6. Test-harness work this required

- `PluginTest`'s own `FakeTemplateHelper::render()` took a **file path**, but templates now call
  `$this->render('FileInteractionCore:file/partial', …)` — which lands on that same method. It now accepts
  both spellings, as `Kanboard\Core\Template::getTemplateFile()` does.
- `FakeTemplateRenderer` gained a `form` helper (the editor calls `$this->form->csrf()`).
- `syntaxStatusOf()` compared raw markup, so the new `data-label-invalid` attribute tripped a
  "not contains" assertion. It now compares the element's **visible text**, which is what the test meant.
- Two "no inline script" assertions kept flagging their own explanatory comments. `InspectsPhpSource`
  (shared trait) strips PHP comment tokens via `token_get_all()`, and JS comment lines, before asserting.

### 7. Open item — unchanged and now larger

**None of the client-side behaviour added across Tasks 35-42 has been confirmed in a browser.** That is
now five scripts and one stylesheet, all verified by markup-contract tests and textual pinning of the
shipped files, never by execution — no browser automation has been available in any of these sessions and
the `php:8.1-cli` container has no JS runtime.

The editor fix in §4 especially deserves a real click-through, since it changes a save path:

1. Open a `.json` attachment in the editor; type invalid JSON — the indicator should turn red live.
2. Save — it should stay in the modal and reload the page, **not** navigate to raw JSON.
3. Click **Edit File** from a `.md` preview — the modal should swap to the editor in place.
4. Click **⛶ Fullscreen** in each of the six modals — the box should fill the viewport with a sticky header.

---

## Tasks 39 & 40 — Excel Sheet Tab Fix & v0.7.0 Release

> Requested as "Task 38". Roadmap numbering ran one ahead from Task 36 onward because Task 35 closed both
> the orphan dropdown action and the PDF stream routing that roadmap Task 37 covered.

### 1. Symptom

Clicking a sheet tab in the `.xlsx` preview did nothing. No console error, no partial switch — the tab
simply did not respond.

### 2. Root cause — dead code twice over

The switcher was an inline `<script>` at the foot of `Template/file/excel_preview.php`. **The JavaScript
itself was correct**; it could never run, for two independent reasons:

1. **CSP refuses it.** `cspRules` is `default-src 'self'` and `script-src` inherits it without
   `'unsafe-inline'` (verified in Task 35: `Content-Security-Policy: default-src 'self'; style-src 'self'
   'unsafe-inline'; img-src * data:;` — no `script-src` at all).
2. **`innerHTML` never executes it.** Modal content is injected by
   `assets/js/core/dom.js:82` → `element.innerHTML = html`, and per the HTML spec a `<script>` inserted
   that way is not executed. So the listeners would not bind even under a permissive CSP.

Either reason alone is fatal, and both fail silently. This is the fourth task in Milestone 7 to trace to
the same class of defect, so `CLAUDE.md` lesson 17 was expanded to state both halves explicitly.

### 3. Fix

The logic moved into the already-registered `Assets/js/preview-controls.js`, joining the Task 36 language
picker and Task 38 CSV controls, with a **delegated** `click` listener — the tabs do not exist when the
asset runs.

Two robustness improvements went in with it:

| Before | After |
|---|---|
| Panels matched by DOM **ordinal** among `.fic-sheet-panel` | Panels carry `data-sheet-index` and are paired by **value** |
| `document.querySelectorAll` — global | Scoped to the clicked tab's own strip, so two previews cannot drive each other |

The badge is still updated with `textContent`, never `innerHTML`: sheet names arrive pre-escaped from
`ExcelPreviewHandler`, so assigning them as markup would double-unescape them back into live markup.

### 4. Verification

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 562 / 562 (100%)  —  1816 assertions   (was 545; +17 new)
```

`tests/Integration/ExcelSheetSwitchingTest.php` pins the markup contract the asset depends on (tabs and
panels paired by index, panels as siblings of the strip, exactly one visible panel) and guards the
regression directly — `testTemplateShipsNoInlineScript` fails if an inline block returns.

**Teeth confirmed**: deleting the delegated `click` registration fails
`testSwitcherUsesDelegatedClickListener`.

One test needed care rather than a code change: the template now documents the defect in prose that
necessarily names `<script>`, so the "no inline script" assertion strips PHP comment tokens via
`token_get_all()` before checking — otherwise it flags its own explanation.

Two pre-existing `PluginTest` assertions counted `data-sheet-index=` occurrences and were updated for the
new panel attribute (3 tabs + 3 panels = 6). They now count `role="tab"` / `role="tabpanel"`, which is
unambiguous — `fic-sheet-tab` also matches the `fic-sheet-tabs` container.

### 5. Release v0.7.0

```
Plugin.php::getPluginVersion()          -> 0.7.0
PluginTest version assertion            -> 0.7.0
CHANGELOG.md                            -> [Unreleased] split; [0.7.0] - 2026-08-09 section added
dist/FileInteractionCore-0.7.0.zip      -> built by scripts/package-plugin.sh
```

`composer.json` deliberately carries no `version` field (it fails `composer validate --strict`), so
`Plugin.php::getPluginVersion()` remains the single source of truth the packaging script reads.

### 6. Open item carried into the release

**The client-side behaviour added across Tasks 35–39 has not been confirmed in a browser.** No browser
automation was available in any of these sessions, and the `php:8.1-cli` test container has no JS runtime,
so all four scripts' DOM behaviour is verified by markup-contract tests plus textual pinning of the
shipped files — never by execution.

Worth one manual pass at `http://localhost:8085` (admin/admin) before announcing the release:

1. PDF renders inline with no fallback banner (Chrome and Firefox).
2. Orphan **View file** entry is gone for a PDF, and still present for an `.mp4`/`.svg`.
3. The syntax picker visibly re-renders the modal.
4. The CSV delimiter picker and header toggle re-render the table in place.
5. Excel sheet tabs switch panels.

Every server response behind those interactions is already proven by the traces in the sections below.

Fixture cleanup (Tasks 35–38 test attachments on task 1):

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name in (\"task35_probe.pdf\",\"LICENSE\",\"dump.bak\",\"bundle.zip\")
           or name like \"%_data.csv\"");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task3*'
```

---

## Task 38 — Dynamic CSV Delimiter Selector & Header Toggle

> Requested as "Task 37". The roadmap's Task 37 (PDF stream routing / fullscreen download redirect) was
> already closed by Task 35, so this is the CSV controls task — roadmap Task 38.

### 1. Scope

A delimiter picker (Auto-detect, Comma, Semicolon, Tab, Pipe) and a "First row is header" checkbox in
the CSV preview modal, both re-rendering the table without closing the modal.

### 2. Design — tokens, not characters

Delimiters travel as opaque **tokens** (`comma`, `semicolon`, `tab`, `pipe`, `auto`), never as their
literal characters. Two reasons:

1. A raw tab or pipe survives neither URL encoding nor HTML attribute escaping reliably.
2. Accepting a raw character would feed an arbitrary request value straight into `str_getcsv()`.

`CsvDelimiterRegistry` validates the token against an allow-list first, so exactly one of four known
characters can ever reach the parser. Anything unrecognised collapses to auto-detection.
`testTokenCharactersMatchTheParserCandidateList` pins the registry to
`CsvParserService::CANDIDATE_DELIMITERS`, so the picker can never offer a delimiter the sniffer would
never return.

Re-rendering reuses the Task 36 mechanism: each control carries the fully-built preview URL for the
state it would select, and `KB.modal.replace()` swaps the content in place. Parsing stays server-side
where every cell is already entity-escaped.

**The checkbox carries its *toggled* URL** (`data-fic-url`), so the server always renders the correct
next target and the script holds no state of its own. The `<select>` keeps URLs in its option values.
Both are driven by one delegated `change` listener.

### 3. Two details that would have made the feature feel broken

**Auto-detect has to stay selected while it is active.** The effective delimiter resolves to a concrete
token (`semicolon`), so keying the `selected` attribute on that value would make the control silently
jump off "Auto-detect" after the first render — with no way back to it. `selected` is keyed on
`delimiterMode` (what the user chose) while the resolved token is surfaced separately as
"Auto-detected: SEMICOLON".

**Header off must not leave a headerless blob.** The template previously always `array_shift`-ed the
first row into a `<thead>`. With the toggle off there would be no `<thead>` at all, breaking the sticky
row and the `#` gutter alignment. It now renders 1-based column indices instead, and every row stays in
the body.

### 4. Asset consolidation

`Assets/js/preview-language-selector.js` was renamed to `Assets/js/preview-controls.js` and generalized
to `[data-fic-language-select], [data-fic-csv-control]`. The alternative — a second near-identical file
duplicating the `KB.modal.replace()`/navigation-fallback logic — would have been worse. Adding another
in-modal reload control now means adding its attribute to that script's `SELECTOR`.

### 5. Verification

**Live HTTP**, authenticated, with multi-delimiter fixtures in task 1. The delimiter override genuinely
re-parses rather than relabelling:

```
semicolon_data.csv, auto            -> Auto-detected: SEMICOLON, badge ";", columns: id | name | role
semicolon_data.csv, delimiter=comma -> columns: "id;name;role"      (one unsplit column)
semicolon_data.csv, header=0        -> thead: 1 | 2 | 3             (first row moved to the body)
pipe_data.csv,      delimiter=pipe  -> badge "|",   columns: id | name | role
tab_data.csv,       delimiter=tab   -> badge "TAB", columns: id | name | role
```

Controls and their URLs render as designed, and the parameter is not injectable:

```
data-fic-csv-control="delimiter"   data-fic-csv-control="header"   checked="checked"
data-fic-url=".../file_id=33&delimiter=auto&header=0"    <- toggled target while header is ON
delimiter=" onmouseover="alert(1)  -> rejected, falls back to auto-detect,
                                      no onmouseover in the response
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 545 / 545 (100%)  —  1764 assertions   (was 485; +60 new)
```

**The regression tests have teeth.** Neutralising the delimiter override
(`resolveDelimiter($requestedToken)` → `null`) fails
`testChoosingSemicolonSplitsWhereCommaWouldNot`; forcing `$showHeaderRow = true` fails
`testHeaderOffKeepsEveryRowAsData`.

One false positive was worth fixing in the test rather than the code: asserting a rejected token is
absent from the HTML tripped on `colon` being a substring of the `Semicolon` label. The assertion now
matches the `delimiter=` parameter itself.

### 6. Open item

Same boundary as Tasks 35 and 36: the controls' DOM behaviour is pinned textually, not executed — the
`php:8.1-cli` container has no JS runtime. **Not confirmed in a browser:** that changing the delimiter
or ticking the checkbox visibly re-renders the table in place. Every URL those controls carry is proven
to return the correct table by the traces above.

Remove the Task 38 fixtures when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name like \"%_data.csv\"");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task37_*'
```

---

## Task 36 — Dynamic Language Selector & Unknown Extension Handling

### 1. Scope

Two features: a syntax language picker in the Safe Preview modal header that switches highlighting on
the fly, and content-based classification for attachments whose extension the whitelist cannot place.

### 2. Design decision — switching happens server-side

The picker reloads the modal with a `lang=<id>` parameter rather than re-highlighting in the browser.
`assets/js/core/modal.js` already exposes `KB.modal.replace(url)` publicly, so an in-place content swap
costs nothing, and the tokenizer stays in PHP where the payload is already entity-escaped. The
alternative — porting `CodePreviewHandler::highlightSyntax()` to JavaScript — would mean two copies of
an XSS-sensitive code path drifting apart.

The `<select>` change handler ships as `Assets/js/preview-controls.js` registered on
`template:layout:js`, for the same CSP reason as Task 35: `default-src 'self'` with no
`script-src 'unsafe-inline'` means an inline handler is silently blocked.

`SyntaxLanguageRegistry` is the single source of truth for the option list, the per-extension default,
and — importantly — the per-language comment prefixes and keyword sets. Without that last part,
switching language would only change a CSS class and a badge; the feature would look implemented while
doing nothing.

### 3. Two implementation traps worth recording

**`resolveHandler()` will not force a handler onto an unsupported extension.**
Routing an explicit language choice through `format=code` silently produced `TextPreviewHandler` when
highlighting a `.txt` as Python: `FileInteractionManager::resolveHandler()` still calls `supports()`
unless the format is literally `text`/`raw`, and `CodePreviewHandler` declines `.txt`. Caught by
`testExplicitLanguageOverridesTheExtensionDefault`. An explicit choice now selects the handler by name
through `findHandlerByName()`.

**`.json` does not reach `CodePreviewHandler`.**
`JsonPreviewHandler` is registered ahead of it, so gating the picker on `[Code, Text]` disabled it for
exactly the format most likely to want it. The gate is now `[Code, Text, Json]`. Markdown is
deliberately excluded — its output is sanitized HTML, so a language selection would misrepresent what
is on screen.

### 4. Unknown extensions — and the memory bound they exposed

`BinaryContentDetector` inspects an 8 KB window for NUL bytes, a control-character ratio above 10%,
and invalid UTF-8. Text gets an escaped preview plus the picker; binary gets
`Template/file/binary_notice.php`, which renders **none** of the payload — only metadata.

Allowing arbitrary extensions through surfaced a latent DoS: `objectStorage->get()` buffers the entire
file before any size cap is consulted, so a 500 MB upload was suddenly a valid preview target.
`FilePreviewController::CONTENT_READ_CEILING_BYTES` now skips the read entirely based on the
attachment row's declared `size`, and that declared size also keeps `validateFileSize()` honest when
content was never loaded (otherwise an oversized file would have previewed as an empty buffer).

**Core-owned media stays rejected.** `FileValidationService::CORE_MEDIA_EXTENSIONS` excludes
images/audio/video from inspection: core already renders working viewers for them, and it guarantees no
URL can route active content (`svg`) into a preview path. This also preserves Task 35's dropdown
scoping — the `fic-safe-preview` marker is withheld for unclassified extensions, since core renders no
view action for them and there is no orphan to clean.

### 5. Verification

**Live HTTP**, authenticated, with fixtures inserted into task 1:

```
LICENSE     (no extension) -> Detected Text badge + picker
dump.bak    (unknown ext)  -> Detected Text badge + picker
bundle.zip  (binary)       -> "Binary File (Preview not supported, click Download)"
                              + "No file content was rendered", reason: null bytes
```

Switching language genuinely changes the tokenisation, not just a label:

```
file 31 default   -> TextPreviewHandler                     (escaped plain text)
file 31 lang=sql  -> CodePreviewHandler, language-sql,
                     tok-comment (--), tok-keyword (SELECT/FROM)
file 31 lang=json -> language-json, NO tok-comment          (JSON has no comment syntax)
```

Extension defaults resolve correctly, and the `lang` parameter is not injectable:

```
.env        -> lang=config selected      deploy.yml -> lang=yaml selected
*.json      -> lang=json selected
lang=<script>alert(1)</script> -> discarded, falls back to the extension default,
                                  no <script> in the response
asset       -> 200, injected as <script defer src=".../preview-controls.js?<mtime>">
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 485 / 485 (100%)  —  1556 assertions   (was 351; +134 new)
```

### 6. Pre-existing tests that changed meaning

Three asserted the pre-Task-36 contract and were rewritten, not deleted:

| Test | Was | Now |
|---|---|---|
| `testShowStillRejectsDisallowedBinaryFormats` | `.zip`/`.exe`/`.docx` throw `InvalidFileException` | renamed `...ServesBinaryNoticeFor...`; asserts `BinaryNotice`, `isBinary`, and that **no bytes** are rendered |
| `testRendersErrorModalInsteadOfThrowingForDisallowedExtension` | `.docx` renders the error modal | renamed `...RendersBinaryNoticeFor...`; a sibling test keeps the error-modal assertion for core media |
| `testNoMarkerForFormatsSafePreviewDoesNotHandle` | `.docx`/`.zip`/`LICENSE` get no Safe Preview entry | split: core media gets **no entry**, unclassified gets an **entry without a marker** |

The old fixtures used ASCII payloads (`'PK binary payload'`, `'binary'`) that now correctly classify as
text; the replacements use real binary headers.

### 7. Open item

As with Task 35's cleanup script, the picker's DOM behaviour is pinned textually rather than executed —
the `php:8.1-cli` container has no JS runtime. **Not yet confirmed in a browser:** that changing the
`<select>` visibly swaps the modal content. The server side of that round trip is proven by the traces
above (every option URL returns correctly-highlighted HTML).

Remove the Task 36 fixtures when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name in (\"LICENSE\",\"dump.bak\",\"bundle.zip\")");'
docker exec kanboard_test_instance sh -c 'rm -f /var/www/app/data/files/tasks/1/fixture_task36_*'
```

---

## Task 35 — PDF Stream Routing Fix & Orphan Action Cleanup

### 1. Symptoms

1. PDF attachments opened the modal but showed the *"Inline PDF viewing is not supported by your
   browser or plugin"* fallback banner instead of the document — on current Chrome and Firefox.
2. The modal's **Open Fullscreen / Download** control triggered a save dialog rather than showing
   the PDF.
3. The attachment dropdown listed a redundant core **View file** action next to **Safe Preview**.

### 2. Root cause — the inline banner was never a URL problem

Task 23/24 had already pointed `<object data>` at core's inline `browser` action, and that action is
correct: `FileViewerController::browser()` resolves the MIME type through
`FileHelper::getBrowserViewType()`, which returns `application/pdf` for `.pdf`.

The blocker is a **response header**. `BootstrapMiddleware::sendHeaders()` runs for every request and
queues `X-Frame-Options: DENY` on the shared `Response` singleton whenever `ENABLE_XFRAME` is on —
and `app/constants.php:128` defaults it to `true`. Every core response therefore carries it,
including the PDF byte stream. Browsers render an embedded PDF inside a nested browsing context, so
`DENY` aborts that navigation and `<object>` falls through to its child content.

Confirmed live against `kanboard/kanboard:v1.2.37` on an authenticated session (file_id 29):

```
GET /?controller=FileViewerController&action=browser&task_id=1&file_id=29
HTTP/1.1 200 OK
Content-Type: application/pdf          <- correct
X-Frame-Options: DENY                  <- blocks <object> from rendering it
Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src * data:;
```

A static asset returns **no** framing headers, proving they come from PHP rather than nginx — so a
plugin-owned response can control them:

```
GET /assets/css/print.min.css   -> no X-Frame-Options, no Content-Security-Policy
```

No `data` attribute can work around a response header, and core must not be patched. Hence a
plugin-owned stream route.

### 3. Root cause — the orphan entry cannot be removed server-side

`app/Template/task_file/files.php` renders its own view `<li>` at line 16-25 and only calls
`$this->hook->render('template:task-file:documents:dropdown', …)` at **line 34** — our hook output is
appended *after* core's entry, inside the same `<ul>`. There is no core-free way to suppress it from
PHP, so it must be pruned in the DOM.

Two constraints shaped that fix:

- **Inline `<script>` is dead on arrival.** `cspRules` (`app/ServiceProvider/ClassProvider.php:185`)
  is `default-src 'self'`, and `script-src` falls back to it — no `'unsafe-inline'`. (`style-src`
  *does* allow inline, but CSS alone cannot scope the fix; see below.) The cleanup therefore ships as
  a served asset registered on `template:layout:js`.
- **The fix must be per-file.** Core also offers *View file* for formats Safe Preview does **not**
  handle — `mp3 ogg flac wav avi webm mov m4v mp4 svg` — and those entries are the only way to open
  them. A blanket rule keyed on the href would wrongly hide them, so cleanup is gated on a marker
  `<li>` that the dropdown template emits only for formats this plugin claims.

### 4. Fixes applied

| File | Change |
|---|---|
| `src/Controller/FileStreamController.php` | **New** — `inline` action streaming allow-listed binaries. Drops `X-Frame-Options` and replaces it with `default-src 'none'; frame-ancestors 'self'`. Gates on ACL, a `pdf`-only allow-list, the 10 MB cap, and a `%PDF` magic-byte check. |
| `src/Core/Contract/StreamEmitterInterface.php` | **New** — injectable header/body sink, so the emitted header set is assertable. Needed because Kanboard's `Response` cannot unset a queued header, and `Response::send()` would re-emit `DENY`. |
| `src/Service/HttpStreamEmitter.php` | **New** — production emitter over `header()` / `header_remove()`, guarded by `headers_sent()`. |
| `Plugin.php` | Registered the `/b/:project_id/task/:task_id/file/:file_id/stream` route and the `template:layout:js` asset hook. |
| `Template/file/pdf_preview.php` | `<object data>` now targets the plugin stream route. Split the combined *Open Fullscreen / Download* link into a **Fullscreen** action (inline stream) and a **Download** action (core `download`). |
| `Template/file/dropdown.php` | Safe Preview `<li>` now carries `class="fic-safe-preview" data-fic-ext="…"` as the cleanup gate. |
| `Assets/js/dropdown-cleanup.js` | **New** — removes sibling `<li>`s linking to core `action=show`/`action=browser` within the marker's own `<ul>`. Leaves Download, Remove, and unmarked dropdowns untouched. Re-runs on DOM mutation for ajax-rendered tables. |
| `scripts/agent-verify.sh` | Added `--memory-limit` to PHPStan. `php:8.1-cli` defaults to 128 M, which the grown `src/` now exceeds — it surfaced as an opaque `Child process error (exit code 255)`. |
| `tests/stubs/FakeTemplateRenderer.php` | **New** — renders plugin templates the way `Core\Template::render()` does, with a `FakeUrlHelper` that reproduces `Route::findUrl()` matching rules faithfully. |

### 5. Verification

**Live HTTP** — the new route, authenticated, against the running instance:

```
GET /b/1/task/1/file/29/stream
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: inline; filename="task35_probe.pdf"
Content-Security-Policy: default-src 'none'; frame-ancestors 'self'
X-Content-Type-Options: nosniff
Cache-Control: private, max-age=300
                                       <- X-Frame-Options absent (was DENY)
body: %PDF-1.4...                      <- correct bytes
```

Boundaries hold:

```
unauthenticated            -> 302 /login                (core middleware, no leak)
file 2 (.html) via stream  -> 400 text/plain "File extension ".html" cannot be streamed inline"
asset                      -> 200 application/javascript
task page                  -> <script defer src="/plugins/.../dropdown-cleanup.js?1786286714">
markers emitted            -> pdf×2 md×3 csv×2 json×2 xlsx×2 yml×2 txt env html  (none for .docx/.zip)
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 350 / 350 (100%)  —  1157 assertions   (was 280; +70 new)
```

Two pre-existing tests in `tests/Integration/PluginTest.php` asserted the old behaviour
(`action=browser` in `<object data>`, and `project_id` omitted entirely) and were rewritten to the
corrected contract — the stream route needs all three params present or `Route::findUrl()` cannot
match and the URL silently degrades to a query string.

**The regression tests have teeth.** Restoring the old `<object data>` target fails 7 tests:

```
PdfPreviewTemplateTest::testObjectStreamsThroughPluginStreamRoute
PdfPreviewTemplateTest::testObjectDoesNotUseCoreFileViewerController
PdfPreviewTemplateTest::testFullscreenLinkOpensInlineStreamNotDownload
PdfPreviewTemplateTest::testStreamUrlIsGeneratedWhenProjectIdIsZero
PdfPreviewTemplateTest::testStreamUrlFallsBackToQueryStringCarryingPluginParam
PluginTest::testPdfPreviewTemplateEmbedsInlineStreamActionNotDownload
PluginTest::testPdfPreviewTemplateOmitsUnknownProjectIdOnDownloadAction
```

### 5b. Coverage boundary on the JavaScript

Worth recording, because the first attempt at this was misleading: gutting `entry.remove()` from
`Assets/js/dropdown-cleanup.js` initially failed **zero** tests.
`tests/Integration/DropdownCleanupTest.php` asserts the removal rules against the real assembled
markup, but it does so through a **PHP transcription** of the script's logic — so it verifies the
rules, not the shipped file. `testCleanupPredicateMatchesTheShippedScript` binds the href predicate
to the real script, and `testCleanupScriptStillPerformsTheRemoval` now pins the operations the script
must perform (verified to fail when `entry.remove()` is removed).

Executing the real script would need a JS DOM runtime, which the `php:8.1-cli` test container does not
provide. **The script's live DOM behaviour is therefore covered by transcription plus textual pinning,
not by execution** — the manual browser pass below is what closes it.

### 6. Open item

The header-level cause and fix are proven by the traces above, but **two things still need a real
browser** — no browser automation was available in this session:

1. That the PDF renders inline with no fallback banner (Chrome and Firefox).
2. That the orphan **View file** entry actually disappears from the dropdown, while it survives for an
   `.mp4`/`.svg`/`.mp3` attachment.

Manual pass at `http://localhost:8085` (admin/admin); task 1 has a `task35_probe.pdf` fixture
inserted for exactly this.

Remove the fixture when finished:

```bash
docker exec kanboard_test_instance php -r '$db = new PDO("sqlite:/var/www/app/data/db.sqlite");
$db->exec("delete from task_has_files where name = \"task35_probe.pdf\"");'
docker exec kanboard_test_instance rm -f /var/www/app/data/files/tasks/1/fixture_task35_probe.pdf
```

---

## v0.4.0 — PDF Viewer Release Verification (embedded `<object>` would never have rendered)

### 1. Symptom (caught pre-release, never shipped)

Task 23 wired the PDF modal's `<object data>` to Kanboard core's `FileViewerController::download`.
Static review during Task 24 release verification flagged it before packaging.

### 2. Root cause

`download()` (`app/Controller/FileViewerController.php:146`) calls
`$this->response->withFileDownload($file['name'])`, which sets `Content-Disposition: attachment`.
No browser renders an attachment-disposition response inside an `<object>` — it opens a save
dialog instead, so spec 004 **AC-2** ("modal displays the embedded PDF document cleanly") could
never have passed.

Core's inline counterpart is `browser()`, which streams through
`FileHelper::getBrowserViewType($filename)` — that returns `application/pdf` for `.pdf`.

Confirmed live against `kanboard/kanboard:v1.2.37`, file `v2-pharma-parser.pdf` (file_id 12):

```
action=browser   -> HTTP 200 | Content-Type: application/pdf              | body starts %PDF-
action=download  -> HTTP 200 | Content-Type: application/octet-stream
                            | Content-Disposition: attachment; filename="v2-pharma-parser.pdf"
```

### 3. Fixes applied

| File | Change |
|---|---|
| `Template/file/pdf_preview.php` | Split the single URL into `$inlineUrl` (`browser`, used by `<object data>`) and `$downloadUrl` (`download`, used by the two fallback links). |
| `tests/Integration/PluginTest.php` | 5 new tests: inline-vs-download URL targeting, `rel="noopener noreferrer"` fallback, filename escaping, `project_id` omission, `.pdf` in the dropdown whitelist. |
| `tests/Integration/PluginTest.php` | `FakeUrlHelper` stub + `FakeTextHelper::bytes()` ported verbatim from core (bare suffixes: `2M`, not `2 MB`). |
| `composer.lock` | Re-hashed via `composer update --lock --no-install`; the `version` bump invalidates `content-hash` and fails `composer validate --strict`. |
| `dist/FileInteractionCore-0.3.0.zip` | Restored from git — a prior packaging run had rebuilt it with Milestone 4 code still labelled 0.3.0. |

### 4. Verification

**Live HTTP**, authenticated web session against the running instance:

```
HTTP 200 |   2190 bytes | v2-pharma-parser.pdf  (PdfPreviewHandler, size badge "1.01M")
  <object data="/?controller=FileViewerController&amp;action=browser&amp;task_id=1&amp;file_id=12&amp;project_id=1"
          type="application/pdf">
```

`sizeBytes` resolves to real content (1.01M badge), confirming the 10 MB cap is enforced against
actual bytes rather than an empty buffer.

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 186 / 186 (100%)  —  582 assertions
```

### 5. Known limitation carried into the release

Spec 004 **AC-3** asks for `sandbox="allow-same-origin allow-scripts"`, but `sandbox` is an
`<iframe>`-only attribute — `<object>` does not support it. Containment currently relies on the
browser's built-in PDF viewer plus `rel="noopener noreferrer"` on outbound links. Migrating the
container to a sandboxed `<iframe>` is the open follow-up.

---

## v0.1.1 — Browser Runtime Repair (Safe Preview returned an empty modal)

### 1. Symptom

Clicking **Safe Preview** on `.json`, `.html`, `.yml`, `.env` and `.txt`/`.md` attachments opened an
empty modal. The endpoint did **not** 500 — it answered `HTTP 200` with a **zero-byte** body, which is
why nothing surfaced in the error log and the modal simply rendered blank.

```
$ curl -s -b cookies -w '[%{http_code} | %{size_download}]' \
    "http://localhost:8085/?plugin=FileInteractionCore&controller=FilePreviewController&action=show&file_id=5&task_id=1&project_id=1"
[200 | 0]
```

### 2. Root cause

`Kanboard\Core\Base` (`app/Core/Base.php`) resolves every service through `__get()`:

```php
public function __get($name)
{
    return $this->container[$name];
}
```

It does **not** implement `__isset()`. PHP routes `isset($obj->prop)` through `__isset()`, *not*
`__get()` — so for any undeclared property, `isset()` returns `false` regardless of whether the
service exists. Proven directly against the running container:

```
isset(request)  => bool(false)
isset(response) => bool(false)
isset(template) => bool(false)
isset(container)=> bool(true)     // real declared property
```

`FilePreviewController::show()` was gated entirely on those checks:

| Guard | Intended | Actual | Consequence |
|---|---|---|---|
| `isset($this->request)` | read HTTP params | always `false` | `file_id`/`task_id`/`project_id` stayed `0` |
| `$this->container->offsetExists('taskFileModel')` | load attachment row | never reached (`$fileId === 0`) | no filename, no content |
| `isset($this->response) && isset($this->template)` | render HTML | always `false` | returned a PHP **array**; nothing echoed → 0 bytes |

So the controller degraded to previewing a nonexistent `attachment.txt` with empty content, then
returned an array that Kanboard discarded — a silent `200`/empty response.

### 3. Fixes applied

| File | Change |
|---|---|
| `src/Controller/FilePreviewController.php` | Added `hasService()`, which probes the Pimple container via `ArrayAccess` instead of `isset()`. Replaced all six dead guards. |
| `src/Controller/FilePreviewController.php` | `AccessDeniedException` / `InvalidFileException` are now caught in the HTTP context and rendered as a clean modal (`403` / `400`) instead of bubbling into a Kanboard error page. |
| `src/Controller/FilePreviewController.php` | Added `projectFileModel` support (`source=project`) and `taskFinderModel` project-id resolution for direct route access. |
| `Template/file/preview_error.php` | **New** — escaped error modal. |
| `Template/file/dropdown.php` | Fixed undefined `$task` on the project-overview hook (it passes `project` + `file`, never `task`); corrected the modal icon from `icon-eye` to `eye`; removed a duplicate `<i>` element. |
| `Plugin.php` | Removed the attach to `template:project-overview:images:dropdown` — no such hook exists in Kanboard core. |
| `tests/stubs/BaseController.php` | Now faithfully mirrors core: `__get()` **without** `__isset()`, so the runtime defect is reproducible in tests. |
| `tests/stubs/FakeContainer.php` | **New** — `ArrayAccess` container plus request/response/template/model/storage fakes. |
| `tests/Unit/FilePreviewControllerRuntimeTest.php` | **New** — 7 regression tests covering the HTTP runtime path. |

### 4. Verification

**Regression tests have teeth** — restoring the buggy `isset()` semantics fails 6 of the 7 new tests:

```
Tests: 42, Assertions: 128, Failures: 6
- 'FileInteractionCore:file/preview' vs ''   (nothing rendered)
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 44 / 44 (100%)  —  145 assertions
```

**Live HTTP, post `docker compose restart`**, against the pretty route the browser actually uses:

```
HTTP 200 |    8181 bytes | bons_de_livraison_example_output.json   (JsonPreviewHandler)
HTTP 200 |   62946 bytes | verox_vlm_optimization_reference.html   (TextPreviewHandler)
HTTP 200 |    1558 bytes | .env                                    (TextPreviewHandler)
HTTP 200 |   11780 bytes | deploy.yml                              (TextPreviewHandler)
HTTP 200 |   37517 bytes | compass_artifact_..._text_markdown.md   (TextPreviewHandler)
HTTP 200 |   25776 bytes | bons_de_livraison_template.json         (JsonPreviewHandler)
HTTP 200 |   38385 bytes | minimaxv2.md                            (TextPreviewHandler)
HTTP 200 |  107397 bytes | Pharma_Document_Extraction_Solution_v2.md
```

Error paths degrade cleanly instead of throwing:

```
HTTP 400 | clean error modal | .docx  (extension not allowed)
HTTP 400 | clean error modal | .pdf   (extension not allowed)
```

**XSS containment** on the 45 KB `.html` attachment: 414 escaped `&lt;` markers, `0` raw `<script`,
and exactly `3` raw `<div` — our own wrapper elements. Container log sweep: **0** PHP
warnings/notices/fatals.

### 5. Known gotcha for the next session

`docker compose restart` re-runs the Kanboard entrypoint, which executes
`chown -R nginx:nginx /var/www/app/plugins`. Because this workspace is bind-mounted there, every
restart silently re-owns it to `100:101` and host edits fail with `EACCES`. Re-run:

```bash
docker run --rm -v $(pwd):/work alpine chown -R 1000:1000 /work
```
