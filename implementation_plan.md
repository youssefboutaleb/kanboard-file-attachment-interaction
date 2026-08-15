# Implementation Plan: Fix Edit System Across All File Types, In-Browser Spreadsheet Editor for XLSX/CSV, and Standalone Sheet Switching

Fix the edit/save/cancel workflow across all file formats, implement an interactive in-browser spreadsheet grid editor for Excel (`.xlsx`, `.xls`) and CSV (`.csv`, `.tsv`), and fix Excel sheet tab switching in standalone/new-tab mode.

## User Review Required

> [!IMPORTANT]
> - **Edit System Fix**: Currently, form submissions fail because `FileEditController::update()` was reading from `$_GET` (`getStringParam`) instead of `$_POST` (`getValue`), and checking CSRF against query parameters. We will fix this to read from `$_POST` and properly validate CSRF tokens, restoring working save/cancel functionality across all file types.
> - **Interactive Excel/CSV Spreadsheet Editor**: For `.xlsx`, `.xls`, `.csv`, and `.tsv`, the editor modal will present an interactive grid/table editor with column headers (`A`, `B`, `C`), row numbers (`1`, `2`, `3`), formula bar, editable cells, "Add Row"/"Add Column" toolbar, and sheet tabs, rather than raw text.

---

## Proposed Changes

### 1. Fix `FileEditController` Backend (Save / Cancel / CSRF / Excel Conversion)

#### [MODIFY] [FileEditController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FileEditController.php)
- Fix `update()`:
  - Read `content`, `mode`, `csrf_token`, `file_id`, `task_id`, `project_id` from `$this->request->getValue(...)` (`$_POST`) with fallback to route params.
  - Validate CSRF token using `$this->token->validateCSRFToken(...)`.
  - For `.xlsx` / `.xls`: Convert submitted grid/CSV data to valid OpenXML `.xlsx` bytes via `ExcelWriterService` before persisting.
  - Return JSON response `{ success: true, message: 'Saved successfully' }`.
- Fix `edit()`:
  - For `.xlsx` / `.xls`: Parse workbook sheets and rows into structured grid data (JSON) as well as CSV text, passed to the template.

---

### 2. Interactive Spreadsheet Grid Editor & Live Text Editor

#### [MODIFY] [Template/file/edit.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/edit.php)
- Add spreadsheet editor mode when editing `.xlsx`, `.xls`, `.csv`, `.tsv`:
  - **Formula / Cell Bar**: Displays active cell reference (`A1`) and inline editor.
  - **Grid Toolbar**: "Add Row", "Add Column", "Remove Row".
  - **Sheet Tabs**: For multi-sheet Excel workbooks.
  - **Editable Table Grid**: Excel-like spreadsheet table with sticky column headers (`A`, `B`, `C`...) and row numbers (`1`, `2`, `3`...).
- For code/text files (`.txt`, `.json`, `.md`, `.html`, `.py`, `.js`, etc.):
  - Render the full monospace live text editor with syntax status and line gutter.
- Fix Cancel button:
  - Replace `.close-popover` with `class="js-modal-close btn btn-link fic-edit-cancel"`.

#### [MODIFY] [Assets/js/editor.js](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Assets/js/editor.js)
- Fix form submission (`onSubmit`):
  - Send `FormData(form)` with `X-Requested-With: XMLHttpRequest`.
  - On success: Show save feedback, close modal via `KB.modal.close()` and reload/update.
  - On failure: Display alert banner with server error message.
- Implement Spreadsheet Grid Editor logic:
  - Cell click and editing (Enter, Tab, Arrow key navigation).
  - Add Row, Add Column, Remove Row operations.
  - Synchronize grid data to hidden form fields on change and before submission.
- Fix Cancel button click handler:
  - Close modal via `window.KB.modal.close()`.

---

### 3. Fix Standalone XLSX Sheet Tab Switching

#### [MODIFY] [Assets/js/preview-controls.js](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Assets/js/preview-controls.js)
- Fix `onSheetTabClick`:
  - Scope sheet tab pairing to `.fic-sheet-container` / `.fic-sheet-tabs` sibling root rather than assuming modal-only DOM hierarchy.
  - Prevent default action and update active tab styles and active sheet panel visibility.

#### [MODIFY] [Template/file/excel_preview.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/excel_preview.php)
- Wrap sheet tabs and panels inside a dedicated `<div class="fic-sheet-container">` container.

---

## Verification Plan

### Automated Tests
- Run full verification pipeline:
  ```bash
  bash scripts/agent-verify.sh
  ```
- Run PHPUnit test suite:
  ```bash
  docker run --rm -v $(pwd):/app -w /app php:8.1-cli vendor/bin/phpunit
  ```
- Unit tests:
  - `tests/Unit/FileEditControllerTest.php`: Test POST update with `$_POST` body, CSRF token validation, spreadsheet save, and cancel.
  - `tests/Unit/ExcelWriterServiceTest.php`: Test multi-sheet and row grid serialization to `.xlsx`.
  - `tests/Integration/EditorScriptTest.php`: Test spreadsheet grid editor markup and text editor markup.

### Manual Verification
- Test at `http://localhost:8085` (admin/admin):
  1. Open `.xlsx` in Safe Preview, click "Open in new tab", and switch sheet tabs.
  2. Click "Edit" on `.xlsx` attachment -> opens interactive spreadsheet grid editor with sheets/columns/rows. Edit cells and click "Save Changes".
  3. Click "Edit" on `.csv` attachment -> edit cells/table, save changes.
  4. Click "Edit" on `.txt` / `.md` / `.json` / `.html` -> edit text, click Cancel (modal closes), edit text, click Save Changes (saves and refreshes).
