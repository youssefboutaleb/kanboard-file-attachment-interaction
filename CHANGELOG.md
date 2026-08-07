# Changelog

All notable changes to `kanboard-file-interaction-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.1.0] - 2026-08-07

### Added
- **Core Strategy & Registry Architecture**:
  - `FileHandlerInterface` contract for file preview handlers.
  - `PreviewResult` immutable value object for preview output and metadata.
  - `FileInteractionManager` central registry for format handler resolution and forced format overrides.
- **Format Handlers**:
  - `TextPreviewHandler`: Safe read-only preview for `.txt`, `.md`, `.env`, `.ini`, `.conf`, `.yaml`, `.yml`, `.xml`, `.log`, `.html`, `.htm`.
  - `JsonPreviewHandler`: Safe JSON validation, 2-space pretty printing, recursion depth limit (500 KB / 512 depth), and friendly invalid JSON error reporting.
- **Security & Validation Services**:
  - `FileValidationService`: Strict extension whitelisting, `basename()` path traversal protection, null-byte rejection, 500 KB file size limit enforcement, and MIME type validation.
  - `PermissionService` & `PermissionCheckerInterface`: ACL authorization abstraction for project, task, and file read access.
  - `MockPermissionChecker`: In-memory permission checker for decoupled unit testing.
- **UI Integration & Controllers**:
  - `FilePreviewController`: Handles preview route requests `/b/:project_id/task/:task_id/file/:file_id/preview`.
  - `Template/file/dropdown.php`: Injects "Safe Preview" modal action link into task attachment dropdown menus.
  - `Template/file/preview.php`: Renders modal preview dialog displaying filename, handler badge, line/char counts, and HTML-escaped content.
- **Agentic Infrastructure & Quality Gates**:
  - `scripts/agent-verify.sh`: Verification script running PHP syntax check, composer validation, PHPStan Level 8 static analysis, and PHPUnit unit tests (with automatic Docker fallback).
  - `.githooks/pre-commit` & `.githooks/pre-push`: Automated git pre-commit and pre-push security hooks.
  - `docker-compose.yml`: Live Kanboard testing environment mapped to port `8085`.
  - `scripts/package-plugin.sh`: Packaging script for building release ZIP archives (`dist/FileInteractionCore-0.1.0.zip`).
- **Comprehensive Documentation**:
  - `AGENTS.md`, `CLAUDE.md`, `SECURITY.md`, `ARCHITECTURE.md`, `ROADMAP.md`, `docs/specs/001-safe-text-preview.md`, and `docs/MANUAL_TESTING.md`.
