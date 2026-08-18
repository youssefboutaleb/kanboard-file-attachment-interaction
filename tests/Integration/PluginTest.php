<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use PHPUnit\Framework\TestCase;

// Define Base class stub for standalone testing without Kanboard core
if (!class_exists('Kanboard\Core\Plugin\Base')) {
    abstract class BaseStub
    {
        protected $container;
        protected $route;
        protected $template;

        public function __construct($container)
        {
            $this->container = $container;
            $this->route = $container->route ?? null;
            $this->template = $container->template ?? null;
        }
    }

    class_alias(BaseStub::class, 'Kanboard\Core\Plugin\Base');
}

require_once __DIR__ . '/../../Plugin.php';
// Kanboard exposes t() globally for template translation
require_once __DIR__ . '/../stubs/TemplateFunctions.php';

use Kanboard\Plugin\FileInteractionCore\Plugin;
use Kanboard\Plugin\FileInteractionCore\Service\FileEditValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;

class PluginTest extends TestCase
{
    private Plugin $plugin;

    protected function setUp(): void
    {
        $container = new \stdClass();
        $container->route = new class {
            public function addRoute(string $path, string $controller, string $action, string $plugin): void
            {
            }
        };
        $container->template = new class {
            public $hook;
            public function __construct()
            {
                $this->hook = new class {
                    public function attach(string $hook, string $template): void
                    {
                    }
                };
            }
        };

        $this->plugin = new Plugin($container);
    }

    public function testPluginMetadata(): void
    {
        $this->assertSame('FileInteractionCore', $this->plugin->getPluginName());
        $this->assertSame('1.1.0', $this->plugin->getPluginVersion());
        $this->assertSame('Youssef BOUTALEB', $this->plugin->getPluginAuthor());
        $this->assertSame('https://github.com/youssefboutaleb/kanboard-file-attachment-interaction', $this->plugin->getPluginHomepage());
        $this->assertNotEmpty($this->plugin->getPluginDescription());

        // Kanboard's Loader refuses to initialize a plugin whose compatibility
        // expression does not match APP_VERSION. Without an override the inherited
        // default is APP_VERSION itself, which claims compatibility with whatever
        // core happens to be running — including releases predating the hooks this
        // plugin attaches to.
        $this->assertSame('>=1.2.23', $this->plugin->getCompatibleVersion());
    }

    public function testPluginInitialization(): void
    {
        $this->plugin->initialize();
        $this->assertTrue(true);
    }

    /**
     * The dropdown template gates the "Safe Preview" entry point with its own
     * extension list; if it drifts from the validator whitelist, previewable
     * attachments silently lose their menu item (or offer one that 400s).
     */
    public function testDropdownTemplateWhitelistMatchesValidationService(): void
    {
        $template = file_get_contents(__DIR__ . '/../../Template/file/dropdown.php');
        $this->assertNotFalse($template);

        $matched = preg_match('/\$allowedExtensions\s*=\s*\[(.*?)\];/s', $template, $matches);
        $this->assertSame(1, $matched, 'dropdown.php must declare an $allowedExtensions array.');

        foreach (FileValidationService::ALLOWED_EXTENSIONS as $extension) {
            $this->assertStringContainsString(
                "'" . $extension . "'",
                $matches[1],
                "dropdown.php is missing the validated extension .{$extension}"
            );
        }
    }

    public function testDropdownTemplateExposesTabularExtensions(): void
    {
        $template = file_get_contents(__DIR__ . '/../../Template/file/dropdown.php');
        $this->assertNotFalse($template);

        $this->assertStringContainsString("'csv'", $template);
        $this->assertStringContainsString("'tsv'", $template);
    }

    public function testDropdownTemplateExposesMarkdownAndCodeExtensions(): void
    {
        $template = file_get_contents(__DIR__ . '/../../Template/file/dropdown.php');
        $this->assertNotFalse($template);

        foreach (['markdown', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql'] as $extension) {
            $this->assertStringContainsString("'" . $extension . "'", $template);
        }
    }

    /**
     * Render the real template file against a Markdown preview payload.
     *
     * $content carries pre-sanitized HTML from MarkdownParserService, so the view
     * must emit it verbatim — double-escaping would surface literal "&lt;h1&gt;".
     */
    public function testMarkdownPreviewTemplateEmitsSanitizedHtmlUnescaped(): void
    {
        $output = $this->renderTemplate('markdown_preview', [
            'filename' => 'README.md',
            'handler' => 'MarkdownPreviewHandler',
            'content' => '<h1>Title</h1>' . "\n" . '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            'metadata' => [
                'headingCount' => 1,
                'lineCount' => 2,
                'charCount' => 40,
                'codeBlockCount' => 0,
                'truncated' => false,
                'maxSizeBytes' => 524288,
            ],
        ]);

        // Sanitized markup renders as real HTML
        $this->assertStringContainsString('<h1>Title</h1>', $output);
        // The neutralized payload stays neutralized and is never re-activated
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        // Escaped-once, not twice
        $this->assertStringNotContainsString('&amp;lt;', $output);
    }

    public function testMarkdownPreviewTemplateEscapesUntrustedFilename(): void
    {
        $output = $this->renderTemplate('markdown_preview', [
            'filename' => '<img src=x onerror=alert(1)>.md',
            'handler' => 'MarkdownPreviewHandler',
            'content' => '<p>body</p>',
            'metadata' => ['headingCount' => 0, 'lineCount' => 1, 'codeBlockCount' => 0],
        ]);

        $this->assertStringNotContainsString('<img src=x', $output);
        $this->assertStringContainsString('&lt;img src=x', $output);
    }

    public function testMarkdownPreviewTemplateRendersCodeViewVariant(): void
    {
        $output = $this->renderTemplate('markdown_preview', [
            'filename' => 'deploy.sh',
            'handler' => 'CodePreviewHandler',
            'content' => '<pre class="code-highlight language-sh"><code>echo hi</code></pre>',
            'metadata' => ['language' => 'sh', 'lineCount' => 1, 'charCount' => 7],
        ]);

        $this->assertStringContainsString('fa-code', $output);
        $this->assertStringContainsString('class="code-highlight language-sh"', $output);

        $this->assertStringNotContainsString('CodePreviewHandler', $output);
        $this->assertStringNotContainsString('Safe Read-Only Syntax Highlighted View', $output);

        // Markdown-only statistics must not leak into the code variant
        $this->assertStringNotContainsString('Headings', $output);
    }

    public function testMarkdownPreviewTemplateShowsEmptyAndTruncationNotices(): void
    {
        $empty = $this->renderTemplate('markdown_preview', [
            'filename' => 'empty.md',
            'handler' => 'MarkdownPreviewHandler',
            'content' => '',
            'metadata' => ['headingCount' => 0, 'lineCount' => 0, 'codeBlockCount' => 0],
        ]);

        $truncated = $this->renderTemplate('markdown_preview', [
            'filename' => 'huge.md',
            'handler' => 'MarkdownPreviewHandler',
            'content' => '<p>body</p>',
            'metadata' => [
                'headingCount' => 0,
                'lineCount' => 1,
                'codeBlockCount' => 0,
                'truncated' => true,
                'maxSizeBytes' => 524288,
            ],
        ]);

        $this->assertStringContainsString('The Markdown document is empty.', $empty);
        $this->assertStringNotContainsString('alert-warning', $empty);
        $this->assertStringContainsString('alert-warning', $truncated);
        $this->assertStringContainsString('512', $truncated);
    }

    /**
     * A top-level "version" field makes `composer validate --strict` exit
     * non-zero ("recommended to leave it out"), which broke the CI pipeline on
     * every release bump. Plugin.php::getPluginVersion() is the single source
     * of truth; Composer derives package versions from git tags.
     */
    public function testComposerJsonDeclaresNoVersionField(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../composer.json');
        $this->assertNotFalse($raw);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey(
            'version',
            $composer,
            'composer.json must not declare "version" — it fails composer validate --strict.'
        );
    }

    /**
     * The packaging script names the archive after Plugin.php's version. If it
     * ever drifts back to composer.json, releases would be built as 1.0.0.
     */
    public function testPackagingScriptDerivesVersionFromPluginPhp(): void
    {
        $script = file_get_contents(__DIR__ . '/../../scripts/package-plugin.sh');
        $this->assertNotFalse($script);

        $this->assertStringContainsString('getPluginVersion', $script);
        $this->assertStringNotContainsString('composer.json 2>/dev/null | cut', $script);

        // Replicate the shell extraction in PHP and confirm both agree
        $pluginSource = file_get_contents(__DIR__ . '/../../Plugin.php');
        $this->assertNotFalse($pluginSource);

        $matched = preg_match(
            "/function getPluginVersion.*?return '([^']+)'/s",
            $pluginSource,
            $matches
        );

        $this->assertSame(1, $matched, 'Plugin.php must expose a literal version string.');
        $this->assertSame($this->plugin->getPluginVersion(), $matches[1]);
    }

    /**
     * Release archives are published as GitHub Release assets, never committed.
     */
    public function testDistArtifactsAreGitIgnored(): void
    {
        $gitignore = file_get_contents(__DIR__ . '/../../.gitignore');
        $this->assertNotFalse($gitignore);

        $this->assertMatchesRegularExpression('/^dist\/?$/m', $gitignore);
    }

    public function testDropdownTemplateExposesPdfExtension(): void
    {
        $template = file_get_contents(__DIR__ . '/../../Template/file/dropdown.php');
        $this->assertNotFalse($template);

        $this->assertStringContainsString("'pdf'", $template);
    }

    /**
     * Spec 004 AC-2: the embedded viewer must target Kanboard core's `browser`
     * action, which streams application/pdf inline. The `download` action sets
     * Content-Disposition: attachment, so pointing <object> at it makes every
     * browser show a save dialog and the document never renders in the modal.
     */
    public function testPdfPreviewTemplateEmbedsInlineStreamActionNotDownload(): void
    {
        $output = $this->renderTemplate('pdf_preview', [
            'filename' => 'invoice.pdf',
            'handler' => 'PdfPreviewHandler',
            'taskId' => 7,
            'fileId' => 42,
            'projectId' => 3,
            'metadata' => ['isBinary' => true, 'sizeBytes' => 2097152],
        ]);

        $matched = preg_match('/<object[^>]*data="([^"]+)"[^>]*>/', $output, $matches);
        $this->assertSame(1, $matched, 'pdf_preview.php must embed an <object data="..."> viewer.');

        /**
         * Task 35: inline rendering must go through the plugin's own stream
         * action, NOT core's FileViewerController::browser. Core answers with the
         * right Content-Type but every core response also carries
         * `X-Frame-Options: DENY`, which stops the browser rendering the PDF
         * inside <object> and forces the "not supported" fallback banner.
         */
        $this->assertStringContainsString('action=inline', $matches[1]);
        $this->assertStringContainsString('FileStreamController', $matches[1]);
        $this->assertStringNotContainsString('FileViewerController', $matches[1]);
        $this->assertStringNotContainsString('action=browser', $matches[1]);
        $this->assertStringNotContainsString('action=download', $matches[1]);
        $this->assertStringContainsString('type="application/pdf"', $output);
        $this->assertStringContainsString('file_id=42', $matches[1]);
        $this->assertStringContainsString('task_id=7', $matches[1]);
    }

    /**
     * Spec 004 AC-4: a fallback download action is offered inside the <object>
     * body for browsers without an inline PDF renderer.
     */
    public function testPdfPreviewTemplateProvidesSecureDownloadFallback(): void
    {
        $output = $this->renderTemplate('pdf_preview', [
            'filename' => 'invoice.pdf',
            'handler' => 'PdfPreviewHandler',
            'taskId' => 7,
            'fileId' => 42,
            'projectId' => 3,
            'metadata' => ['isBinary' => true, 'sizeBytes' => 2097152],
        ]);

        $this->assertStringContainsString('action=download', $output);
        $this->assertStringContainsString('Download PDF Document', $output);
        // Externally opened links must not leak window.opener to the target
        $this->assertStringContainsString('rel="noopener noreferrer"', $output);
        // Human-readable size badge from the handler metadata (core renders "2M")
        $this->assertStringContainsString('>2M<', preg_replace('/\s+/', '', $output) ?? '');
    }

    /**
     * Spec 004 AC-3: attachment names are attacker-controlled and must never
     * reach the modal unescaped.
     */
    public function testPdfPreviewTemplateEscapesMaliciousFilename(): void
    {
        $output = $this->renderTemplate('pdf_preview', [
            'filename' => '<script>alert(1)</script>.pdf',
            'handler' => 'PdfPreviewHandler',
            'taskId' => 1,
            'fileId' => 2,
            'projectId' => 0,
            'metadata' => ['isBinary' => true, 'sizeBytes' => 1024],
        ]);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    /**
     * Project attachments carry no task id; the project_id parameter is only
     * appended when it is actually known.
     */
    public function testPdfPreviewTemplateOmitsUnknownProjectIdOnDownloadAction(): void
    {
        $output = $this->renderTemplate('pdf_preview', [
            'filename' => 'spec.pdf',
            'handler' => 'PdfPreviewHandler',
            'taskId' => 5,
            'fileId' => 9,
            'projectId' => 0,
            'metadata' => ['isBinary' => true, 'sizeBytes' => 512],
        ]);

        $matched = preg_match_all('/href="([^"]*action=download[^"]*)"/', $output, $matches);
        $this->assertGreaterThan(0, $matched, 'A download action must be present.');

        foreach ($matches[1] as $downloadUrl) {
            $this->assertStringNotContainsString('project_id=', $downloadUrl);
            $this->assertStringContainsString('file_id=9', $downloadUrl);
        }

        $streamMatched = preg_match('/<object[^>]*data="([^"]+)"[^>]*>/', $output, $streamMatches);
        $this->assertSame(1, $streamMatched);
        $this->assertStringContainsString('project_id=0', $streamMatches[1]);
    }

    // ---------------------------------------------------------------------
    // Task 28 — editor dropdown entry point
    // ---------------------------------------------------------------------

    /**
     * Build the variables the core task-file hook passes to our dropdown.
     *
     * @return array<string, mixed>
     */
    private function taskFileVars(string $filename): array
    {
        return [
            'task' => ['id' => 7, 'project_id' => 3],
            'file' => ['id' => 42, 'name' => $filename, 'task_id' => 7],
        ];
    }

    public function testDropdownShowsEditLinkForEditableAttachmentWithWriteAccess(): void
    {
        $output = $this->renderTemplate('dropdown', $this->taskFileVars('notes.md'));

        $this->assertStringContainsString('Edit Attachment', $output);
        $this->assertStringContainsString('FileEditController::edit', $output);
        $this->assertStringContainsString('file_id=42', $output);
        // The preview entry must survive alongside the new one
        $this->assertStringContainsString('Safe Preview', $output);
    }

    /**
     * Spec 005 AC-4: no write access, no editor entry point.
     */
    public function testDropdownHidesEditLinkWithoutWriteAccess(): void
    {
        $renderer = new FakeTemplateHelper();
        $renderer->user->setProjectAccess(false);

        $output = $renderer->render(
            __DIR__ . '/../../Template/file/dropdown.php',
            $this->taskFileVars('notes.md')
        );

        $this->assertStringNotContainsString('Edit Attachment', $output);
        // Read-only preview stays available to users who may only read
        $this->assertStringContainsString('Safe Preview', $output);
    }

    /**
     * Binary, tabular and active-content formats are previewable but must never
     * open in a plain-text editor.
     */
    public function testDropdownHidesEditLinkForNonEditableFormats(): void
    {
        foreach (['report.pdf', 'archive.exe'] as $filename) {
            $output = $this->renderTemplate('dropdown', $this->taskFileVars($filename));

            $this->assertStringNotContainsString(
                'Edit Attachment',
                $output,
                "Editor entry point must not be offered for {$filename}"
            );
        }

        // ...while a previewable non-editable format keeps its preview entry
        $pdf = $this->renderTemplate('dropdown', $this->taskFileVars('report.pdf'));
        $this->assertStringContainsString('Safe Preview', $pdf);
    }

    /**
     * FileEditController resolves attachments through taskFileModel only, so a
     * project-overview file has no editable target.
     */
    public function testDropdownHidesEditLinkForProjectOverviewAttachments(): void
    {
        $output = $this->renderTemplate('dropdown', [
            'project' => ['id' => 3],
            'file' => ['id' => 42, 'name' => 'notes.md', 'project_id' => 3],
        ]);

        $this->assertStringNotContainsString('Edit Attachment', $output);
        $this->assertStringContainsString('Safe Preview', $output);
    }

    public function testDropdownEditableListMatchesEditValidationService(): void
    {
        $template = file_get_contents(__DIR__ . '/../../Template/file/dropdown.php');
        $this->assertNotFalse($template);

        $matched = preg_match('/\$editableExtensions\s*=\s*\[(.*?)\];/s', $template, $matches);
        $this->assertSame(1, $matched, 'dropdown.php must declare an $editableExtensions array.');

        foreach (FileEditValidationService::EDITABLE_EXTENSIONS as $extension) {
            $this->assertStringContainsString(
                "'" . $extension . "'",
                $matches[1],
                "dropdown.php is missing the editable extension .{$extension}"
            );
        }
    }

    /**
     * Anything editable must also be previewable; the reverse must NOT hold.
     */
    public function testEditableExtensionsAreStrictSubsetOfAllowedExtensions(): void
    {
        foreach (FileEditValidationService::EDITABLE_EXTENSIONS as $extension) {
            $this->assertContains(
                $extension,
                FileValidationService::ALLOWED_EXTENSIONS,
                "Editable extension .{$extension} is not in the validated whitelist"
            );
        }

        foreach (['pdf'] as $extension) {
            $this->assertNotContains(
                $extension,
                FileEditValidationService::EDITABLE_EXTENSIONS,
                ".{$extension} must not be editable as plain text"
            );
        }
    }

    // ---------------------------------------------------------------------
    // Task 28 — editor modal template
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function editorVars(array $overrides = []): array
    {
        return array_merge([
            'fileId' => 42,
            'taskId' => 7,
            'projectId' => 3,
            'filename' => 'notes.md',
            'extension' => 'md',
            'content' => "# Title\nbody line\n",
        ], $overrides);
    }

    public function testEditTemplateRendersEditorWithFileMetadata(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars());

        $this->assertStringContainsString('<textarea', $output);
        $this->assertStringContainsString('name="content"', $output);
        $this->assertStringContainsString('notes.md', $output);
        // Format badge and line counter
        $this->assertStringContainsString('>MD<', preg_replace('/\s+/', '', $output) ?? '');
        $this->assertStringContainsString('id="fic-line-count">3<', $output);
    }

    /**
     * Raw file bytes reach the textarea. An unescaped "</textarea>" in the
     * payload would break out of the field and inject live markup.
     */
    public function testEditTemplateEscapesContentBreakingOutOfTextarea(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars([
            'content' => '</textarea><script>alert(1)</script>',
        ]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        $this->assertStringContainsString('&lt;/textarea&gt;', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testEditTemplateEscapesMaliciousFilename(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars([
            'filename' => '"><img src=x onerror=alert(1)>.txt',
            'extension' => 'txt',
        ]));

        $this->assertStringNotContainsString('<img src=x', $output);
        $this->assertStringContainsString('&lt;img', $output);
    }

    public function testEditTemplatePostsToUpdateActionWithCsrfAndIdentifiers(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars());

        $this->assertStringContainsString('method="post"', $output);

        // Kanboard resolves this to the pretty route (/b/3/task/7/file/42/update)
        // in production and to a query string under the test URL stub, so assert
        // on the target rather than on one URL shape.
        $this->assertMatchesRegularExpression(
            '/<form[^>]+action="[^"]*update[^"]*"/',
            $output,
            'The editor form must post to the update action.'
        );
        $this->assertStringContainsString('name="csrf_token"', $output);
        $this->assertStringContainsString('name="file_id" value="42"', $output);
        $this->assertStringContainsString('name="task_id" value="7"', $output);
        $this->assertStringContainsString('name="project_id" value="3"', $output);
    }

    public function testEditTemplateOffersBothSaveModesWithOverwriteDefault(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars());

        $this->assertStringContainsString('name="mode" value="overwrite" checked', $output);
        $this->assertStringContainsString('name="mode" value="revision"', $output);
        $this->assertStringContainsString('Overwrite this file', $output);
        $this->assertStringContainsString('Save as new revision', $output);
        // The revision radio must not also be pre-selected
        $this->assertStringNotContainsString('value="revision" checked', $output);
    }

    /**
     * Read the rendered syntax indicator, ignoring the script block that also
     * carries the label strings for live re-evaluation.
     */
    private function syntaxStatusOf(string $output): string
    {
        $matched = preg_match('/<span id="fic-syntax-status".*?<\/span>/s', $output, $matches);
        $this->assertSame(1, $matched, 'edit.php must render a #fic-syntax-status indicator.');

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($matches[0])));
    }

    public function testEditTemplateReportsJsonSyntaxState(): void
    {
        $valid = $this->renderTemplate('edit', $this->editorVars([
            'filename' => 'config.json',
            'extension' => 'json',
            'content' => '{"status":"ok"}',
        ]));

        $invalid = $this->renderTemplate('edit', $this->editorVars([
            'filename' => 'config.json',
            'extension' => 'json',
            'content' => '{"status":',
        ]));

        $this->assertStringContainsString('Valid JSON', $this->syntaxStatusOf($valid));
        $this->assertStringNotContainsString('Invalid JSON Syntax', $this->syntaxStatusOf($valid));
        $this->assertStringContainsString('Invalid JSON Syntax', $this->syntaxStatusOf($invalid));
    }

    public function testEditTemplateShowsPlainTextModeForNonJsonFormats(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars());

        $this->assertStringContainsString('Plain Text Mode', $this->syntaxStatusOf($output));
        // The JSON live-validation branch is not emitted at all for plain text
        $this->assertStringNotContainsString('JSON.parse', $output);
        $this->assertStringNotContainsString('Valid JSON', $output);
    }

    public function testEditTemplateHandlesEmptyAttachmentContent(): void
    {
        $output = $this->renderTemplate('edit', $this->editorVars(['content' => '']));

        $this->assertStringContainsString('id="fic-line-count">0<', $output);
        $this->assertStringContainsString('id="fic-char-count">0<', $output);
        $this->assertStringContainsString('<textarea', $output);
    }

    // ---------------------------------------------------------------------
    // Task 33 — multi-sheet Excel modal template
    // ---------------------------------------------------------------------

    /**
     * Build handler-shaped metadata. Cells and sheet names arrive from
     * ExcelPreviewHandler ALREADY entity-escaped.
     *
     * @param array<string, list<list<string>>> $sheets
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function excelVars(array $sheets, array $overrides = []): array
    {
        $sheetMeta = [];
        foreach ($sheets as $name => $rows) {
            $columns = 0;
            foreach ($rows as $row) {
                $columns = max($columns, count($row));
            }

            $sheetMeta[$name] = [
                'rows' => $rows,
                'rowCount' => count($rows),
                'columnCount' => $columns,
                'truncated' => false,
            ];
        }

        $names = array_keys($sheets);

        return array_merge([
            'filename' => 'budget.xlsx',
            'handler' => 'ExcelPreviewHandler',
            'extension' => 'xlsx',
            'metadata' => array_merge([
                'handler' => 'ExcelPreviewHandler',
                'sheets' => $sheetMeta,
                'sheetCount' => count($names),
                'sheetNames' => $names,
                'activeSheet' => $names === [] ? '' : $names[0],
                'truncated' => false,
                'isLegacyFormat' => false,
                'parsed' => $names !== [],
            ], $overrides),
        ]);
    }

    public function testExcelTemplateRendersSheetTabsForMultiSheetWorkbook(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Summary' => [['Region', 'Total']],
            'Detail' => [['Id']],
            'Notes' => [['Memo']],
        ]));

        $this->assertStringContainsString('fic-sheet-tabs', $output);
        foreach (['Summary', 'Detail', 'Notes'] as $sheetName) {
            $this->assertStringContainsString($sheetName, $output);
        }
        // `role="tab"` counts the tabs alone — `fic-sheet-tab` would also match the
        // `fic-sheet-tabs` container, and `role="tablist"`/`role="tabpanel"` do not
        // match this exact string.
        $this->assertSame(3, substr_count($output, 'role="tab"'));
        $this->assertSame(3, substr_count($output, 'role="tabpanel"'));
        $this->assertSame(6, substr_count($output, 'data-sheet-index='));
        $this->assertSame(1, substr_count($output, 'aria-selected="true"'));
        $this->assertStringContainsString('3 Sheets', $output);
    }

    /**
     * Spec 006 AC-2: only the active worksheet is visible; the rest are present
     * in the DOM but hidden, so switching needs no server round-trip.
     */
    public function testExcelTemplateShowsOnlyTheActiveSheetPanel(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Summary' => [['visible-cell']],
            'Detail' => [['hidden-cell']],
        ]));

        $matched = preg_match_all('/<div class="fic-sheet-panel" id="fic-sheet-(\d+)"[^>]*style="([^"]*)"/', $output, $matches);
        $this->assertSame(2, $matched);
        $this->assertSame('', $matches[2][0], 'The active panel must not be hidden.');
        $this->assertStringContainsString('display: none', $matches[2][1]);
    }

    public function testExcelTemplateOmitsTabBarForSingleSheetWorkbook(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'OnlySheet' => [['a']],
        ]));

        $this->assertStringNotContainsString('fic-sheet-tabs', $output);
        // No TAB is rendered. The single panel still carries data-sheet-index
        // (Task 38) — that attribute pairs panels to tabs and is harmless without
        // a tab strip, so assert on the tab class rather than the attribute.
        $this->assertStringNotContainsString('fic-sheet-tab"', $output);
        $this->assertStringNotContainsString('role="tab"', $output);
    }

    public function testExcelTemplateRendersSpreadsheetColumnLabels(): void
    {
        $wideRow = array_fill(0, 28, 'x');

        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Wide' => [$wideRow],
        ]));

        foreach (['>A<', '>B<', '>Z<', '>AA<', '>AB<'] as $label) {
            $this->assertStringContainsString(
                $label,
                preg_replace('/\s+/', '', $output) ?? '',
                "Missing column label {$label}"
            );
        }
    }

    public function testExcelTemplateRendersRowIndexColumn(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Sheet1' => [['a'], ['b'], ['c']],
        ]));

        $compact = preg_replace('/\s+/', '', $output) ?? '';
        foreach (['>1<', '>2<', '>3<'] as $rowNumber) {
            $this->assertStringContainsString($rowNumber, $compact);
        }
    }

    /**
     * Spec 006 AC-3. Cells reach this view ALREADY escaped by the handler, so
     * the view must emit them verbatim: escaping again would surface literal
     * "&amp;lt;script&amp;gt;" text, and not escaping at the handler would
     * execute the payload.
     */
    public function testExcelTemplateEmitsPreEscapedCellsExactlyOnce(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Sheet1' => [['&lt;script&gt;alert(1)&lt;/script&gt;']],
        ]));

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        $this->assertStringNotContainsString('&amp;lt;', $output);
    }

    public function testExcelTemplateEmitsPreEscapedSheetNamesExactlyOnce(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            '&lt;img src=x&gt;' => [['a']],
            'Safe' => [['b']],
        ]));

        $this->assertStringContainsString('&lt;img src=x&gt;', $output);
        $this->assertStringNotContainsString('<img src=x>', $output);
        $this->assertStringNotContainsString('&amp;lt;img', $output);
    }

    public function testExcelTemplateEscapesUntrustedFilename(): void
    {
        $output = $this->renderTemplate('excel_preview', array_merge(
            $this->excelVars(['Sheet1' => [['a']]]),
            ['filename' => '<img src=x onerror=alert(1)>.xlsx']
        ));

        $this->assertStringNotContainsString('<img src=x', $output);
        $this->assertStringContainsString('&lt;img src=x', $output);
    }

    /**
     * A legacy .xls cannot be parsed by the OpenXML reader, so the view must
     * say so rather than render a grid that looks like an empty workbook.
     */
    public function testExcelTemplateShowsLegacyNoticeAndSuppressesGrid(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([], [
            'isLegacyFormat' => true,
            'parsed' => false,
        ]));

        $this->assertStringContainsString('Legacy .xls workbooks', $output);
        $this->assertStringContainsString('Download the file', $output);
        $this->assertStringNotContainsString('<table', $output);
        $this->assertStringNotContainsString('fic-sheet-tabs', $output);
    }

    public function testExcelTemplateShowsUnreadableWorkbookNotice(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([], [
            'parsed' => false,
        ]));

        $this->assertStringContainsString('could not be read', $output);
        $this->assertStringNotContainsString('Legacy .xls workbooks', $output);
        $this->assertStringNotContainsString('<table', $output);
    }

    public function testExcelTemplateShowsTruncationBanner(): void
    {
        $truncated = $this->renderTemplate('excel_preview', $this->excelVars([
            'Sheet1' => [['a']],
        ], ['truncated' => true]));

        $clean = $this->renderTemplate('excel_preview', $this->excelVars([
            'Sheet1' => [['a']],
        ]));

        $this->assertStringContainsString('Preview truncated', $truncated);
        $this->assertStringNotContainsString('Preview truncated', $clean);
        $this->assertStringNotContainsString('alert-warning', $clean);
    }

    public function testExcelTemplateShowsEmptySheetNotice(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Blank' => [],
        ]));

        $this->assertStringContainsString('This sheet is empty.', $output);
        $this->assertStringNotContainsString('<table', $output);
    }

    /**
     * Ragged rows must still line up under the header strip.
     */
    public function testExcelTemplatePadsShortRowsToTheWidestRow(): void
    {
        $output = $this->renderTemplate('excel_preview', $this->excelVars([
            'Ragged' => [['a', 'b', 'c'], ['only-one']],
        ]));

        $matched = preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $output, $rows);
        $this->assertSame(3, $matched, 'Expected a header row plus two body rows.');

        // Each body row: 1 index cell + 3 data cells
        $this->assertSame(4, substr_count($rows[1][1], '<td'));
        $this->assertSame(4, substr_count($rows[1][2], '<td'));
    }

    /**
     * Execute a plugin template the way Kanboard's template engine would:
     * variables extracted into scope, $this bound to a helper providing text->e().
     *
     * @param array<string, mixed> $vars
     */
    public function testDocxPreviewTemplateRendering(): void
    {
        $vars = [
            'filename' => 'specification.docx',
            'handler' => 'DocxPreviewHandler',
            'extension' => 'docx',
            'content' => '<h1 class="docx-heading">Project Specs</h1><p class="docx-paragraph">Details here.</p>',
            'metadata' => [
                'paragraphCount' => 1,
                'headingCount' => 1,
                'tableCount' => 0,
                'wordCount' => 4,
                'isLegacyFormat' => false,
                'parsed' => true,
            ],
            'taskId' => 3,
            'projectId' => 7,
            'fileId' => 42,
            'is_ajax' => true,
        ];

        $output = $this->renderTemplate('docx_preview', $vars);

        $this->assertStringContainsString('specification.docx', $output);
        $this->assertStringContainsString('fa-file-word-o', $output);
        $this->assertStringContainsString('4 Words', $output);
        $this->assertStringContainsString('fic-docx-container', $output);
        $this->assertStringContainsString('data-fic-stream-url', $output);
        $this->assertStringContainsString('Word Document', $output);
    }

    public function testPptxPreviewTemplateRendering(): void
    {
        $vars = [
            'filename' => 'deck.pptx',
            'handler' => 'PptxPreviewHandler',
            'extension' => 'pptx',
            'content' => '',
            'metadata' => [
                'slides' => [
                    [
                        'index' => 1,
                        'title' => 'Intro Slide',
                        'paragraphs' => ['Welcome everyone.'],
                        'bulletPoints' => ['Overview', 'Next Steps'],
                        'tables' => [],
                    ],
                    [
                        'index' => 2,
                        'title' => 'Summary',
                        'paragraphs' => ['Final thoughts.'],
                        'bulletPoints' => [],
                        'tables' => [],
                    ],
                ],
                'slideCount' => 2,
                'title' => 'Intro Slide',
                'isLegacyFormat' => false,
                'parsed' => true,
            ],
            'taskId' => 3,
            'projectId' => 7,
            'fileId' => 42,
            'is_ajax' => true,
        ];

        $output = $this->renderTemplate('pptx_preview', $vars);

        $this->assertStringContainsString('deck.pptx', $output);
        $this->assertStringContainsString('fa-file-powerpoint-o', $output);
        $this->assertStringContainsString('2 Slides', $output);
        $this->assertStringContainsString('fic-pptx-container', $output);
        $this->assertStringContainsString('fic-slide-tab', $output);
        $this->assertStringContainsString('data-fic-stream-url', $output);
        $this->assertStringContainsString('PowerPoint Presentation', $output);
    }

    private function renderTemplate(string $name, array $vars): string
    {
        $path = __DIR__ . '/../../Template/file/' . $name . '.php';
        $this->assertFileExists($path);

        $renderer = new FakeTemplateHelper();

        return $renderer->render($path, $vars);
    }
}

/**
 * Minimal stand-in for Kanboard's template helper collection.
 */
class FakeTextHelper
{
    public function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Verbatim port of Kanboard\Helper\TextHelper::bytes() (v1.2.37) — note the
     * suffixes are bare ('', 'k', 'M', 'G'), so 2 MB renders as "2M", not "2 MB".
     *
     * @param int|float $size
     * @return string|int
     */
    public function bytes($size, int $precision = 2)
    {
        if ($size == 0) {
            return 0;
        }

        $base = log((float) $size) / log(1024);
        $suffixes = ['', 'k', 'M', 'G', 'T'];

        return round(pow(1024, $base - floor($base)), $precision) . $suffixes[(int) floor($base)];
    }
}

/**
 * Mirrors Kanboard\Helper\UrlHelper::href(), which returns an attribute-safe
 * URL (query separator is &amp;).
 */
class FakeUrlHelper
{
    /**
     * @param array<string, mixed> $params
     */
    public function href(string $controller, string $action, array $params = []): string
    {
        $query = array_merge(['controller' => $controller, 'action' => $action], $params);

        return '?' . http_build_query($query, '', '&amp;');
    }
}

/**
 * Mirrors Kanboard\Helper\FormHelper::csrf(), which emits a hidden token input.
 */
class FakeFormHelper
{
    public function csrf(): string
    {
        return '<input type="hidden" name="csrf_token" value="test-token"/>';
    }
}

/**
 * Mirrors Kanboard\Helper\UserHelper::hasProjectAccess($controller, $action, $projectId).
 */
class FakeUserHelper
{
    private bool $projectAccess = true;

    public function setProjectAccess(bool $granted): void
    {
        $this->projectAccess = $granted;
    }

    public function hasProjectAccess(string $controller, string $action, int $projectId): bool
    {
        return $this->projectAccess;
    }
}

/**
 * Mirrors Kanboard\Helper\ModalHelper. The real helper renders an anchor; the
 * stub emits an identifiable marker so tests can assert on the target action.
 */
class FakeModalHelper
{
    /**
     * @param array<string, mixed> $params
     */
    public function medium(string $icon, string $label, string $controller, string $action, array $params = []): string
    {
        return sprintf(
            '<a href="?%s" class="js-modal-medium" data-icon="%s">%s::%s %s</a>',
            http_build_query(array_merge(['controller' => $controller, 'action' => $action], $params), '', '&amp;'),
            htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $controller,
            $action,
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}

/**
 * Binds $this->text inside included plugin templates.
 */
class FakeTemplateHelper
{
    public FakeTextHelper $text;
    public FakeUrlHelper $url;
    public FakeFormHelper $form;
    public FakeUserHelper $user;
    public FakeModalHelper $modal;

    public function __construct()
    {
        $this->text = new FakeTextHelper();
        $this->url = new FakeUrlHelper();
        $this->form = new FakeFormHelper();
        $this->user = new FakeUserHelper();
        $this->modal = new FakeModalHelper();
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $path, array $vars): string
    {
        if (str_contains($path, ':')) {
            [, $relative] = explode(':', $path, 2);
            $path = __DIR__ . '/../../Template/' . $relative . '.php';
        }

        extract($vars, EXTR_SKIP);

        ob_start();

        try {
            include $path;
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
