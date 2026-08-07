# ADR 0002: Handler Registry Pattern for File Formats

- **Status**: Accepted
- **Date**: 2026-08-07

---

## Context & Problem Statement

Kanboard supports dozens of file attachment extensions and MIME types. If file processing logic is embedded inside a single monolith controller or utility function, adding support for new file formats becomes error-prone and violates the Open/Closed Principle.

## Decision Drivers

- Need for modularity and easy extension for future file types (CSV, PDF, Excel).
- Need to isolate format-specific validation and sanitization rules.
- Ability to unit test file handlers independently without loading Kanboard UI dependencies.

## Decision

We adopt the **Strategy & Registry Pattern**:
1. `FileHandlerInterface` defines the uniform contract for all handlers.
2. `FileInteractionManager` acts as the central registry, resolving the appropriate handler by extension and MIME type.

## Consequences

- **Positive**: Adding a new file format requires creating a single handler class and registering it, with zero modifications to existing controllers or handlers.
- **Negative**: Adds a minor abstraction layer (`FileInteractionManager`).
