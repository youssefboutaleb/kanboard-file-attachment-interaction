# Implementation Plan - Milestone 5: Safe In-App Text & JSON Live Editor with Versioning

We will implement **Milestone 5 (Safe In-App Text & JSON Live Editor with Versioning)** as specified in [docs/specs/005-live-editor-versioning.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/docs/specs/005-live-editor-versioning.md).

---

## 🏗️ Architecture & Security Design

- **Pre-Save Validation Engine (`FileEditValidationService`)**: Validates edit payload size (under 500 KB limit) and syntax (e.g. `json_validate()` to reject malformed JSON before saving).
- **Attachment Revision & Versioning (`FileVersionService`)**: Supports updating attachment content or creating a versioned revision attachment (`filename_v2.ext`).
- **Live Edit Controller (`FileEditController`)**: Handles `edit` modal display and `update` POST action with ACL write checks via `PermissionService`.
- **Responsive Editor View (`Template/file/edit.php`)**: Full-featured modal view with format badge, line indicator, error alert banners, and save controls.

---

## 📋 Task Breakdown for Milestone 5

### Task 25: Pre-Save Validation & Syntax Checking Service (`FileEditValidationService`)
- **Goal**: Create service validating edit payload size and syntax (e.g. JSON syntax error detection).
- **Files to Create/Modify**:
  - `[NEW]` [src/Service/FileEditValidationService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/FileEditValidationService.php)
  - `[NEW]` [tests/Unit/FileEditValidationServiceTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/FileEditValidationServiceTest.php)
- **Tests Required**: Unit tests for size limits, valid JSON, invalid JSON syntax error detection, and plain text validation.

---

### Task 26: File Versioning & Revision Service (`FileVersionService`)
- **Goal**: Create service managing attachment file overwriting and versioned revision creation.
- **Files to Create/Modify**:
  - `[NEW]` [src/Service/FileVersionService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/FileVersionService.php)
  - `[NEW]` [tests/Unit/FileVersionServiceTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/FileVersionServiceTest.php)
- **Tests Required**: Unit tests for version filename generation (`file_v2.txt`), content update logic, and path sanitization.

---

### Task 27: Live Editor Controller & Routes (`FileEditController`)
- **Goal**: Create `FileEditController` handling `edit()` (renders modal) and `update()` (POST action validating payload, checking write ACL, and persisting changes).
- **Files to Create/Modify**:
  - `[NEW]` [src/Controller/FileEditController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FileEditController.php)
  - `[MODIFY]` [Plugin.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Plugin.php) (routes registration)
  - `[NEW]` [tests/Unit/FileEditControllerTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/FileEditControllerTest.php)
- **Tests Required**: Unit tests for `edit` modal response, `update` POST handling, validation failures, and 403 ACL rejection.

---

### Task 28: Interactive Editor Modal View & Dropdown Entry Point
- **Goal**: Create `Template/file/edit.php` editor modal template view and add "Edit File" link to `Template/file/dropdown.php`.
- **Files to Create/Modify**:
  - `[NEW]` [Template/file/edit.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/edit.php)
  - `[MODIFY]` [Template/file/dropdown.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/dropdown.php)
  - `[MODIFY]` [tests/Integration/PluginTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Integration/PluginTest.php)
- **Tests Required**: Integration tests verifying dropdown edit link rendering and editor template dispatching.

---

### Task 29: Verification, Packaging & Release v0.5.0
- **Goal**: Run test suite, verify PHPStan Level 8 clean, update `CLAUDE.md`, `walkthrough.md`, `CHANGELOG.md`, bump version to `0.5.0`.
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
- Test editing `.txt`, `.json`, and `.md` file attachments in Kanboard UI (`http://localhost:8085`).
- Verify invalid JSON syntax triggers warning banner without saving.
- Verify saving updates attachment content cleanly.
