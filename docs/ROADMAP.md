# Project Roadmap

This document outlines the evolutionary milestones and future strategic roadmap for `kanboard-file-interaction-core`.

---

## 🏆 Completed Milestones

### Milestone 1: Safe Read-Only Text Preview (v0.1.0)
- [x] Safe plain text preview for `.txt` files.
- [x] Pretty-printed formatted preview for `.json` files.
- [x] Hardened output escaping with `htmlspecialchars()`.
- [x] File size boundaries and path traversal sanitization.
- [x] Unit test suite and automated CI pipeline.

### Milestone 2: CSV & Tabular Grid Preview (v0.2.0)
- [x] Safe read-only CSV/TSV table rendering.
- [x] Auto-detection of field delimiters (comma, semicolon, tab, pipe).
- [x] Interactive delimiter switcher and table controls.

### Milestone 3: Markdown & Syntax Highlighted Code (v0.3.0)
- [x] Sanitized Markdown HTML rendering with heading counters.
- [x] Syntax-highlighted code viewer (`.py`, `.js`, `.php`, `.sql`, `.sh`, `.yaml`, `.xml`, `.css`).
- [x] Interactive syntax language selector in the modal header.

### Milestone 4: Embedded PDF Stream Viewer (v0.4.0)
- [x] Sandboxed inline PDF streaming via `FileStreamController`.
- [x] Framing policy override bypassing `X-Frame-Options: DENY` via CSP `frame-ancestors 'self'`.
- [x] Magic byte verification (`%PDF`) and 10 MB size limit.

### Milestone 5: In-App Live Editor & Attachment Versioning (v0.5.0)
- [x] In-browser live text and code editor with line gutter and syntax status.
- [x] Pre-save JSON syntax validation and error line reporting.
- [x] Attachment versioning engine with revision branching (`_v2.txt`).

### Milestone 6: Excel Spreadsheet Preview & Grid Editor (v0.6.0)
- [x] Memory-safe OpenXML `.xlsx` parser with multi-sheet support.
- [x] In-browser interactive spreadsheet grid editor (formula bar, cell edits, row/col operations).
- [x] Bidirectional XLSX ↔ CSV conversion via `ExcelWriterService`.

### Milestone 7: Rendered / Raw View Mode Toggle & UI Unification (v0.7.1)
- [x] Server-side Rendered vs Raw view mode switcher.
- [x] Unified bottom metadata action bar across all modals.
- [x] Fullscreen modal toggle.

### Milestone 8: Unclassified File Content Sniffing (v0.8.0)
- [x] Bounded 8 KB byte inspection via `BinaryContentDetector`.
- [x] Safe printable text rendering for unknown extensions.
- [x] Metadata-only download notice for binary payloads.

### Milestone 9: Word & PowerPoint Document Engines (v0.9.0)
- [x] High-fidelity client-side Word document rendering (`docx-preview`).
- [x] Interactive PowerPoint slide deck viewer (`pptx-viewer`).
- [x] Pure-PHP OpenXML DOM fallback parsers (`DocxParserService`, `PptxParserService`).
- [x] Legacy `.doc` / `.ppt` download notice handling.

---

## 🚀 Strategic Future Roadmap

### v1.0.0 — Production Release & Hardening (Current)
- [x] Comprehensive architectural refactoring (`AbstractPreviewHandler`, `HandlesAttachmentInteraction`).
- [x] Open-source readiness (`CONTRIBUTING.md`, installation guide in `README.md`).
- [x] Zero PHPUnit deprecations and PHPStan Level 8 static analysis compliance.
- [x] Complete visual diagrams for security architecture and system component lifecycle.

### v1.1.0 — Visual Attachment Revision Diff Viewer
- [ ] **Side-by-Side Diff Engine**: Visual side-by-side and inline diffs comparing attachment revisions (e.g. `spec_v1.txt` vs `spec_v2.txt`).
- [ ] **Word / Markdown Revision Diffs**: Visual highlighting of added/removed paragraphs and headings across versions.
- [ ] **Quick Rollback**: One-click restore to any previous revision from the version history dropdown.

### v1.2.0 — Safe Vector & Media Preview
- [ ] **Sandboxed Vector Viewer**: Secure iframe sandboxed preview for `.svg` files with strict CSP forbidding script execution.
- [ ] **Modern Image Formats**: In-browser preview for modern image formats (`.webp`, `.avif`, `.heic`).
- [ ] **HTML Sandboxed Iframe Viewer**: Optional toggle to render sanitized HTML attachments inside `<iframe sandbox>` without same-origin access.

### v1.3.0 — Full-Text Attachment Search & Indexing
- [ ] **Background Indexing**: Asynchronous extraction and indexing of text from `.docx`, `.pptx`, `.xlsx`, `.pdf`, `.md`, and `.txt` files.
- [ ] **Kanboard Search Integration**: Enable searching attachment text directly from Kanboard's global search bar.

### v2.0.0 — External Document Server & Collaborative Editing
- [ ] **WOPI / OnlyOffice / Collabora Connector**: Optional backend bridge connecting Kanboard to an external OnlyOffice or Collabora Document Server.
- [ ] **Real-Time Co-Editing**: Multi-user simultaneous live editing of `.docx`, `.xlsx`, and `.pptx` documents directly inside Kanboard.
- [ ] **Administrative Plugin Settings UI**: Kanboard admin panel to configure size limits, manage allowed extensions, and toggle client-side viewing engines.
