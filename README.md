# kanboard-file-interaction-core

> A secure, modular file interaction framework for Kanboard task attachments.

[![CI](https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/actions/workflows/ci.yml/badge.svg)](https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/actions)
![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)
![License](https://img.shields.io/badge/license-MIT-green)

---

## 🎯 Project Vision

`kanboard-file-interaction-core` provides a safe, extensible architecture for previewing, processing, and interacting with file attachments uploaded to Kanboard tasks.

### Long-term Goals
- Safe file preview engine
- Versioned file editing capabilities
- Multi-format validation & schema checking
- File conversion workflows
- Integration with external tools (OnlyOffice, Collabora)
- Support for text, JSON, Markdown, HTML, CSV, Excel, PDF, and media types

---

## 🚀 Current Status: Milestone 1

### Milestone 1 Scope
Safe **read-only preview** for simple text-based files:
- `.txt` (plain text)
- `.json` (formatted read-only preview with safe error handling)
- `.md` (initially rendered as raw plain text only, no HTML rendering)

### Explicit Non-Goals for Milestone 1
- File editing or saving
- Rich Markdown HTML rendering
- HTML sandbox rendering or SVG execution
- Excel parsing or CSV editing
- PDF annotation or binary viewers
- External integrations (OnlyOffice/Collabora)
- Multi-agent automation or complex workflow triggers

---

## 🛡️ Security Guarantees

1. **Untrusted Uploads**: All uploaded attachments are treated as hostile input.
2. **No Direct Execution**: Never execute uploaded scripts (`.php`, `.js`, `.sh`, `.html`, `.svg`).
3. **Strict Output Escaping**: All rendered text content is HTML entity escaped before display to eliminate XSS risks.
4. **Path Traversal Protection**: Hashing and strict `basename()` isolation are enforced for file access.
5. **Access Control**: Every preview request requires project and task ACL verification.

See [docs/SECURITY.md](docs/SECURITY.md) for detailed threat models and rules.

---

## 💻 Local Setup & Development

### Requirements
- PHP >= 8.1
- Composer
- Kanboard >= 1.2.0

### Installation
1. Clone this repository into your Kanboard `plugins/` directory:
   ```bash
   cd /path/to/kanboard/plugins
   git clone https://github.com/youssefboutaleb/kanboard-file-attachment-interaction.git FileInteractionCore
   ```
2. Install dev dependencies:
   ```bash
   cd FileInteractionCore
   composer install
   ```

---

## 🧪 Testing

Run automated unit test suite:
```bash
vendor/bin/phpunit
```

---

## 📚 Documentation

- [Architecture & Design](docs/ARCHITECTURE.md)
- [Security Policy](docs/SECURITY.md)
- [Project Roadmap](docs/ROADMAP.md)
- [Coding Rules for AI Agents](AGENTS.md)
- [Claude Code Quick Guide](CLAUDE.md)
