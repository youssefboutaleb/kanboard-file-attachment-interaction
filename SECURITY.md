# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 1.1.x | ✅ |
| 1.0.x | ❌ — see the advisory below, upgrade to 1.1.0 |
| < 1.0 | ❌ |

## Known advisory — versions before 1.1.0

Releases **0.1.0 through 1.0.1** did not verify that an attachment belonged to the
task and project named in the request URL, and their permission layer approved every
request at runtime.

Any authenticated user holding a role on **any** project could read — and through the
editor route **overwrite** — attachments belonging to projects they had no access to.

There is no configuration workaround. **Upgrade to 1.1.0.** Details are in
[CHANGELOG.md](CHANGELOG.md) and [docs/SECURITY.md](docs/SECURITY.md).

## Reporting a Vulnerability

Please **do not** open a public issue for a security problem.

Use GitHub's private reporting on this repository:
**Security → Advisories → Report a vulnerability**.

Include the affected version, the impact, and a reproduction if you have one. Expect an
acknowledgement within 7 days. Fixes ship as a patch or minor release with the issue
described in the changelog, and reporters are credited unless they ask otherwise.

## Threat model

The plugin's security design, its trust boundaries, and its explicit assumptions are
documented in [docs/SECURITY.md](docs/SECURITY.md). Anything that lets an attachment's
bytes reach a user who could not otherwise read them, or that gets attachment content
executed as active content in a viewer's browser, is in scope.
