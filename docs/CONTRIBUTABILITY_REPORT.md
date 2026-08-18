# Contributability Report — FileInteractionCore

Audit date: 2026-08-18 · Audited version: 1.0.1 → **1.1.0** · Auditor role: external Kanboard maintainer / PHP reviewer

---

## Scope correction

The audit brief repeatedly described a **Draw.io integration** — postMessage security, origin
validation, iframe diagram editing, "malicious Draw.io payloads", "multiple diagrams" tests.
**No such integration exists in this repository.** `grep -rli 'draw\.io|drawio|diagrams\.net|postMessage'`
across all PHP, JS, CSS and Markdown returns nothing.

The actual plugin is an attachment **preview and edit** framework (text, JSON, CSV, Markdown,
code, HTML, PDF, XLSX, DOCX, PPTX). Markdown *is* present, so that portion of the brief applied
directly. The Draw.io sections were substituted with the equivalent real risk surfaces:
the sandboxed-iframe HTML preview, the inline binary streaming route, and the OpenXML parsers.

---

## Executive summary

> ## READY

Two **critical**, independently exploitable authorization defects were found and fixed, with
regression tests proven to fail against the pre-fix source. Code quality, escaping discipline,
architecture and test depth are genuinely above average for a Kanboard plugin.

The one blocker raised during the audit — an unattributed bundle in `Assets/js/vendor/` — was
resolved when the maintainer confirmed `pptx-viewer.umd.js` is their own work. It now carries
an MIT banner and a `NOTICE` entry. **No blockers remain.**

Everything below is fixed unless explicitly marked otherwise. The remaining items are
follow-ups, not obstacles to release or to directory submission.

---

## Findings

### CRITICAL-1 · Security · Cross-project attachment disclosure and overwrite (IDOR) — **FIXED**

**Problem.** Kanboard authorizes these routes through `projectAccessMap`, which
`ProjectAuthorization` evaluates against the `project_id` **in the URL**. It proves the caller
holds a role on that project and inspects nothing about `file_id`. All three controllers
loaded attachments with `getById($fileId)` — keyed on the id alone — and backfilled ownership
only when the URL value was `0`:

```php
$taskId    = $taskId > 0 ? $taskId : (int) ($file['task_id'] ?? 0);
$projectId = $projectId > 0 ? $projectId : (int) ($file['project_id'] ?? 0);
```

On the real pretty routes `project_id` is always present, so the URL value always won and the
attachment's true owner was never compared to anything.

**Why it matters.** Any authenticated user with a role on *any* project could pair a project
they legitimately belong to with a foreign attachment id:

```
/b/<project the caller can access>/task/<any>/file/<file in a FOREIGN project>/preview
```

The ACL passed and the foreign file was previewed, streamed, opened in the editor, or — via
`FileEditController::update()` — **overwritten**. Confidentiality *and* integrity, across the
entire installation.

**Fix.** `HandlesAttachmentInteraction::assertAttachmentOwnership()` joins `file_id` to the
task and project named in the URL before any bytes are read, on all four actions. Ownership
columns are database-controlled, never request-controlled, so they cannot be forged.

**Verified exploitable:** 7 of 12 new tests fail against the pre-fix source, returning HTTP 200
with the foreign file's content.

### CRITICAL-2 · Security · Plugin ACL layer was inert at runtime — **FIXED**

**Problem.** `PermissionService::__construct()` defaulted to `new MockPermissionChecker(true)`
— an allow-everything test stub — and every controller wrote
`$permissionService ?? new PermissionService()`. No production code path ever injected anything
else, so `assertUserCanReadFile()` and `canUserWriteFile()` returned `true` for every request
the plugin ever served. `docs/SECURITY.md:117` documented a guarantee that did not exist.

**Why it matters.** The plugin's own defence-in-depth layer contributed nothing; only Kanboard's
route ACL was real, and CRITICAL-1 shows why that alone was insufficient.

**Fix.** New `KanboardPermissionChecker`, backed by `projectPermissionModel` and `userSession`,
installed automatically whenever the container provides them. It **fails closed**: a checker
that cannot reach the models it needs answers "no".

### HIGH-1 · Security · Read access implied write access — **FIXED**

`canUserWriteFile()` was a straight alias of `canUserReadFile()`, so a project **viewer**
satisfied the write gate. Core's `projectAccessMap` registration limited the edit routes to
`PROJECT_MEMBER`, so this was defence-in-depth rather than directly exploitable — but the
plugin's own layer disagreed with core's. Write now requires `projectPermissionModel->isMember()`.

### HIGH-2 · Licensing · Bundle redistributed with no license or attribution — **FIXED**

**Problem.** `Assets/js/vendor/pptx-viewer.umd.js` (110 KB, minified) carried no license
banner, no copyright, no version and no upstream URL — unlike its two siblings in the same
directory, which carry proper banners. It is redistributed in every release archive.

**Why it mattered.** Redistributing code of unknown origin risks a licensing complaint, and
listing the plugin would have extended that risk to the directory. The audit deliberately did
**not** invent an attribution: an unverifiable license claim is worse than an absent one.

**Resolution.** The maintainer confirmed the file is **first-party** — their own work, covered
by the project's MIT license. It now carries an MIT banner naming the copyright holder, and
`NOTICE` records it as first-party rather than vendored.

**The underlying lesson stands even though the answer was benign:** nothing in the repository
said the file was first-party, and a minified, unbannered blob inside a directory named
`vendor/` reads as someone else's code to every reviewer — which is exactly how it was read
here. `PackagingTest::testBundledVendorJavaScriptIsAttributed()` now fails the build if any
file in `Assets/js/vendor/` lacks a `NOTICE` entry.

### HIGH-3 · Licensing · License metadata contradicted the LICENSE file — **FIXED**

The `LICENSE` file and the project metadata disagreed: `LICENSE` carried the full Apache-2.0
text while `composer.json` and the README declared MIT, and the license boilerplate placeholder
`[name of copyright owner]` was never filled in. `plugins.json` requires a `license` field, so a
submission would have declared something untrue.

Resolved to **MIT** throughout at the maintainer's direction — `LICENSE` replaced with the MIT
text, and all metadata aligned to it. MIT is also the directory norm (147 of 158 entries).
`PackagingTest::testLicenseMetadataIsConsistent()` now fails the build if the LICENSE file and
`composer.json` ever drift apart again, which is the actual defect here: nothing was checking.

Bundled docx-preview remains Apache-2.0. That is compatible — Apache-2.0 is permissive and may
be redistributed inside an MIT work provided its license and attribution are preserved, which
`NOTICE` does. It does **not** make the project dual-licensed.

### MEDIUM-1 · Compatibility · No `getCompatibleVersion()` — **FIXED**

Without the override, `Base::getCompatibleVersion()` returns `APP_VERSION`, which claims
compatibility with whatever core is running. Meanwhile the plugin attaches to
`template:project-overview:documents:dropdown`, which core gained in **1.2.23** (bisected: absent
from 1.2.22). Hooks fail silently when they do not exist, so an older core produced a
half-working plugin with no diagnostic. Now declares `>=1.2.23`.

### MEDIUM-2 · Packaging · Release archive shipped development files — **FIXED**

The exclude-based `rsync` shipped `CLAUDE.md` (38 KB), `AGENTS.md`, `implementation_plan.md`,
`settings.json`, `.phpunit.result.cache`, `composer.lock` and **154 KB of `walkthrough.md`** to
end users — roughly 200 KB of AI-agent scratch notes in every install. A deny-list fails open:
any new root file ships until someone remembers to exclude it. Rewritten as an **allow-list**,
with build-time assertions that no dev file leaked in, that the archive root entry is
`FileInteractionCore/` (required by `Installer::update()`'s `statIndex(0)`), and that `src/`
does not reference the excluded `tests/`.

### MEDIUM-3 · Correctness · Duplicate JS caused double modal reloads — **FIXED**

`Assets/js/preview-language-selector.js` was a verbatim earlier copy of the language-picker
handler already in `preview-controls.js`. **Both were registered in `Plugin.php`**, and both
bound a delegated `change` listener on `document` matching `[data-fic-language-select]`. One
language change therefore fired **two** `KB.modal.replace()` calls — two full round trips, and
a race over which response painted last. File deleted, registration removed, tests retargeted.

### MEDIUM-4 · Maintainability · Production code required a test stub — **FIXED**

`FilePreviewController` and `FileStreamController` each began with
`require_once __DIR__ . '/../../tests/stubs/BaseController.php'` behind a `class_exists()` guard.
Inside Kanboard the branch never fires, so it looked harmless — but `tests/` is excluded from
the archive, so the fallback could only ever fatal. Moved to `tests/bootstrap.php`.

### MEDIUM-5 · Compatibility · PHP 9 forward-compatibility — **FIXED**

Four `str_getcsv()` / `fputcsv()` calls omitted `$escape`, which PHP 8.4 deprecates because the
default changes in PHP 9 — a silent CSV parsing behaviour change on upgrade. The **current**
default is now passed explicitly, preserving today's behaviour exactly.

### LOW-1 · Tooling · Patch script reported success on total failure — **FIXED**

`patch_pptx_viewer.js` printed `"orig not found!"` six times and still exited **0** with
`"ALL PPTX FIXES APPLIED SUCCESSFULLY!"`. Because the vendored bundle is committed
already-patched, the script has been unrunnable since it was written and nothing noticed. It
now exits non-zero and writes nothing when a target is missing.

### LOW-2 · CI · Static analysis and packaging were never run in CI — **FIXED**

CI ran only `composer validate` and PHPUnit. PHPStan level 8 — advertised in the README — was
never enforced, nor was the release archive ever built on a PR. Added PHPStan, `php -l`, PHP 8.4
to the matrix, and a job that builds and extracts the archive. Also added `ext-zip`, which
un-skips 2 previously-skipped tests.

### LOW-3 · Repository · Missing community health files — **FIXED**

No root `SECURITY.md` (so GitHub offered no private reporting path — for a plugin with a live
authorization defect), no issue templates, no PR template. All added; `SECURITY.md` carries an
advisory for versions below 1.1.0.

### INFORMATIONAL-1 · Architecture · `src/` layout deviates from Kanboard convention — **NOT CHANGED**

`Loader::scan()` registers `addPsr4('Kanboard\Plugin\', PLUGINS_DIR)`, so Kanboard's convention
is `plugins/<Name>/Controller/Foo.php`. This plugin keeps sources under `src/` and compensates
with its own `spl_autoload_register()` at the top of `Plugin.php`, which runs before any
controller resolves. **It works**, and moving ~40 files would be a large restructure with real
regression risk for no functional gain, so it was left alone per the brief's "do not replace the
architecture" rule. Worth knowing: any external tool that relies on Kanboard's own PSR-4 mapping
will not find these classes.

### INFORMATIONAL-2 · Security · HTML preview isolation is correct — **NO ACTION**

`Template/file/html_preview.php` renders untrusted HTML via `<iframe sandbox="" srcdoc="...">`
with the content `htmlspecialchars()`-escaped into the attribute. No `allow-scripts`, no
`allow-same-origin` — scripts cannot run and the frame has an opaque origin. Correct as written.

### INFORMATIONAL-3 · Security · Inline streaming allow-list is correctly narrow — **NO ACTION**

`FileStreamController::INLINE_MIME_TYPES` admits only PDF and OpenXML, enforces magic-byte
checks, sets `nosniff`, `default-src 'none'; frame-ancestors 'self'`, `Cache-Control: private`,
and sanitizes the `Content-Disposition` filename against header injection. `.html` and `.svg`
are deliberately absent. This is the single best-reasoned area of the codebase.

### INFORMATIONAL-4 · Repository · Agent scaffolding remains tracked — **NOT CHANGED**

`CLAUDE.md`, `AGENTS.md`, `walkthrough.md` (154 KB), `implementation_plan.md`, `settings.json`
and `.agents/` are still committed. They no longer ship to users, which was the material
problem. `CLAUDE.md` is mandated by the project's own rules, so it was not touched. You may
wish to prune or relocate `walkthrough.md` and `implementation_plan.md` — a first-time visitor
reads them as project documentation.

### INFORMATIONAL-5 · Escaping discipline — **NO ACTION**

Template output is consistently `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` or
`$this->text->e()`. Sheet/slide names are paired on `data-*` **values** rather than DOM ordinals,
and JS writes `textContent`, never `innerHTML`, for already-escaped strings. CSS is fully
`.fic-`-namespaced with no bare global selectors. OpenXML parsers use `LIBXML_NONET`. CSV
delimiters travel as opaque tokens, never raw characters. No SQL is written by the plugin.

---

## Changes made

**Security**
- `src/Service/KanboardPermissionChecker.php` *(new)* — real ACL, fails closed, adds `canWriteProject()`.
- `src/Controller/Concerns/HandlesAttachmentInteraction.php` — added `assertAttachmentOwnership()` and `createDefaultPermissionService()`.
- `src/Controller/{FilePreview,FileStream,FileEdit}Controller.php` — ownership gate on all four actions; real checker wired in.
- `src/Service/PermissionService.php` — write is no longer an alias of read.

**Metadata & licensing**
- `Plugin.php` — version `1.1.0`, added `getCompatibleVersion() => '>=1.2.23'`, removed duplicate JS registration.
- `LICENSE` / `composer.json` / `README.md` — aligned on **MIT**; `PackagingTest` now enforces agreement.
- `NOTICE` *(new)* — third-party attribution; records the unresolved bundle.

**Packaging & CI**
- `scripts/package-plugin.sh` — rewritten as an allow-list with build-time assertions.
- `scripts/patch_pptx_viewer.js` — fails loudly; documents what it does and why it is unrunnable.
- `.github/workflows/ci.yml` — PHPStan, `php -l`, PHP 8.4, `ext-zip`, archive build + extract.
- `SECURITY.md`, `.github/pull_request_template.md`, `.github/ISSUE_TEMPLATE/*` *(new)*.

**Correctness**
- Deleted `Assets/js/preview-language-selector.js`.
- Removed `tests/stubs` requires from two controllers; added `tests/bootstrap.php`; `phpunit.xml` retargeted.
- Pinned `$escape` on four `str_getcsv()`/`fputcsv()` calls.

**Tests** *(+19: 751 → 770)*
- `tests/Unit/AttachmentAuthorizationTest.php` *(new, 12)* — IDOR and ACL regression.
- `tests/Integration/PackagingTest.php` *(new, 7)* — archive shape, version/changelog agreement, NOTICE coverage.
- `tests/stubs/FakeContainer.php` — added `FakeTaskFinder`, `FakeProjectPermissionModel`.
- Retargeted `LanguageSelectorTemplateTest`; updated `PluginTest`.

**Documentation**
- `docs/SECURITY.md` — removed the false ACL claim; added the object-level authorization section with explicit assumptions and a version history note.
- `README.md` — Requirements, Upgrading, Uninstallation, Limitations, Troubleshooting, Release Process; corrected the stale v0.9.0 ZIP name, the wrong Docker port (8080 → 8085), the compatibility badge, and the roadmap.
- `docs/kanboard-directory-submission.md` *(new)*, `CHANGELOG.md`, `CLAUDE.md`.

---

## Tests executed

| Command | Result |
|---|---|
| `docker run php:8.1-cli vendor/bin/phpunit` (baseline, pre-audit) | **751 passed**, 2 skipped |
| Same, against pre-fix source with new tests | **7 of 12 failed** — vulnerability confirmed exploitable |
| `vendor/bin/phpunit` on PHP 8.1 / 8.2 / 8.3 / 8.4 | **770 passed** on all four |
| `vendor/bin/phpunit` with `ext-zip` (CI config) | **770 passed, 2619 assertions, 0 skipped** |
| `vendor/bin/phpstan analyse src --level=8` | **No errors** |
| `composer validate --strict` | Valid |
| `bash scripts/agent-verify.sh` | Complete, all stages pass |
| `bash scripts/package-plugin.sh` | `dist/FileInteractionCore-1.1.0.zip`, 240 KB, 86 files, root `FileInteractionCore/` |

**Observations.** The pre-fix run is the important one: the foreign attachment returned HTTP 200
with its content, so these are genuine regression tests, not tests written to pass. The 2 tests
skipped in every previous run needed `ext-zip`, absent from the bare `php:8.1-cli` image — they
exercise real `.xlsx` parsing and had therefore **never run in CI**. They now do, and pass.

---

## Remaining issues

None block release or submission. In rough priority order:

1. **`pptx-viewer.umd.js` ships minified-only.** Now correctly licensed, but the source is not
   in the repository, so contributors cannot read or modify it — and `scripts/patch_pptx_viewer.js`
   exists precisely because six fixes had to be applied to the build artifact after the fact.
   Committing the source and folding those six patches into it would let that script be deleted.
   Housekeeping, not a defect.
2. **It also sits in `Assets/js/vendor/` despite being first-party.** The banner and `NOTICE`
   now say so, but the directory name still implies otherwise. Moving it would touch
   `Plugin.php` and `office-viewer.js`; left alone deliberately, since renaming working asset
   paths for tidiness is exactly the kind of change this audit was asked not to make.
3. **No browser-level test coverage.** Client-side behaviour is pinned textually (asserting the
   shipped `.js` contains the expected calls), never executed. Adequate for a plugin this size
   and consistent with existing practice, but the JavaScript is genuinely untested.
4. **Not yet verified in a live Kanboard.** All verification was static plus unit/integration.
   The ownership gate and the real ACL checker should be exercised once against
   `docker compose up` with two projects and a non-member user before tagging.
5. **`src/` layout deviates from Kanboard's PSR-4 convention** (INFORMATIONAL-1) — works, but
   worth a decision if first-class tooling compatibility ever matters.
6. **Agent scaffolding still tracked** (INFORMATIONAL-4) — cosmetic.
7. **PHPStan 1.12 is ~13 months old.** Upgrading to 2.x will likely surface new findings; a
   deliberate, separate piece of work.

## Official directory readiness

The full dossier is [`docs/kanboard-directory-submission.md`](kanboard-directory-submission.md):
repository URL, identifier, version, license, compatibility, release and download URLs, a
validated `plugins.json` entry, exact `kanboard/website` repository steps, PR title and body,
and maintainer considerations.

Everything is prepared and validated — the embedded JSON parses and its field set matches the
directory schema exactly (all 15 fields, alphabetically ordered, inserting between
`EssentialTheme` and `FontSwitcher`).

**To submit, in order:**

1. Commit this work and push.
2. `git tag v1.1.0 && git push origin v1.1.0` — the release workflow verifies the tag against
   `Plugin.php`, rebuilds the archive, extracts the changelog section and publishes the asset.
3. Confirm the download URL returns HTTP 200, then install that exact asset into a clean
   Kanboard ≥ 1.2.23 **through the admin UI's remote installer** — that is the path
   `remote_install: true` promises, and the only way to prove the archive's directory shape.
4. Open the `kanboard/website` PR using the prepared title and body.

Note that Kanboard's documentation states there is **no code review and no approval process**
for the directory — a merged PR publishes the entry as-is. Correctness is entirely the
submitter's responsibility, which is why step 3 is not optional.
