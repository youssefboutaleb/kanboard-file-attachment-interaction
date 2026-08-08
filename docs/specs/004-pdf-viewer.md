# Functional Specification: 004 - PDF Embedded Read-Only Viewer

**Feature Target**: Milestone 4 — PDF Read-Only Viewer Engine  
**Document Status**: Proposal / Specification  
**Author**: Security & Engineering Team  
**Date**: 2026-08-08  

---

## 1. User Story & Summary

**User Story**:  
As a Kanboard team member or project manager, I want to safely preview PDF attachments (`.pdf`) directly inside a Kanboard modal window, so that I can read documents, invoices, or specifications without leaving Kanboard or downloading raw files locally.

---

## 2. Scope

- **Supported Extension**: `.pdf`.
- **Supported MIME Type**: `application/pdf`.
- **Sandboxed PDF Viewer Window**: Embedded modal viewer using HTML5 `<object>` / `<iframe>` sandbox wrapper with toolbar disabled and script execution blocked.
- **Fallback Download Option**: Clean fallback banner provided if browser PDF rendering is unavailable or disabled.
- **File Validation & ACL**: Strict ACL permission checks, size validation (up to 10 MB for PDFs), and path traversal checks via `FileValidationService`.

---

## 3. Non-Goals

- **NO In-App PDF Form Editing**: PDF form fields cannot be edited or saved.
- **NO PDF Macro Execution**: Embedded JavaScript or macros in PDF files will NOT execute.
- **NO External Dependencies**: No unverified 3rd-party remote script loading.

---

## 4. Acceptance Criteria

1. **AC-1 (Format Resolution)**:
   - Uploading `.pdf` files routes preview requests to `PdfPreviewHandler`.
2. **AC-2 (Sandboxed Preview Modal)**:
   - Clicking "Safe Preview" on a `.pdf` file opens a modal displaying the embedded PDF document cleanly.
3. **AC-3 (XSS & Script Containment)**:
   - PDF viewer container is sandboxed (`sandbox="allow-same-origin allow-scripts"`) and raw HTML tags in metadata are escaped.
4. **AC-4 (Browser Fallback)**:
   - If browser cannot inline render PDFs, a fallback banner provides a direct secure download action link.

---

## 5. Security & Performance Requirements

### Security Constraints
- **Sandboxed Viewer Wrapper**: PDF view rendered in a sandboxed `<object>` / `<iframe>` container.
- **ACL Enforcement**: Permission check via `PermissionService` before serving stream or preview HTML.
- **Strict File Size Bounds**: Maximum PDF size capped at 10 MB.

---

## 6. Test Cases

| ID | Test Scenario | Input / Payload | Expected Result |
| :--- | :--- | :--- | :--- |
| **TC-PDF-01** | Standard PDF File | `document.pdf` | Renders `PdfPreviewHandler` modal with embedded PDF viewer. |
| **TC-PDF-02** | Disallowed File Extension | `archive.zip` | Rejects file with `InvalidFileException`. |
| **TC-PDF-03** | Size Limit Exceeded | 15 MB PDF file | Rejects request with `InvalidFileException` (size exceeds limit). |
| **TC-PDF-04** | Access Denied | Unauthorized user | Returns 403 error modal cleanly. |

---

## 7. Definition of Done

- [ ] `docs/specs/004-pdf-viewer.md` approved.
- [ ] Implementation plan created and approved.
- [ ] `PdfPreviewHandler` implemented and registered in `FileInteractionManager`.
- [ ] `Template/file/pdf_preview.php` modal template created.
- [ ] `bash scripts/agent-verify.sh` passes 100% (PHPStan Level 8 clean).
- [ ] `CLAUDE.md` and `walkthrough.md` updated.
