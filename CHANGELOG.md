# Changelog

All notable changes to `kanboard-file-interaction-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
