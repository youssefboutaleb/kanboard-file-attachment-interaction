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

### 5. Clickjacking / Cross-Origin Embedding of Attachment Streams
- **Threat**: A third-party site frames an attachment stream URL to read privileged document content, or overlays it to trick a logged-in user.
- **Context**: `FileStreamController::inline` deliberately drops the `X-Frame-Options: DENY` header that `BootstrapMiddleware` applies to every core response. That header is what prevented the browser from rendering a PDF inside the modal's `<object>` container at all, and it cannot be selectively overridden through Kanboard's `Response` service. Downgrading it to `SAMEORIGIN` was rejected because it has historically also blocked Chrome's out-of-process PDF viewer.
- **Mitigation**:
  - `Content-Security-Policy: default-src 'none'; frame-ancestors 'self'` replaces it. `frame-ancestors` is the standardized CSP-2 equivalent, honoured by every browser capable of displaying an embedded PDF, and restricts embedding to this origin only.
  - `default-src 'none'` additionally prevents the streamed document from fetching any subresource of its own.
  - The relaxation is scoped to this one action; every other plugin and core response keeps `DENY`.
  - `Cache-Control: private` keeps ACL-protected bytes out of shared caches (core's own `browser` action uses `public`).
  - The route stays behind Kanboard's authentication and project-authorization middleware, plus the plugin's own `PermissionService` check — an unauthenticated request is redirected to `/login` and never reaches the file.

### 6. Inline Streaming of Active Content
- **Threat**: An uploaded `.html`, `.svg`, or `.js` attachment served inline from the application's own origin executes as same-origin script — stored XSS with full session access.
- **Mitigation**:
  - `FileStreamController::INLINE_MIME_TYPES` is a hard allow-list containing **`pdf` only**, deliberately far narrower than `FileValidationService::ALLOWED_EXTENSIONS`. Being previewable through an escaping handler does not make a format safe to serve as a live document.
  - Any other extension is rejected with `400` before any byte is emitted.
  - The payload must carry the format's magic signature (`%PDF` within the first 1024 bytes), so a mislabelled file cannot be announced to the browser as a document.
  - `Content-Type` is forced from the allow-list and paired with `X-Content-Type-Options: nosniff`, so the browser cannot re-interpret the response.
  - `Content-Disposition: inline` filenames are stripped of quotes, backslashes and control characters to prevent header injection.

### 7. Preview of Unclassified Attachments (Unknown / Missing Extension)
- **Threat**: Widening the preview surface beyond the extension whitelist lets an arbitrary upload reach the rendering path — an unrecognised payload could be parsed, rendered as live markup, or buffered without bound.
- **Mitigation**:
  - `BinaryContentDetector` classifies the payload from a **bounded 8 KB sample** (NUL bytes, control-character ratio above 10%, invalid UTF-8). Detection never parses or executes the content, and there is no magic-number allow-list to keep current.
  - Only two outcomes exist, both safe: printable text is rendered through `TextPreviewHandler`, which entity-escapes the whole payload; anything else renders the binary notice, which emits **no file content at all** — only metadata.
  - A false positive costs the user a download prompt. It can never cause an unsafe render, which is why detection is deliberately conservative.
  - Picking a syntax language cannot force binary content into a text view: classification runs before handler selection.
  - `FilePreviewController::CONTENT_READ_CEILING_BYTES` (10 MB) skips the content read entirely using the attachment row's declared size, so an oversized upload is never buffered. The declared size is also used for the cap check, so a skipped read cannot pass validation as a zero-length buffer.
  - ACL enforcement runs before any content is inspected.

### 8. Active Content Reaching a Preview Path
- **Threat**: SVG and other active-content formats executing as same-origin script if rendered or served with their native MIME type.
- **Mitigation**:
  - `FileValidationService::CORE_MEDIA_EXTENSIONS` keeps image, audio and video formats — including `svg` — rejected outright. They are neither previewed nor content-inspected, so no URL can route them into a preview path. Kanboard core owns those viewers.
  - Independently, `FileStreamController::INLINE_MIME_TYPES` (§6) restricts inline streaming to `pdf`, so even an allow-listed format cannot be served as a live document unless explicitly cleared.
  - Whitelisted markup formats (`.html`, `.htm`, `.xml`) are previewable but only ever as **escaped text** — never injected into the DOM as markup.
  - The `lang` picker parameter is validated against `SyntaxLanguageRegistry`; an unrecognised or crafted value is discarded before it can reach the highlighter or the rendered output.

### 9. Dangerous Extension / Content Execution
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
