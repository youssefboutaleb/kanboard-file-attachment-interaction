# Integrity Rules & Standards for AI Agents

All AI agents (Claude Code, Antigravity, GitHub Copilot, etc.) interacting with this repository **MUST** strictly follow these non-negotiable rules.

---

## 1. Core Integrity Rules

1. **Do Not Guess**:
   - Never invent or assume Kanboard core APIs, hooks, template names, controllers, or database schemas.
   - If uncertain, clearly mark as `UNKNOWN` or `ASSUMPTION` and outline a verification step before writing production code.

2. **Do Not Modify Kanboard Core**:
   - All functionality must reside within this plugin directory.
   - Use standard Kanboard extension points (plugin interfaces, template hooks, routes, events).

3. **Prefer Safety Over Speed**:
   - Secure file handling is paramount. Never propose or implement shortcuts that weaken security boundaries.

4. **No Execution of Untrusted Files**:
   - Uploaded files are untrusted.
   - Never execute uploaded `.html`, `.php`, `.js`, `.svg`, `.sh`, `.exe`, or macro-enabled files.
   - Never render untrusted HTML directly into the browser DOM without strict escaping or sanitization.

5. **Small, Reviewable Steps**:
   - Work in small, isolated, testable tasks.
   - Keep git diffs focused and minimal.

6. **Tests are Mandatory**:
   - Every feature handler, validation rule, and security constraint must have automated unit test coverage before declaration of completion.

7. **Documentation is Part of the Code**:
   - Update architecture docs, specs, ADRs, and `CLAUDE.md` whenever code structure, behavior, or task milestone status changes on every step.

8. **No Secrets**:
   - Never request, hardcode, or store API keys, credentials, tokens, or production connection strings.

9. **Always Produce a Plan First**:
   - Create and seek approval for an implementation plan before writing feature code.

10. **Mandatory Step Update**:
   - `CLAUDE.md` MUST be updated at the end of every completed task step to record the current progress, changed handlers, and test commands.

---

## 2. Technical Standards & Guidelines

- **Language & Runtime**: PHP >= 8.1, strict types (`declare(strict_types=1);`).
- **Coding Standard**: PSR-12 coding standard.
- **Autoloading**: PSR-4 under `Kanboard\Plugin\FileInteractionCore\`.
- **Output Escaping**: Use `htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` for all plain text renderings.
- **Path Traversal Protection**: Always wrap path references in `basename()` checks and sanitize path separators.

---

## 3. Agentic Workflow Conventions

1. **Pre-Commit Verification**:
   - Run `bash scripts/agent-verify.sh` or `composer agent-verify` before declaring any task complete.

2. **Git Commit Format**:
   - Use conventional commit messages:
     - `feat: <description>` for new feature modules
     - `fix: <description>` for bug fixes
     - `test: <description>` for test suite additions
     - `docs: <description>` for documentation updates
     - `security: <description>` for security enhancements

3. **Subagent Delegation & Task Boundaries**:
   - Work strictly within the single task assigned in the current plan phase.
   - Do not make opportunistic edits to unrelated components.
