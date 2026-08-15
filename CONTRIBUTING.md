# Contributing to FileInteractionCore

Thank you for your interest in contributing to **`kanboard-file-interaction-core`**! This plugin provides a secure, modular file interaction framework for Kanboard task attachments.

We welcome contributions of all kinds: bug reports, documentation improvements, security enhancements, and new format handlers.

---

## 📜 Table of Contents

1. [Code of Conduct & Core Principles](#-code-of-conduct--core-principles)
2. [Development Environment Setup](#-development-environment-setup)
3. [Architecture Overview](#-architecture-overview)
4. [How to Implement a New File Handler](#-how-to-implement-a-new-file-handler)
5. [Security Rules & Best Practices](#-security-rules--best-practices)
6. [Coding Standards](#-coding-standards)
7. [Testing & Verification Pipeline](#-testing--verification-pipeline)
8. [Git Workflow & Pull Requests](#-git-workflow--pull-requests)

---

## 🛡️ Code of Conduct & Core Principles

To ensure code safety, stability, and maintainability, all contributors must uphold these foundational rules:

1. **Security First**: File attachments uploaded by users are **untrusted raw input**. Never execute scripts or render raw, unescaped HTML.
2. **Zero Core Modifications**: All plugin functionality must reside within this repository. Never alter Kanboard core files.
3. **No Assumptions**: Use standard Kanboard extension points (plugin interfaces, template hooks, routes, events).
4. **Mandatory Automated Tests**: Every new handler, validation rule, or feature must have comprehensive unit and integration tests.
5. **Strict Types & Static Analysis**: Strict typing (`declare(strict_types=1);`) and PHPStan Level 8 compliance are enforced across the entire codebase.

---

## 🛠️ Development Environment Setup

### Requirements
- **PHP**: >= 8.1 (with `ext-mbstring`, `ext-json`, and optionally `ext-zip` for OpenXML parsing)
- **Composer**: 2.x
- **Docker** (optional, for isolated pipeline runs)
- **Kanboard**: >= 1.2.0

### Local Setup
```bash
# 1. Clone the repository into Kanboard's plugins directory
cd /path/to/kanboard/plugins
git clone https://github.com/youssefboutaleb/kanboard-file-attachment-interaction.git FileInteractionCore
cd FileInteractionCore

# 2. Install dependencies
composer install

# 3. Setup git hooks (pre-commit verification)
composer setup-hooks
```

---

## 🏛️ Architecture Overview

The plugin is structured around standard software design patterns:

- **Strategy Pattern (`FileHandlerInterface`, `AbstractPreviewHandler`)**: Each file format (Text, Markdown, CSV, Excel, PDF, Word, PowerPoint, Code) is handled by a specialized preview strategy.
- **Registry Pattern (`FileInteractionManager`, `SyntaxLanguageRegistry`, `CsvDelimiterRegistry`, `PreviewViewModeRegistry`)**: Dynamically resolves and manages format handlers, syntax language tokens, delimiters, and view modes.
- **Service Layer (`src/Service/`)**: Encapsulates discrete domain logic (parsers, writers, security validation, permission checks, versioning).
- **Concern Traits (`src/Controller/Concerns/`)**: Encapsulates common controller operations (safe container probing, attachment lookup, layout wrapping, error handling).

```
Kanboard UI / Hook Trigger
           │
           ▼
FilePreviewController / FileEditController / FileStreamController
           │
           ├──► PermissionService (ACL Check)
           ├──► FileValidationService (Size/Extension Whitelist)
           ├──► BinaryContentDetector (Unknown Extension Classifier)
           │
           ▼
FileInteractionManager (Handler Registry)
           │
           ├──► AbstractPreviewHandler
           │      ├── TextPreviewHandler
           │      ├── JsonPreviewHandler
           │      ├── MarkdownPreviewHandler
           │      ├── CsvPreviewHandler
           │      ├── ExcelPreviewHandler
           │      ├── DocxPreviewHandler
           │      ├── PptxPreviewHandler
           │      ├── PdfPreviewHandler
           │      └── CodePreviewHandler
           │
           ▼
Safe HTML / Escaped View / Sandboxed Stream Output
```

---

## 🚀 How to Implement a New File Handler

To add support for a new previewable file format:

### Step 1: Create the Handler Class
Create `src/Handler/YourFormatPreviewHandler.php` extending `AbstractPreviewHandler`:

```php
<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

class YourFormatPreviewHandler extends AbstractPreviewHandler
{
    private const ALLOWED_EXTENSIONS = ['xyz'];

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = $this->normalizeExtension($extension);
        return in_array($normalizedExt, self::ALLOWED_EXTENSIONS, true);
    }

    public function preview(string $content, array $options = []): PreviewResult
    {
        [$truncated, $isTruncated, $originalSize] = $this->truncateContent($content);

        // ALWAYS sanitize/escape plain text or parse using a memory-safe parser
        $safeOutput = $this->escapeHtml($truncated);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($truncated),
            'lineCount' => $this->countLines($truncated),
            'charCount' => $this->countChars($truncated),
            'truncated' => $isTruncated,
            'maxSizeBytes' => $this->maxSizeBytes,
        ];

        return new PreviewResult($safeOutput, false, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'YourFormatPreviewHandler';
    }
}
```

### Step 2: Register the Handler
1. Add the extension to `FileValidationService::ALLOWED_EXTENSIONS` and configure size limits if needed.
2. Register the handler in `FilePreviewController::__construct()` in the appropriate priority order.
3. Update `Template/file/dropdown.php` and `PreviewViewModeRegistry` if necessary.

### Step 3: Write Unit Tests
Create `tests/Unit/YourFormatPreviewHandlerTest.php` to verify:
- `supports()` matching on valid extensions/MIMEs and declining foreign extensions.
- `preview()` output formatting and strict HTML escaping.
- Size truncation behavior when payloads exceed `maxSizeBytes`.

---

## 🔒 Security Rules & Best Practices

When writing or modifying code:

1. **Strict HTML Escaping**: Always wrap user-supplied plain text in `htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` or call `$this->escapeHtml($text)`.
2. **Path Traversal Defense**: Never construct filesystem paths with raw user input. Sanitize all filenames using `FileValidationService::sanitizeFilename()` or `basename()`.
3. **XML/DOM Safety**: When parsing OpenXML / XML documents, disable entity loading:
   ```php
   libxml_use_internal_errors(true);
   $dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOBLANKS);
   ```
4. **Framing & CSP**: Inline streaming endpoints must enforce `Content-Security-Policy: default-src 'none'; frame-ancestors 'self'` and `X-Content-Type-Options: nosniff`.
5. **ACL Enforcement**: Always invoke `PermissionService::assertUserCanReadFile()` before reading attachment bytes.

---

## 📐 Coding Standards

- **PHP Version**: Target PHP >= 8.1.
- **Code Style**: PSR-12 standard.
- **Strict Typing**: Every PHP file must begin with:
  ```php
  <?php

  declare(strict_types=1);
  ```
- **Namespaces**: `Kanboard\Plugin\FileInteractionCore\...` under `src/`, `Kanboard\Plugin\FileInteractionCore\Tests\...` under `tests/`.

---

## 🧪 Testing & Verification Pipeline

Before opening a pull request, ensure the entire verification pipeline succeeds:

```bash
# Run the complete agentic automated verification pipeline
composer agent-verify
# or
bash scripts/agent-verify.sh
```

Individual test runners:
```bash
# Run PHPUnit test suite
composer test

# Run PHPStan static analysis (Level 8)
composer phpstan

# Validate composer.json
composer validate --strict
```

---

## 🔀 Git Workflow & Pull Requests

1. **Fork & Branch**: Create a feature branch off `main`:
   ```bash
   git checkout -b feat/my-new-feature
   ```
2. **Conventional Commits**: Format commit messages following standard conventions:
   - `feat: <description>` for new features or format handlers
   - `fix: <description>` for bug fixes
   - `security: <description>` for security enhancements
   - `test: <description>` for test suite additions
   - `docs: <description>` for documentation updates
   - `refactor: <description>` for code refactoring
3. **Open a Pull Request**:
   - Provide a clear summary of changes.
   - Reference any related issues or feature requests.
   - Confirm all automated tests and static analysis checks pass.

Thank you for helping make `kanboard-file-interaction-core` the most secure and capable file interaction plugin for Kanboard!
