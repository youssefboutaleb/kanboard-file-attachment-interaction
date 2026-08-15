# Functional Specification: 006 - Excel Spreadsheet Interactive Preview Engine

**Feature Target**: Milestone 6 — Excel Spreadsheet Read-Only Interactive Viewer  
**Document Status**: Proposal / Specification  
**Author**: Security & Engineering Team  
**Date**: 2026-08-08  

---

## 1. User Story & Summary

**User Story**:  
As a Kanboard team member or project manager, I want to preview Excel spreadsheet attachments (`.xlsx`, `.xls`) directly inside a Kanboard modal window with multi-sheet tab navigation and responsive grid layout, so that I can inspect data tables and financial models without opening Excel locally.

---

## 2. Scope

- **Supported Formats**: `.xlsx`, `.xls`.
- **Supported MIME Types**:
  - `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (`.xlsx`)
  - `application/vnd.ms-excel` (`.xls`)
- **Multi-Sheet Tab Navigation**: Modal interface allowing switching between multiple sheets (`Sheet 1`, `Sheet 2`, etc.).
- **Interactive Grid Template (`Template/file/excel_preview.php`)**:
  - Spreadsheet column headers (`A`, `B`, `C`, ...).
  - Row index column (`1`, `2`, `3`, ...).
  - Cell-level HTML entity escaping (`htmlspecialchars()`) preventing XSS.
  - Active sheet badge, row/column count metadata, and cell truncation warnings.
- **Parsing Service (`ExcelParserService`)**: Lightweight XML/ZIP parser for `.xlsx` structure extracting worksheet sheets, shared strings, and cell values.
- **File Validation & ACL**: Capped size limit of 5 MB for spreadsheet attachments with path sanitization and write/read ACL checks.

---

## 3. Non-Goals

- **NO Spreadsheet Formula Calculation Engine**: Displays formatted cell values or string values; does NOT execute arbitrary VBA macros or embedded external web requests.
- **NO Macro Execution**: Embedded VBA code / macros in `.xlsm` files will NOT execute.

---

## 4. Acceptance Criteria

1. **AC-1 (Format Resolution)**:
   - Requesting preview for `.xlsx` or `.xls` resolves to `ExcelPreviewHandler`.
2. **AC-2 (Multi-Sheet Tab Navigation)**:
   - Multi-sheet workbooks display sheet tabs, allowing users to toggle between worksheets.
3. **AC-3 (Cell Security & XSS Escape)**:
   - Every cell value is entity-escaped (`htmlspecialchars()`) before rendering into the DOM table grid.
4. **AC-4 (Performance Capping)**:
   - Previews are capped to first 100 rows and 50 columns per sheet with truncation notice banners.

---

## 5. Security & Performance Constraints

- **Cell Value Sanitization**: `htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **Memory Ceiling**: Capped at 5 MB file size and max 100 rows per sheet to remain under 8 MB memory usage.

---

## 6. Definition of Done

- [ ] `docs/specs/006-excel-spreadsheet-preview.md` created and approved.
- [ ] Implementation plan created and approved.
- [ ] `ExcelParserService` and `ExcelPreviewHandler` implemented and unit tested.
- [ ] `Template/file/excel_preview.php` modal template view created.
- [ ] `FileValidationService`, `FileInteractionManager`, and `FilePreviewController` updated.
- [ ] `bash scripts/agent-verify.sh` passes 100% (PHPStan Level 8 clean).
- [ ] `CLAUDE.md` and `walkthrough.md` updated.
