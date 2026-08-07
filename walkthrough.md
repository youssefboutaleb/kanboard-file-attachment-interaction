# Walkthrough — FileInteractionCore

Engineering log of implementation steps and their verification evidence.

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
