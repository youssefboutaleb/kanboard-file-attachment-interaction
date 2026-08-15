# Spec 001: Safe Read-Only Text & Configuration Preview

- **Status**: Updated & Approved
- **Milestone**: 1
- **Target Formats**: `.txt`, `.json`, `.md`, `.env`, `.ini`, `.conf`, `.yaml`, `.yml`, `.xml`, `.log`, `.html`, `.htm`

---

## User Story

> As a Kanboard user viewing task attachments,  
> I want to preview plain text, configuration files (`.env`, `.ini`, `.yaml`), JSON, and raw HTML files safely in the Kanboard UI,  
> So that I can inspect attachment contents without downloading them or executing malicious scripts.

---

## Acceptance Criteria

1. **Config & Environment Files Preview (`.env`, `.ini`, `.conf`, `.yaml`, `.yml`, `.xml`, `.log`)**:
   - Files open by default as safe plain text.
   - Values are strictly HTML-escaped.

2. **JSON File Preview**:
   - `.json` files are validated and pretty-printed.
   - If JSON is malformed, displays a safe friendly error message alongside raw text content.

3. **HTML / Markdown File Preview**:
   - `.html`, `.htm`, and `.md` files are rendered **as raw text source code only** (`htmlspecialchars()`).
   - No HTML tags or JavaScript execution (`<script>`, `<iframe>`) can occur.

4. **Forced Format Support**:
   - `FileInteractionManager::resolveHandler()` accepts an optional `$forcedFormat` parameter (`'text'`, `'json'`, `'raw'`) allowing UI controls to force plain text rendering for any supported attachment.

---

## Definition of Done

- All 22 Unit tests pass (`bash scripts/agent-verify.sh`).
- PHPStan Level 8 passes with zero errors.
- `SECURITY.md` rules verified.
