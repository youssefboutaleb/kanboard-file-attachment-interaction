# Functional Specification: 005 - Safe In-App Text & JSON Live Editor with Versioning

**Feature Target**: Milestone 5 — Live Text/JSON Editor & Attachment Versioning Engine  
**Document Status**: Proposal / Specification  
**Author**: Security & Engineering Team  
**Date**: 2026-08-08  

---

## 1. User Story & Summary

**User Story**:  
As a Kanboard project member, I want to edit `.txt`, `.json`, and `.md` file attachments directly inside a Kanboard modal window with syntax validation and automatic version tracking, so that I can quickly update documentation or configuration files without downloading, editing locally, and re-uploading manually.

---

## 2. Scope

- **Supported Formats**: `.txt`, `.json`, `.md`, `.markdown`, `.yml`, `.yaml`, `.sh`, `.py`, `.js`, `.css`, `.sql`.
- **Edit Modal (`Template/file/edit.php`)**: Clean text editing area with line numbers, format badge, and syntax validation status.
- **Controller Actions (`FileEditController`)**:
  - `edit`: Renders the interactive editor modal.
  - `update`: Handles POST submissions, validates payload size and syntax, and saves the file.
- **Pre-Save Validation (`FileEditValidationService`)**:
  - Rejects oversized payloads exceeding format limits (500 KB).
  - Validates JSON syntax (returns structured syntax error with line/character offset if invalid JSON).
- **Attachment Versioning (`FileVersionService`)**:
  - Supports overwriting existing file content or creating a versioned revision attachment (`filename_v2.ext`).
- **Strict ACL Write Check**: Verifies project edit permissions before rendering edit controls or executing file updates.

---

## 3. Non-Goals

- **NO WYSIWYG Rich Text Editor**: Editor operates on raw source text and markup cleanly.
- **NO Unsanitized DOM Execution**: Output in editor preview stays HTML entity-escaped.

---

## 4. Acceptance Criteria

1. **AC-1 (Edit Dropdown Action)**:
   - "Edit File" option appears in file attachment dropdown for supported text/json/md extensions when user has write access.
2. **AC-2 (Pre-Save Syntax Validation)**:
   - Attempting to save invalid JSON highlights the exact syntax error line without corrupting the existing file attachment.
3. **AC-3 (Safe Save & Version Creation)**:
   - Saving updates the file attachment content cleanly and records a revision timestamp/version log.
4. **AC-4 (Write ACL Enforcement)**:
   - Users without project edit permissions receive a 403 access denied response and cannot submit edits.

---

## 5. Security & Performance Constraints

- **Strict Write Permission Enforcement**: Validates user edit permissions via `PermissionService`.
- **Input Path Traversal Sanitization**: All file paths wrapped in `basename()`.
- **Size Bounds**: Max payload size capped at 500 KB.

---

## 6. Definition of Done

- [ ] `docs/specs/005-live-editor-versioning.md` created and approved.
- [ ] Implementation plan created and approved.
- [ ] `FileEditValidationService` and `FileVersionService` implemented and unit tested.
- [ ] `FileEditController` and routes created.
- [ ] `Template/file/edit.php` editor modal view created.
- [ ] Dropdown action "Edit File" added to `Template/file/dropdown.php`.
- [ ] `bash scripts/agent-verify.sh` passes 100% (PHPStan Level 8 clean).
- [ ] `CLAUDE.md` and `walkthrough.md` updated.
