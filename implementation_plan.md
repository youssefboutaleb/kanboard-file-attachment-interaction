# Implementation Plan - Milestone 4: PDF Embedded Read-Only Viewer

We will implement **Milestone 4 (PDF Embedded Read-Only Viewer)** as specified in [docs/specs/004-pdf-viewer.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/docs/specs/004-pdf-viewer.md).

---

## 🏗️ Architecture & Security Design

- **Safe Embedded PDF Container**: Renders `.pdf` files inside a sandboxed HTML `<object>` / `<iframe>` container.
- **Dedicated Size Limit for PDFs**: Capped at 10 MB (versus 500 KB for text/json/csv files).
- **Fallback Action Link**: Provides a secure fallback link for browsers with PDF plugin blocking.
- **Strict ACL Protection**: Validates user access before opening viewer or serving streams.

---

## 📋 Task Breakdown for Milestone 4

### Task 21: PDF Preview Handler (`PdfPreviewHandler`)
- **Goal**: Implement `FileHandlerInterface` supporting `.pdf` files and `application/pdf` MIME type.
- **Files to Create/Modify**:
  - `[NEW]` [src/Handler/PdfPreviewHandler.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Handler/PdfPreviewHandler.php)
  - `[NEW]` [tests/Unit/PdfPreviewHandlerTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/PdfPreviewHandlerTest.php)
- **Tests Required**: Unit tests for `.pdf` extension support, MIME type matching, and metadata generation.

---

### Task 22: PDF Validation & Registry Expansion
- **Goal**: Update `FileValidationService` (add `pdf` extension and 10MB size limit for PDF), register `PdfPreviewHandler` in `FileInteractionManager`, `FilePreviewController`, and `Template/file/dropdown.php`.
- **Files to Create/Modify**:
  - `[MODIFY]` [src/Service/FileValidationService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/FileValidationService.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
  - `[MODIFY]` [Template/file/dropdown.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/dropdown.php)
- **Tests Required**: Unit & integration tests verifying PDF extension validation and handler resolution.

---

### Task 23: Sandboxed PDF Modal Template View
- **Goal**: Create `Template/file/pdf_preview.php` modal view rendering a sandboxed PDF container with fallback download links.
- **Files to Create/Modify**:
  - `[NEW]` [Template/file/pdf_preview.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/pdf_preview.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
- **Tests Required**: Integration tests verifying PDF template dispatching.

---

### Task 24: Verification, Packaging & Release v0.4.0
- **Goal**: Run end-to-end verification suite, update `CLAUDE.md`, `walkthrough.md`, `CHANGELOG.md`, and package `dist/FileInteractionCore-0.4.0.zip`.
- **Files to Create/Modify**:
  - `[MODIFY]` [CLAUDE.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/CLAUDE.md)
  - `[MODIFY]` [CHANGELOG.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/CHANGELOG.md)
- **Verification**: `bash scripts/agent-verify.sh` passes 100% with zero PHPStan errors.

---

## 🔒 Verification Plan

### Automated Tests
```bash
# Run full verification suite
bash scripts/agent-verify.sh
```

### Manual Verification
- Test `.pdf` file attachments in Kanboard UI (`http://localhost:8085`).
- Verify modal renders embedded PDF document cleanly with fallback download link.
