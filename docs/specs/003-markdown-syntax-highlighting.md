# Functional Specification: 003 - Safe Markdown HTML Rendering & Code Syntax Highlighting

**Feature Target**: Milestone 3 — Safe Markdown & Code Syntax Preview Engine  
**Document Status**: Proposal / Specification  
**Author**: Youssef BOUTALEB  
**Date**: 2026-08-08  

---

## 1. User Story & Summary

**User Story**:  
As a Kanboard developer, project manager, or documentation author, I want to preview Markdown (`.md`, `.markdown`) attachments rendered as formatted, rich HTML documents and preview source code files (`.json`, `.yml`, `.yaml`, `.xml`, `.sh`, `.py`, `.php`, `.js`, `.css`, `.sql`) with syntax highlighting in a secure modal, so that I can easily read technical documentation, specifications, and code snippets without risking XSS attacks or raw script execution.

---

## 2. Scope

- **Supported Markdown Extensions**: `.md`, `.markdown`.
- **Supported Code Extensions**: `.json`, `.yml`, `.yaml`, `.xml`, `.html`, `.sh`, `.py`, `.php`, `.js`, `.css`, `.sql`.
- **Safe Markdown HTML Parser**: Converts standard Markdown elements (headers `#`, lists `-`/`1.`, bold `**`, italic `*`, blockquotes `>`, code blocks ` ``` `, inline code `` ` ``, tables, and links `[text](url)`) into safe HTML.
- **Strict XSS Sanitization**:
  - Raw HTML tags inside Markdown text are escaped or sanitized (e.g. `<script>`, `<iframe>`, `javascript:` URIs are neutralized).
  - External link targets are forced to `target="_blank" rel="noopener noreferrer"`.
- **Code Syntax Highlighting**: Formatted code block rendering with keyword, string, comment, and number highlighting.

---

## 3. Non-Goals

- **NO Raw Unescaped HTML Execution**: Raw HTML injected inside Markdown will NOT execute scripts, styles, or external embeds.
- **NO File Editing**: Preview remains strictly read-only.
- **NO Remote Asset Loading**: No external HTTP requests for remote images or scripts during Markdown rendering.

---

## 4. Acceptance Criteria

1. **AC-1 (Markdown Rendering)**:
   - Previewing `.md` or `.markdown` attachments displays formatted HTML headings (`<h1>`-`<h6>`), lists (`<ul>`, `<ol>`), blockquotes (`<blockquote>`), code blocks, and formatted text.
2. **AC-2 (Code Syntax Highlighting)**:
   - Code blocks inside Markdown or source code attachments (`.json`, `.yml`, `.sh`, `.py`, `.php`) render with highlighted syntax tokens.
3. **AC-3 (XSS Containment)**:
   - Markdown content containing `<script>alert(1)</script>` or `<img src=x onerror=...>` renders as escaped text. No JavaScript is executed.
4. **AC-4 (Unsafe Link Sanitization)**:
   - Markdown links using `javascript:`, `data:`, or `vbscript:` URI schemes are sanitized or stripped to `href="#"`.
5. **AC-5 (Memory & Size Safety)**:
   - Content exceeding 500 KB is safely truncated before parsing.

---

## 5. Security & Performance Requirements

### Security Constraints
- **HTML Sanitization**: All HTML generated from Markdown parsing MUST pass through strict entity escaping or HTML sanitization.
- **Link Protocol Filter**: Only `http://` and `https://` protocol schemes allowed in Markdown link `href` attributes.
- **Strict Read-Only Modal**: Container does not execute scripts.

### Performance Constraints
- **Parser Execution Speed**: Parsing completes in under 10 ms for standard 500 KB documentation files.
- **Zero Heavy Dependencies**: Lightweight native PHP / JS implementation without bloated vendor packages.

---

## 6. Test Cases

| ID | Test Scenario | Input Payload | Expected Result |
| :--- | :--- | :--- | :--- |
| **TC-MD-01** | Standard Markdown | `# Title\n- Item 1\n- Item 2` | Renders `<h1>Title</h1>` and `<ul><li>Item 1</li>...`. |
| **TC-MD-02** | XSS Script Tag | `# Hello\n<script>alert('XSS')</script>` | Renders `<h1>Hello</h1>` and escaped `&lt;script&gt;...`. |
| **TC-MD-03** | Malicious Link Scheme | `[Click Here](javascript:alert(1))` | Link URL is sanitized to `#` or `http://`. |
| **TC-MD-04** | Code Syntax Block | ` ```python\ndef hello():\n    print("World")\n``` ` | Renders code block with token syntax classes. |

---

## 7. Definition of Done

- [ ] `docs/specs/003-markdown-syntax-highlighting.md` approved.
- [ ] Implementation plan created and approved.
- [ ] `MarkdownPreviewHandler` implemented and registered.
- [ ] Unit tests for Markdown parsing, XSS sanitization, and syntax highlighting passing.
- [ ] `bash scripts/agent-verify.sh` passes 100% (PHPStan Level 8 clean).
- [ ] `CLAUDE.md` and `walkthrough.md` updated.
