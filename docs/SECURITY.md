# Security Specification & Guidelines

Security is a foundational requirement for `kanboard-file-interaction-core`. File attachments uploaded by users are considered **untrusted raw input**.

---

## Threat Model & Risk Mitigations

### 1. Cross-Site Scripting (XSS)
- **Threat**: Attackers upload files containing script payloads (`<script>`, inline SVG JS, HTML event handlers) to execute code in the user's browser session.
- **Mitigation**:
  - All preview text is converted into safe HTML entities using `htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
  - Content-Type headers set to `text/plain` or strict iframe sandboxing when rendering previews.
  - HTML rendering is explicitly **disabled** in Milestone 1.

### 2. Path Traversal & Arbitrary File Read
- **Threat**: Crafted filenames or paths (`../../../../etc/passwd` or `C:\boot.ini`) used to read host system files.
- **Mitigation**:
  - Filenames are sanitized using `basename()`.
  - Storage keys are looked up strictly via Kanboard's internal attachment ID mapping, never raw user path parameters.

### 3. Permission Bypass / Unauthorized File Access
- **Threat**: Users access attachment previews for tasks/projects they do not have access rights to view.
- **Mitigation**:
  - All preview routes invoke `PermissionService` to verify that the logged-in user has read permissions for the specific project and task prior to retrieving file content.

### 4. Denial of Service (DoS) via Large File Allocation
- **Threat**: Uploading massive files (e.g. 100MB+ text/JSON files) causing server memory exhaustion during parsing or formatting.
- **Mitigation**:
  - `FileValidationService` enforces a hard maximum preview size cap (default: **500 KB**).
  - Preview reads request only up to the maximum byte limit.

### 5. Dangerous Extension / Content Execution
- **Threat**: Execution of uploaded `.php`, `.sh`, `.exe`, `.py` scripts on the server.
- **Mitigation**:
  - Strict extension whitelist (`.txt`, `.json`, `.md`).
  - Disallowed extensions return `UnsupportedFileTypeException` and are rejected before read operations occur.

---

## Security Checklist for Code Contributions

- [ ] Does input validation use strict whitelisting?
- [ ] Is all output rendered in templates safely escaped?
- [ ] Are project/task ACL permissions checked before reading files?
- [ ] Is file path traversal impossible by design?
- [ ] Are file size limits enforced before loading contents into memory?
