# Architecture Overview

`kanboard-file-interaction-core` follows a clean, modular architecture based on the **Strategy Pattern** and **Registry Pattern**.

---

## High-Level Component Diagram

```
+-------------------------------------------------------------------+
|                  Kanboard UI / Controller                         |
+-------------------------------------------------------------------+
                                  |
                                  v
+-------------------------------------------------------------------+
|                    FilePreviewController                          |
+-------------------------------------------------------------------+
          |                                       |
          v                                       v
+-----------------------+               +-------------------+
|   PermissionService   |               | FileValidationSvc |
+-----------------------+               +-------------------+
          |                                       |
          +-------------------+-------------------+
                              |
                              v
             +---------------------------------+
             |     FileInteractionManager      |
             +---------------------------------+
               |             |             |
               v             v             v
        +-----------+  +-----------+  +-----------+
        |  Text     |  |   Json    |  |  Future   |
        |  Handler  |  |  Handler  |  |  Handlers |
        +-----------+  +-----------+  +-----------+
```

---

## Core Interfaces & Abstractions

### 1. `FileHandlerInterface`
Contract implemented by every format handler:
- `supports(string $extension, string $mimeType): bool`
- `preview(string $content, array $options = []): PreviewResult`
- `getHandlerName(): string`

### 2. `PreviewResult`
An immutable value object containing:
- `$content`: Safe formatted plain text output.
- `$isFormatted`: Boolean indicating if syntax/pretty-formatting was applied.
- `$metadata`: Array of file/handler metadata (line count, character count, encoding).

### 3. `FileInteractionManager`
Central registry that registers handlers and resolves the appropriate handler based on file metadata.

### 4. `FileValidationService`
Gatekeeper responsible for checking file extension whitelists, file size boundaries, and path safety.

### 5. `PermissionService`
Wrapper around Kanboard core ACL systems to ensure uniform permission enforcement.
