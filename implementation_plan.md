# Implementation Plan - Milestone 2: Safe CSV Read-Only Table Preview

We will implement **Milestone 2 (CSV Read-Only Table Preview)** as specified in [docs/specs/002-csv-preview.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/docs/specs/002-csv-preview.md).

---

## 🏗️ Architecture Decision: CSV Parser Strategy

### Evaluation: Option A vs Option B

| Criterion | Option A: Native PHP `fgetcsv()` / `str_getcsv()` | Option B: `league/csv` Dependency |
| :--- | :--- | :--- |
| **Dependencies** | **0 External Dependencies** (Native PHP 8.1 built-in) | Adds vendor dependency to plugin ZIP package |
| **Memory Safety** | High (Streams max 100 rows line-by-line) | High (Iterators) |
| **Security & Supply Chain**| **Zero supply chain attack surface** | Requires auditing third-party dependency updates |
| **Performance** | Extremely fast (C extension implementation) | PHP userland parsing abstractions |

> [!TIP]
> **Recommendation**: **Option A (Native PHP `fgetcsv()` / `str_getcsv()`)** is strongly recommended. It adheres strictly to Kanboard plugin integrity rules (lightweight, zero unnecessary composer bloat, zero supply-chain risk, native PHP 8.1 speed).

---

## 📋 Task Breakdown for Milestone 2

### Task 11: Delimiter Detection & Parsing Service (`CsvParserService`)
- **Goal**: Implement dynamic delimiter detection (`,`, `;`, `\t`, `|`) and line-bounded CSV string parsing up to 100 rows.
- **Files to Create/Modify**:
  - `[NEW]` [src/Service/CsvParserService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/CsvParserService.php)
  - `[NEW]` [tests/Unit/CsvParserServiceTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/CsvParserServiceTest.php)
- **Tests Required**: Unit tests for comma, semicolon, tab, pipe delimiters, empty inputs, and line truncation.
- **Risks & Mitigations**: Malformed quoted cells — mitigated using native `str_getcsv()` with boundary checks.

---

### Task 12: CSV Preview Handler (`CsvPreviewHandler`)
- **Goal**: Implement `FileHandlerInterface` supporting `.csv` and `.tsv`, returning structured table data and metadata (`delimiter`, `rowCount`, `columnCount`, `truncated`).
- **Files to Create/Modify**:
  - `[NEW]` [src/Handler/CsvPreviewHandler.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Handler/CsvPreviewHandler.php)
  - `[NEW]` [tests/Unit/CsvPreviewHandlerTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/CsvPreviewHandlerTest.php)
- **Tests Required**: Unit tests for `.csv` & `.tsv` extensions, cell HTML escaping, and metadata calculations.
- **Risks & Mitigations**: XSS in cell values — mitigated via `htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

---

### Task 13: File Validation & Registry Expansion
- **Goal**: Add `csv` and `tsv` to `FileValidationService::ALLOWED_EXTENSIONS` and register `CsvPreviewHandler` in `FileInteractionManager` & `FilePreviewController`.
- **Files to Create/Modify**:
  - `[MODIFY]` [src/Service/FileValidationService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/FileValidationService.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
  - `[MODIFY]` [Template/file/dropdown.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/dropdown.php)
- **Tests Required**: Controller & validator integration tests verifying `.csv` routing.

---

### Task 14: Responsive Safe CSV Table Template View
- **Goal**: Create HTML modal view rendering structured CSV data tables with styled headers, alternating row colors, cell entity escaping, and truncation warning banners.
- **Files to Create/Modify**:
  - `[NEW]` [Template/file/csv_preview.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/csv_preview.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
- **Tests Required**: Template rendering tests verifying modal HTML output.

---

### Task 15: Verification, Packaging & Milestone 2 Release (`v0.2.0`)
- **Goal**: Run end-to-end verification suite, update `CLAUDE.md`, `walkthrough.md`, `CHANGELOG.md`, and package `v0.2.0` release ZIP.
- **Files to Create/Modify**:
  - `[MODIFY]` [CLAUDE.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/CLAUDE.md)
  - `[MODIFY]` [CHANGELOG.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/CHANGELOG.md)
- **Verification**: `bash scripts/agent-verify.sh` passes 100% with zero PHPStan errors.

---

## 🔒 Verification Plan

### Automated Tests
```bash
# Run full verification suite (PHP Syntax, Composer, PHPStan Level 8, Unit Tests)
bash scripts/agent-verify.sh
```

### Manual Verification
- Test `.csv` (comma) and `.csv` (semicolon) uploads in Kanboard UI (`http://localhost:8085`).
- Confirm modal opens displaying responsive data table with escaped cells.
