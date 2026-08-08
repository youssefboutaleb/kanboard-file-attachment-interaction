# Walkthrough — FileInteractionCore

Engineering log of implementation steps and their verification evidence.

---

## v0.4.0 — PDF Viewer Release Verification (embedded `<object>` would never have rendered)

### 1. Symptom (caught pre-release, never shipped)

Task 23 wired the PDF modal's `<object data>` to Kanboard core's `FileViewerController::download`.
Static review during Task 24 release verification flagged it before packaging.

### 2. Root cause

`download()` (`app/Controller/FileViewerController.php:146`) calls
`$this->response->withFileDownload($file['name'])`, which sets `Content-Disposition: attachment`.
No browser renders an attachment-disposition response inside an `<object>` — it opens a save
dialog instead, so spec 004 **AC-2** ("modal displays the embedded PDF document cleanly") could
never have passed.

Core's inline counterpart is `browser()`, which streams through
`FileHelper::getBrowserViewType($filename)` — that returns `application/pdf` for `.pdf`.

Confirmed live against `kanboard/kanboard:v1.2.37`, file `v2-pharma-parser.pdf` (file_id 12):

```
action=browser   -> HTTP 200 | Content-Type: application/pdf              | body starts %PDF-
action=download  -> HTTP 200 | Content-Type: application/octet-stream
                            | Content-Disposition: attachment; filename="v2-pharma-parser.pdf"
```

### 3. Fixes applied

| File | Change |
|---|---|
| `Template/file/pdf_preview.php` | Split the single URL into `$inlineUrl` (`browser`, used by `<object data>`) and `$downloadUrl` (`download`, used by the two fallback links). |
| `tests/Integration/PluginTest.php` | 5 new tests: inline-vs-download URL targeting, `rel="noopener noreferrer"` fallback, filename escaping, `project_id` omission, `.pdf` in the dropdown whitelist. |
| `tests/Integration/PluginTest.php` | `FakeUrlHelper` stub + `FakeTextHelper::bytes()` ported verbatim from core (bare suffixes: `2M`, not `2 MB`). |
| `composer.lock` | Re-hashed via `composer update --lock --no-install`; the `version` bump invalidates `content-hash` and fails `composer validate --strict`. |
| `dist/FileInteractionCore-0.3.0.zip` | Restored from git — a prior packaging run had rebuilt it with Milestone 4 code still labelled 0.3.0. |

### 4. Verification

**Live HTTP**, authenticated web session against the running instance:

```
HTTP 200 |   2190 bytes | v2-pharma-parser.pdf  (PdfPreviewHandler, size badge "1.01M")
  <object data="/?controller=FileViewerController&amp;action=browser&amp;task_id=1&amp;file_id=12&amp;project_id=1"
          type="application/pdf">
```

`sizeBytes` resolves to real content (1.01M badge), confirming the 10 MB cap is enforced against
actual bytes rather than an empty buffer.

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 186 / 186 (100%)  —  582 assertions
```

### 5. Known limitation carried into the release

Spec 004 **AC-3** asks for `sandbox="allow-same-origin allow-scripts"`, but `sandbox` is an
`<iframe>`-only attribute — `<object>` does not support it. Containment currently relies on the
browser's built-in PDF viewer plus `rel="noopener noreferrer"` on outbound links. Migrating the
container to a sandboxed `<iframe>` is the open follow-up.

---

## v0.1.1 — Browser Runtime Repair (Safe Preview returned an empty modal)

### 1. Symptom

Clicking **Safe Preview** on `.json`, `.html`, `.yml`, `.env` and `.txt`/`.md` attachments opened an
empty modal. The endpoint did **not** 500 — it answered `HTTP 200` with a **zero-byte** body, which is
why nothing surfaced in the error log and the modal simply rendered blank.

```
$ curl -s -b cookies -w '[%{http_code} | %{size_download}]' \
    "http://localhost:8085/?plugin=FileInteractionCore&controller=FilePreviewController&action=show&file_id=5&task_id=1&project_id=1"
[200 | 0]
```

### 2. Root cause

`Kanboard\Core\Base` (`app/Core/Base.php`) resolves every service through `__get()`:

```php
public function __get($name)
{
    return $this->container[$name];
}
```

It does **not** implement `__isset()`. PHP routes `isset($obj->prop)` through `__isset()`, *not*
`__get()` — so for any undeclared property, `isset()` returns `false` regardless of whether the
service exists. Proven directly against the running container:

```
isset(request)  => bool(false)
isset(response) => bool(false)
isset(template) => bool(false)
isset(container)=> bool(true)     // real declared property
```

`FilePreviewController::show()` was gated entirely on those checks:

| Guard | Intended | Actual | Consequence |
|---|---|---|---|
| `isset($this->request)` | read HTTP params | always `false` | `file_id`/`task_id`/`project_id` stayed `0` |
| `$this->container->offsetExists('taskFileModel')` | load attachment row | never reached (`$fileId === 0`) | no filename, no content |
| `isset($this->response) && isset($this->template)` | render HTML | always `false` | returned a PHP **array**; nothing echoed → 0 bytes |

So the controller degraded to previewing a nonexistent `attachment.txt` with empty content, then
returned an array that Kanboard discarded — a silent `200`/empty response.

### 3. Fixes applied

| File | Change |
|---|---|
| `src/Controller/FilePreviewController.php` | Added `hasService()`, which probes the Pimple container via `ArrayAccess` instead of `isset()`. Replaced all six dead guards. |
| `src/Controller/FilePreviewController.php` | `AccessDeniedException` / `InvalidFileException` are now caught in the HTTP context and rendered as a clean modal (`403` / `400`) instead of bubbling into a Kanboard error page. |
| `src/Controller/FilePreviewController.php` | Added `projectFileModel` support (`source=project`) and `taskFinderModel` project-id resolution for direct route access. |
| `Template/file/preview_error.php` | **New** — escaped error modal. |
| `Template/file/dropdown.php` | Fixed undefined `$task` on the project-overview hook (it passes `project` + `file`, never `task`); corrected the modal icon from `icon-eye` to `eye`; removed a duplicate `<i>` element. |
| `Plugin.php` | Removed the attach to `template:project-overview:images:dropdown` — no such hook exists in Kanboard core. |
| `tests/stubs/BaseController.php` | Now faithfully mirrors core: `__get()` **without** `__isset()`, so the runtime defect is reproducible in tests. |
| `tests/stubs/FakeContainer.php` | **New** — `ArrayAccess` container plus request/response/template/model/storage fakes. |
| `tests/Unit/FilePreviewControllerRuntimeTest.php` | **New** — 7 regression tests covering the HTTP runtime path. |

### 4. Verification

**Regression tests have teeth** — restoring the buggy `isset()` semantics fails 6 of the 7 new tests:

```
Tests: 42, Assertions: 128, Failures: 6
- 'FileInteractionCore:file/preview' vs ''   (nothing rendered)
```

**Pipeline** (`bash scripts/agent-verify.sh`):

```
✔ PHP Syntax OK          ✔ Composer Validation OK
✔ PHPStan Level 8: [OK] No errors
✔ PHPUnit: 44 / 44 (100%)  —  145 assertions
```

**Live HTTP, post `docker compose restart`**, against the pretty route the browser actually uses:

```
HTTP 200 |    8181 bytes | bons_de_livraison_example_output.json   (JsonPreviewHandler)
HTTP 200 |   62946 bytes | verox_vlm_optimization_reference.html   (TextPreviewHandler)
HTTP 200 |    1558 bytes | .env                                    (TextPreviewHandler)
HTTP 200 |   11780 bytes | deploy.yml                              (TextPreviewHandler)
HTTP 200 |   37517 bytes | compass_artifact_..._text_markdown.md   (TextPreviewHandler)
HTTP 200 |   25776 bytes | bons_de_livraison_template.json         (JsonPreviewHandler)
HTTP 200 |   38385 bytes | minimaxv2.md                            (TextPreviewHandler)
HTTP 200 |  107397 bytes | Pharma_Document_Extraction_Solution_v2.md
```

Error paths degrade cleanly instead of throwing:

```
HTTP 400 | clean error modal | .docx  (extension not allowed)
HTTP 400 | clean error modal | .pdf   (extension not allowed)
```

**XSS containment** on the 45 KB `.html` attachment: 414 escaped `&lt;` markers, `0` raw `<script`,
and exactly `3` raw `<div` — our own wrapper elements. Container log sweep: **0** PHP
warnings/notices/fatals.

### 5. Known gotcha for the next session

`docker compose restart` re-runs the Kanboard entrypoint, which executes
`chown -R nginx:nginx /var/www/app/plugins`. Because this workspace is bind-mounted there, every
restart silently re-owns it to `100:101` and host edits fail with `EACCES`. Re-run:

```bash
docker run --rm -v $(pwd):/work alpine chown -R 1000:1000 /work
```
