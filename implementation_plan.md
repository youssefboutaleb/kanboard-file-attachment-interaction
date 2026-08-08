# Implementation Plan - Milestone 6: Excel Spreadsheet Interactive Preview Engine

We will implement **Milestone 6 (Excel Spreadsheet Interactive Preview Engine)** as specified in [docs/specs/006-excel-spreadsheet-preview.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/docs/specs/006-excel-spreadsheet-preview.md).

---

## 🏗️ Architecture & Security Design

- **Lightweight OpenXML Parser (`ExcelParserService`)**: Memory-safe `.xlsx` ZIP/XML parser extracting shared string dictionaries and worksheet cell values without heavy 3rd-party dependencies.
- **Multi-Sheet Tab Navigation**: Allows switching between workbook sheets with responsive tab bar rendering.
- **Strict Cell-Level XSS Sanitization**: Wraps all string cell values in `htmlspecialchars()` to prevent XSS DOM injection.
- **Per-Format Size Capping**: Dedicated 5 MB file size limit for spreadsheet files.

---

## 📋 Task Breakdown for Milestone 6

### Task 30: Excel Spreadsheet Parsing Service (`ExcelParserService`)
- **Goal**: Create service parsing `.xlsx` OpenXML files, extracting sheet names, shared strings, and cell data matrix.
- **Files to Create/Modify**:
  - `[NEW]` [src/Service/ExcelParserService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/ExcelParserService.php)
  - `[NEW]` [tests/Unit/ExcelParserServiceTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/ExcelParserServiceTest.php)
- **Tests Required**: Unit tests for shared strings resolution, multi-sheet parsing, row/column bounds, and empty sheet handling.

---

### Task 31: Excel Preview Handler (`ExcelPreviewHandler`)
- **Goal**: Implement `FileHandlerInterface` supporting `.xlsx` and `.xls` extensions, returning structured sheet matrix and metadata.
- **Files to Create/Modify**:
  - `[NEW]` [src/Handler/ExcelPreviewHandler.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Handler/ExcelPreviewHandler.php)
  - `[NEW]` [tests/Unit/ExcelPreviewHandlerTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/ExcelPreviewHandlerTest.php)
- **Tests Required**: Unit tests for extension matching, MIME type checking, and spreadsheet metadata generation.

---

### Task 32: Excel Validation & Registry Expansion
- **Goal**: Update `FileValidationService` (add `xlsx` and `xls` extensions with 5 MB cap), register `ExcelPreviewHandler` in `FileInteractionManager`, `FilePreviewController`, and `Template/file/dropdown.php`.
- **Files to Create/Modify**:
  - `[MODIFY]` [src/Service/FileValidationService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/FileValidationService.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
  - `[MODIFY]` [Template/file/dropdown.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/dropdown.php)
- **Tests Required**: Unit & integration tests verifying Excel extension validation and handler resolution.

---

### Task 33: Multi-Sheet Tabbed Excel Modal Template View
- **Goal**: Create `Template/file/excel_preview.php` modal template view with sheet navigation tabs, column headers (`A`, `B`, `C`), row indices, and cell entity escaping.
- **Files to Create/Modify**:
  - `[NEW]` [Template/file/excel_preview.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/excel_preview.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
- **Tests Required**: Integration tests verifying Excel template dispatching and sheet tab rendering.

---

### Task 34: Verification, Packaging & Release v0.6.0
- **Goal**: Run test suite, verify PHPStan Level 8 clean, update `CLAUDE.md`, `walkthrough.md`, `CHANGELOG.md`, bump version to `0.6.0`.
- **Files to Create/Modify**:
  - `[MODIFY]` [CLAUDE.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/CLAUDE.md)
  - `[MODIFY]` [CHANGELOG.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/CHANGELOG.md)
  - `[MODIFY]` [Plugin.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Plugin.php)
- **Verification**: `bash scripts/agent-verify.sh` passes 100% with zero PHPStan errors.

---

## 🔒 Verification Plan

### Automated Tests
```bash
# Run full verification suite
bash scripts/agent-verify.sh
```

### Manual Verification
- Test previewing `.xlsx` files in Kanboard UI (`http://localhost:8085`).
- Verify multi-sheet tabs allow switching between worksheets.
- Verify cell values are safely rendered with HTML entity escaping.
