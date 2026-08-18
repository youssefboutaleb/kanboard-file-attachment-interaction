# Kanboard Plugin Directory — Submission Dossier

Everything required to list **FileInteractionCore** in the official Kanboard plugin
directory, which is the `plugins.json` file in the [`kanboard/website`](https://github.com/kanboard/website)
repository and is what powers <https://kanboard.org/plugins.html> and Kanboard's in-app
plugin installer.

> **Nothing here has been submitted.** No pull request has been opened and no release has
> been published. This document is the prepared package; a maintainer performs the steps.

> ## ⛔ SUBMISSION IS BLOCKED
>
> `Assets/js/vendor/pptx-viewer.umd.js` has **unresolved provenance** — no license banner,
> no copyright, no version and no upstream identifier (see [`NOTICE`](../NOTICE)). The
> plugin redistributes it. Listing a plugin that ships unattributed third-party code
> exposes both you and the directory to a licensing complaint.
>
> **Close this first** — identify the upstream project, restore its license banner, and
> record it in `NOTICE`. Every other item below is ready.

---

## 1. Repository URL

<https://github.com/youssefboutaleb/kanboard-file-attachment-interaction>

## 2. Plugin name

**FileInteractionCore**

## 3. Plugin identifier

`FileInteractionCore`

This is the `plugins.json` object key, the value returned by `Plugin::getPluginName()`,
the PHP namespace segment (`Kanboard\Plugin\FileInteractionCore`), **and** the directory
name inside `plugins/`. Kanboard derives the plugin class from the folder name in
`Core\Plugin\Loader::scan()`, so all four must match exactly — including case.

> Note the deliberate mismatch: the **repository** is named
> `kanboard-file-attachment-interaction` while the **plugin** is `FileInteractionCore`.
> That is allowed — the identifier comes from the directory inside the archive, not from
> the repository name — but it is exactly why `remote_install` must point at a purpose-built
> release asset and never at a GitHub source archive (see §8).

## 4. Current version

`1.1.0` — declared by `Plugin::getPluginVersion()`, the single source of truth.
`CHANGELOG.md` and `tests/Integration/PackagingTest.php` both enforce agreement.

## 5. License

**MIT** (`LICENSE`, `composer.json`, and the `license` field below all agree).

Bundled third-party JavaScript is listed in [`NOTICE`](../NOTICE): JSZip 3.10.1 (MIT
option of its MIT/GPLv3 dual license) and docx-preview (Apache-2.0 — permissive and
redistributable inside an MIT work, provided its banner and attribution are preserved,
which `NOTICE` does). The third bundle is the blocker above.

## 6. Compatibility

`>=1.2.23`

Derived, not guessed: the plugin attaches to
`template:project-overview:documents:dropdown`, which core gained in **1.2.23** — it is
absent from 1.2.22. Attaching to a hook that does not exist fails silently, so an older
core would drop the project-attachment entry with no diagnostic. Every other API used
(`projectAccessMap`, `Role::PROJECT_VIEWER`, `KB.modal.replace()`, `js-modal-medium`)
predates that release. `Plugin::getCompatibleVersion()` returns the same string, so
Kanboard's `Loader` refuses the plugin cleanly on older cores.

Verified against Kanboard v1.2.53 (current release at time of writing).
Requires **PHP ≥ 8.1**; CI runs 8.1, 8.2, 8.3 and 8.4.

## 7. Release URL

    https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/releases/tag/v1.1.0

## 8. Download ZIP URL

    https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/releases/download/v1.1.0/FileInteractionCore-1.1.0.zip

This is the release **asset** built by `scripts/package-plugin.sh`, not a GitHub source
archive. The distinction is mandatory: the `kanboard/website` README explicitly warns that
GitHub archive URLs "create incorrect directory structures". A source archive would extract
to `kanboard-file-attachment-interaction-1.1.0/`, which Kanboard would load as a plugin
named `Kanboard-file-attachment-interaction-1.1.0` — the class would not resolve and the
plugin would never load.

The built asset extracts to `FileInteractionCore/`, and the packaging script fails the
build if the archive's first entry is anything else — which also matters because
`Core\Plugin\Installer::update()` reads `statIndex(0)` to decide which directory to delete
before reinstalling.

## 9. README URL

    https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/blob/main/README.md

## 10. Suggested `plugins.json` entry

Insert alphabetically **between `EssentialTheme` and `FontSwitcher`**. Keys are in the
alphabetical order every existing entry uses; all fifteen fields are required.

```json
    "FileInteractionCore": {
        "author": "Youssef BOUTALEB",
        "compatible_version": ">=1.2.23",
        "description": "Preview and edit task attachments without leaving Kanboard. Renders Word, PowerPoint, Excel, PDF, CSV, Markdown, HTML, JSON and source code in a modal, with an in-browser editor for text, code, CSV and spreadsheet attachments. Every format is parsed in pure PHP and escaped before display; images, audio and video are left to Kanboard's own viewers.",
        "download": "https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/releases/download/v1.1.0/FileInteractionCore-1.1.0.zip",
        "has_hooks": true,
        "has_overrides": false,
        "has_schema": false,
        "homepage": "https://github.com/youssefboutaleb/kanboard-file-attachment-interaction",
        "is_type": "plugin",
        "last_updated": "2026-08-18",
        "license": "MIT",
        "readme": "https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/blob/main/README.md",
        "remote_install": true,
        "title": "File Interaction Core",
        "version": "1.1.0"
    },
```

Field notes:

| Field | Value | Why |
|---|---|---|
| `has_hooks` | `true` | Attaches to `template:task-file:documents:dropdown`, `template:task-file:images:dropdown`, `template:project-overview:documents:dropdown`, `template:layout:js`, `template:layout:css`. |
| `has_overrides` | `false` | Ships only its own templates under `Template/file/`; no core template is replaced. |
| `has_schema` | `false` | No `Schema/` directory, no tables, no migrations. |
| `is_type` | `plugin` | Not an `action`, `theme` or `connector`. |
| `remote_install` | `true` | The download is a purpose-built asset with the correct root directory (§8). Set this to `false` if you ever publish only a source archive. |
| `license` | `MIT` | SPDX identifier, and the most common value in the directory (147 of 158 entries). |
| `last_updated` | release date | Must match the day the release is published. Update it on every release. |

## 11. Exact changes required in the `kanboard/website` repository

Single file: **`plugins.json`**.

1. Fork <https://github.com/kanboard/website>.
2. Branch: `git checkout -b add-fileinteractioncore`
3. Open `plugins.json` and insert the §10 object between the `"EssentialTheme"` and
   `"FontSwitcher"` keys, preserving two-space indentation.
4. Confirm the comma placement. The file is strict JSON — the entry needs a trailing
   comma because it is not last, and the final entry in the file must have none.
5. Validate before committing:
   ```bash
   python3 -m json.tool plugins.json > /dev/null && echo "valid JSON"
   ```
6. Commit, push, and open a PR against `master`.

No other file changes are needed; the directory page and the in-app installer are
generated from `plugins.json`.

## 12. Pull request title

    Add FileInteractionCore plugin

## 13. Pull request description

```markdown
Adds **FileInteractionCore** to the plugin directory.

Preview and edit task attachments without leaving Kanboard. Word, PowerPoint, Excel,
PDF, CSV, Markdown, HTML, JSON and source code render in a modal; text, code, CSV and
spreadsheet attachments can be edited in place.

- **Repository:** https://github.com/youssefboutaleb/kanboard-file-attachment-interaction
- **Release:** https://github.com/youssefboutaleb/kanboard-file-attachment-interaction/releases/tag/v1.1.0
- **License:** MIT
- **Compatible with:** Kanboard >= 1.2.23 (requires PHP >= 8.1)

Notes for reviewers:

- The download URL is a purpose-built release asset, not a GitHub source archive, so it
  extracts to `FileInteractionCore/` as the installer expects. `remote_install` is `true`.
- No database schema, no migrations, no configuration, and no Kanboard core files are
  modified.
- Images, audio and video are deliberately excluded — core already renders those, and
  excluding them keeps active content such as `.svg` out of every preview path.
- CI runs PHPUnit (770 tests) and PHPStan level 8 on PHP 8.1–8.4.
- Entry inserted alphabetically between `EssentialTheme` and `FontSwitcher`; `plugins.json`
  validates as strict JSON.
```

## 14. Maintainer considerations

**Before submitting**

- [ ] **Resolve the `pptx-viewer.umd.js` provenance blocker** and update `NOTICE`.
- [ ] Publish release `v1.1.0` and confirm the asset URL in §8 returns HTTP 200.
- [ ] Download that asset and install it into a clean Kanboard ≥ 1.2.23 — through the
      admin UI's remote installer, which is the path `remote_install: true` promises.
- [ ] Confirm the plugin appears under **Settings → Plugins** with version `1.1.0`.

**There is no code review.** The Kanboard documentation states plainly that there is no
approval process for the directory — a merged PR publishes the entry as-is. Correctness is
entirely the submitter's responsibility, which is why §14 is a checklist rather than a
formality.

**Ongoing obligations**

- Every release needs a `plugins.json` PR updating `version`, `download` and
  `last_updated`. Kanboard's installer compares the directory's `version` against the
  installed one to offer updates, so a stale entry means users are never prompted.
- Keep `compatible_version` truthful. Raise it only when a genuinely newer core API is
  adopted; lower it only after testing against the older release.
- Because `remote_install` is `true`, the download URL must stay reachable at that exact
  address forever. Deleting or renaming a release asset breaks installs for everyone who
  has not yet upgraded.

**Naming**

`title` is `"File Interaction Core"` (human-readable) while the key and directory name are
`FileInteractionCore`. Do not change the key after listing — Kanboard matches installed
plugins to directory entries by that identifier, and renaming it orphans every existing
install from update notifications.
