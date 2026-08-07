---
name: kanboard-plugin-dev
description: Guidelines and verification procedures for building secure Kanboard plugins
---

# Kanboard Plugin Development Skill

This skill provides step-by-step guidance for developing, testing, and verifying Kanboard plugins without core modifications.

## 1. Extension Points

- **Plugin Entry Class**: Must extend `Kanboard\Core\Plugin\Base` in `Plugin.php`.
- **Template Hooks**:
  ```php
  $this->template->hook->attach('template:task:file:dropdown', 'fileInteractionCore:file/dropdown');
  ```
- **Routes**:
  ```php
  $this->route->addRoute('/b/:project_id/task/:task_id/file/:file_id/preview', 'FilePreviewController', 'show', 'fileInteractionCore');
  ```

## 2. Verification Checklist

1. [ ] PHP strict types declared (`declare(strict_types=1);`).
2. [ ] PSR-4 namespace matches `Kanboard\Plugin\FileInteractionCore\`.
3. [ ] All handlers implement `FileHandlerInterface`.
4. [ ] All output sanitized via `htmlspecialchars()`.
5. [ ] Paths wrapped with `basename()`.
6. [ ] Unit test written and passing in `tests/Unit`.
