# CLAUDE.md - Claude Agentic Setup & Development Guide

This document defines the agentic workflow, automated quality gates, git hooks, and verification standards for Claude Code and AI coding assistants.

> ⚠️ **MANDATORY RULE**: This `CLAUDE.md` file MUST be updated at the end of **EVERY** completed task/step to reflect current milestone status, implemented handlers, and test execution details.

---

## 🚦 Milestone Task Progress Status

### Milestone 1: Safe Text/JSON Preview (100% RELEASED - v0.1.0 / v0.1.1 Hotfix)
- [x] **Task 1: Bootstrap & Integrity Documentation** (Completed)
- [x] **Task 2: Core Contracts & Manager** (`FileHandlerInterface`, `PreviewResult`, `FileInteractionManager`) (Completed)
- [x] **Task 3: Text Preview Handler** (`TextPreviewHandler` for `.txt` and `.md`) (Completed)
- [x] **Task 4: JSON Preview Handler** (`JsonPreviewHandler` for `.json`) (Completed)
- [x] **Task 5: File Validation Service** (`FileValidationService` for extension, path, size, MIME validation) (Completed)
- [x] **Task 6: Permission Service Abstraction** (`PermissionService`, `PermissionCheckerInterface`, `MockPermissionChecker`) (Completed)
- [x] **Task 7: Preview Controller & Route** (`FilePreviewController`, `FileContentFetcherInterface`) (Completed)
- [x] **Task 8: UI Entry Point Integration** (`Plugin.php` hooks, `Template/file/dropdown.php`, `Template/file/preview.php`) (Completed)
- [x] **Task 9: Automated Test Expansion** (`tests/Integration/PluginTest.php`, `tests/Unit/EdgeCasesTest.php`) (Completed)
- [x] **Task 10: Manual Checklist & Verification** (`docs/MANUAL_TESTING.md` matrix, 100% test pass rate verified) (Completed)

### Milestone 2: Safe CSV Read-Only Table Preview (100% RELEASED - v0.2.0)
- [x] **Task 11: Delimiter Detection & Parsing Service** (`CsvParserService` for `,`, `;`, `\t`, `|` detection) (Completed)
- [x] **Task 12: CSV Preview Handler** (`CsvParserService`, `CsvPreviewHandler` for `.csv` and `.tsv`) (Completed)
- [x] **Task 13: File Validation & Registry Expansion** (`FileValidationService`, `FileInteractionManager` name-based forced format, `FilePreviewController`) (Completed)
- [x] **Task 14: Responsive Safe CSV Table Template View** (`Template/file/csv_preview.php`, `FilePreviewController` template selection) (Completed)
- [x] **Task 15: Verification, Packaging & Release v0.2.0** (`CHANGELOG.md`, `dist/FileInteractionCore-0.2.0.zip`, 72 tests passing 100%) (Completed)

### Milestone 3: Safe Markdown HTML Rendering & Code Syntax Highlighting (100% RELEASED - v0.3.0)
- [x] **Task 16: Safe Markdown & Syntax Parser Service** (`MarkdownParserService` with XSS script entity escaping & link URI sanitization) (Completed)
- [x] **Task 17: Markdown Preview Handler** (`MarkdownPreviewHandler` for `.md` and `.markdown`) (Completed)
- [x] **Task 18: Code Syntax Highlighting Handler** (`CodePreviewHandler` for `.sh`, `.py`, `.js`, `.sql`, `.css`, `.php`, `.yml`, `.xml`) (Completed)
- [x] **Task 19: Template Views & Validation Registry Expansion** (`Template/file/markdown_preview.php`, 5-handler registry) (Completed)
- [x] **Task 20: Verification, Packaging & Release v0.3.0** (`CHANGELOG.md`, `dist/FileInteractionCore-0.3.0.zip`, 142 tests passing 100%) (Completed)

### Milestone 4: PDF Embedded Read-Only Viewer (100% RELEASED - v0.4.0)
- [x] **Task 21: PDF Preview Handler** (`PdfPreviewHandler` for `.pdf` and `application/pdf`) (Completed)
- [x] **Task 22: PDF Validation & Registry Expansion** (`FileValidationService` 10MB PDF cap, 6-handler registry, dropdown whitelist) (Completed)
- [x] **Task 23: Sandboxed PDF Modal Template View** (`Template/file/pdf_preview.php`, `FilePreviewController` PDF template dispatching) (Completed)
- [x] **Task 24: Verification, Packaging & Release v0.4.0** (`CHANGELOG.md`, `Plugin.php` v0.4.0, release workflow) (Completed)

### Milestone 5: Safe In-App Text & JSON Live Editor with Versioning (100% RELEASED - v0.5.0)
- [x] **Task 25: Pre-Save Validation & Syntax Checking Service** (`FileEditValidationService` for size bounds & JSON syntax error detection) (Completed)
- [x] **Task 26: File Versioning & Revision Service** (`FileVersionService` for versioned filename generation & content updating) (Completed)
- [x] **Task 27: Live Editor Controller & Routes** (`FileEditController` with `edit()` modal and `update()` POST action) (Completed)
- [x] **Task 28: Interactive Editor Modal View & Dropdown Entry Point** (`Template/file/edit.php`, `Template/file/dropdown.php` edit link) (Completed)
- [x] **Task 29: Verification, Packaging & Release v0.5.0** (`CHANGELOG.md`, `Plugin.php` v0.5.0, release packaging) (Completed)

### Milestone 6: Excel Spreadsheet Interactive Preview Engine (100% RELEASED - v0.6.0)
- [x] **Task 30: Excel Spreadsheet Parsing Service** (`ExcelParserService` for `.xlsx` OpenXML parsing & multi-sheet extraction) (Completed)
- [x] **Task 31: Excel Preview Handler** (`ExcelPreviewHandler` for `.xlsx` and `.xls`) (Completed)
- [x] **Task 32: Excel Validation & Registry Expansion** (`FileValidationService` 5MB cap, 7-handler registry, dropdown whitelist) (Completed)
- [x] **Task 33: Multi-Sheet Tabbed Excel Modal Template View** (`Template/file/excel_preview.php`, sheet navigation tabs, A/B/C column headers, cell entity escaping) (Completed)
- [x] **Task 34: Verification, Packaging & Release v0.6.0** (`CHANGELOG.md`, `Plugin.php` v0.6.0, release packaging) (Completed)

---

## 🛠️ Essential Commands & Agentic Scripts

```bash
# Automated Agent Verification Pipeline (PHP Syntax, Composer, PHPStan Level 8, 279 Tests Passing)
bash scripts/agent-verify.sh
# or via composer:
composer agent-verify

# Test Execution via Docker (PHP 8.1 container - 279 Tests Passing)
docker run --rm -v $(pwd):/app -w /app php:8.1-cli vendor/bin/phpunit

# Package Plugin Release v0.6.0
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
