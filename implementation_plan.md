# Implementation Plan - Milestone 3: Safe Markdown HTML Rendering & Code Syntax Highlighting

We will implement **Milestone 3 (Safe Markdown HTML Rendering & Code Syntax Highlighting)** as specified in [docs/specs/003-markdown-syntax-highlighting.md](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/docs/specs/003-markdown-syntax-highlighting.md).

---

## 🏗️ Architecture & Security Design

- **Safe HTML Conversion**: Converts Markdown structures (headers, lists, bold/italic, blockquotes, code blocks, links, tables) into clean HTML elements.
- **Strict XSS Containment**: All raw HTML tags within Markdown content are entity-escaped using `htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **Sanitized Link Protocol Filter**: Link URLs with `javascript:`, `data:`, or `vbscript:` schemes are sanitized to `#`.
- **Zero Heavy Dependencies**: Lightweight native PHP parser implementation with 0 third-party composer dependencies.

---

## 📋 Task Breakdown for Milestone 3

### Task 16: Safe Markdown & Syntax Parser Service (`MarkdownParserService`)
- **Goal**: Implement safe Markdown parser converting headers (`#`), lists (`-`/`1.`), blockquotes (`>`), bold/italic (`**`/`*`), code fences (```), tables, and sanitized links into safe HTML.
- **Files to Create/Modify**:
  - `[NEW]` [src/Service/MarkdownParserService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/MarkdownParserService.php)
  - `[NEW]` [tests/Unit/MarkdownParserServiceTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/MarkdownParserServiceTest.php)
- **Tests Required**: Unit tests for header levels, lists, code fences, XSS script tag containment, and malicious link scheme stripping.

---

### Task 17: Markdown Preview Handler (`MarkdownPreviewHandler`)
- **Goal**: Implement `FileHandlerInterface` supporting `.md` and `.markdown` extensions, delegating to `MarkdownParserService` and returning `PreviewResult`.
- **Files to Create/Modify**:
  - `[NEW]` [src/Handler/MarkdownPreviewHandler.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Handler/MarkdownPreviewHandler.php)
  - `[NEW]` [tests/Unit/MarkdownPreviewHandlerTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/MarkdownPreviewHandlerTest.php)
- **Tests Required**: Unit tests for `.md` format support, formatted HTML output, and metadata calculation (`lineCount`, `charCount`, `headingCount`).

---

### Task 18: Code Syntax Highlighting Handler (`CodePreviewHandler`)
- **Goal**: Implement `CodePreviewHandler` providing tokenized syntax highlighting for source code and config files (`.json`, `.yml`, `.yaml`, `.xml`, `.sh`, `.py`, `.php`, `.js`, `.css`, `.sql`).
- **Files to Create/Modify**:
  - `[NEW]` [src/Handler/CodePreviewHandler.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Handler/CodePreviewHandler.php)
  - `[NEW]` [tests/Unit/CodePreviewHandlerTest.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/tests/Unit/CodePreviewHandlerTest.php)
- **Tests Required**: Unit tests for token highlighting across multiple programming languages.

---

### Task 19: Template Views & Validation Registry Expansion
- **Goal**: Create `Template/file/markdown_preview.php` modal view, update `FileValidationService`, `FileInteractionManager`, `FilePreviewController`, and `Template/file/dropdown.php`.
- **Files to Create/Modify**:
  - `[NEW]` [Template/file/markdown_preview.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/markdown_preview.php)
  - `[MODIFY]` [src/Service/FileValidationService.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Service/FileValidationService.php)
  - `[MODIFY]` [src/Controller/FilePreviewController.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/src/Controller/FilePreviewController.php)
  - `[MODIFY]` [Template/file/dropdown.php](file:///home/yboutaleb/Documents/kanboard-file-attachment-interaction/Template/file/dropdown.php)
- **Tests Required**: Integration tests verifying template dispatch and registry resolution.

---

### Task 20: Verification, Packaging & Release v0.3.0
- **Goal**: Run end-to-end verification suite, update `CLAUDE.md`, `walkthrough.md`, `CHANGELOG.md`, and package `dist/FileInteractionCore-0.3.0.zip`.
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
- Test `.md` file attachments in Kanboard UI (`http://localhost:8085`).
- Verify modal renders rich formatted HTML with styled headers, lists, code fences, and sanitized links.
