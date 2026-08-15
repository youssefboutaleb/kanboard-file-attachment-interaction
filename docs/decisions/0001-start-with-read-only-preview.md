# ADR 0001: Start with Read-Only Preview

- **Status**: Accepted
- **Date**: 2026-08-07

---

## Context & Problem Statement

Kanboard attachments can contain sensitive or hostile content. Introducing file editing, rich rendering, or complex binary parsers early increases the surface area for security vulnerabilities (XSS, arbitrary file writes, privilege escalation).

## Decision Drivers

- Need for maximum safety when dealing with untrusted user uploads.
- Desirable to establish robust security and architectural foundations first.
- Need for small, reviewable, testable iterations.

## Decision

We will start with **Milestone 1: Read-Only Preview for Simple Text Files (`.txt`, `.json`, `.md`)**.
File editing, rich HTML rendering, PDF annotation, and Excel parsing are explicitly postponed to future milestones.

## Consequences

- **Positive**: Eliminates risk of accidental file corruption/overwrites on server. Minimizes XSS attack vectors.
- **Negative**: Users cannot modify file contents within Kanboard during Milestone 1.
