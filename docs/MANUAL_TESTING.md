# Manual Testing Checklist & Verification Guide

This checklist documents manual testing procedures for `kanboard-file-interaction-core` on local or staging Kanboard installations.

---

## 🚀 Environment Setup

1. Start the local Kanboard test instance:
   ```bash
   docker compose up -d
   ```
2. Open browser at `http://localhost:8085` and log in (`admin` / `admin`).
3. Verify that `FileInteractionCore` is listed in **Settings -> Installed Plugins**.

---

## 📋 Test Matrix

| ID | Category | Action / File | Expected Behavior | Status |
| :--- | :--- | :--- | :--- | :--- |
| **TC-01** | **Text Preview** | Upload `notes.txt` | Dropdown displays **Safe Preview**. Modal opens displaying plain text. | ✅ Pass |
| **TC-02** | **Markdown Preview** | Upload `README.md` | Opens as raw plain text source code. No HTML elements rendered. | ✅ Pass |
| **TC-03** | **Environment Config** | Upload `.env` or `app.ini` | Opens as plain text. Config variables are strictly HTML-escaped. | ✅ Pass |
| **TC-04** | **Valid JSON** | Upload `data.json` | JSON is validated and formatted with 2-space pretty printing. | ✅ Pass |
| **TC-05** | **Invalid JSON** | Upload malformed `.json` | Displays friendly warning notice `[JSON Validation Error]` with raw input. | ✅ Pass |
| **TC-06** | **Safe HTML View** | Upload `index.html` | Displays **raw HTML source code only**. Scripts (`<script>`) do NOT execute. | ✅ Pass |
| **TC-07** | **XSS Neutralization** | Upload file with `<script>alert('xss')</script>` | Text is HTML-entity escaped (`&lt;script&gt;`). No alert pops up. | ✅ Pass |
| **TC-08** | **Path Traversal** | Request preview of `../../../../etc/passwd` | Filename is sanitized via `basename()`. Arbitrary file read is prevented. | ✅ Pass |
| **TC-09** | **Size Truncation** | Upload file > 500 KB | Modal displays warning banner: *"File content exceeds maximum preview size limit (500 KB) and has been truncated."* | ✅ Pass |
| **TC-10** | **ACL Security** | Request preview without task read rights | Access Denied notice returned. Request blocked. | ✅ Pass |
| **TC-11** | **Forbidden Extensions**| Attempt preview of `.php`, `.js`, `.exe`, `.sh` | "Safe Preview" option omitted from dropdown. Request rejected. | ✅ Pass |

---

## 🛡️ Security Sanity Checks

- [x] No `eval()`, `exec()`, `passthru()`, or `shell_exec()` calls present in plugin source code.
- [x] All preview outputs processed through `htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- [x] All file path parameters wrapped in `basename()`.
- [x] Hard size limit of 500 KB enforced prior to memory allocations.
