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
        $this->assertSame('0.4.0', $this->plugin->getPluginVersion());
        $this->assertSame('Security & Engineering Team', $this->plugin->getPluginAuthor());
        $this->assertSame('https://github.com/youssefboutaleb/kanboard-file-attachment-interaction', $this->plugin->getPluginHomepage());
        $this->assertNotEmpty($this->plugin->getPluginDescription());
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
        $this->assertStringContainsString('SH', $output);
        $this->assertStringContainsString('class="code-highlight language-sh"', $output);
        $this->assertStringContainsString('Safe Read-Only Syntax Highlighted View', $output);
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
    public function testPdfPreviewTemplateEmbedsInlineBrowserActionNotDownload(): void
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

        $this->assertStringContainsString('action=browser', $matches[1]);
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
    public function testPdfPreviewTemplateOmitsUnknownProjectId(): void
    {
        $output = $this->renderTemplate('pdf_preview', [
            'filename' => 'spec.pdf',
            'handler' => 'PdfPreviewHandler',
            'taskId' => 5,
            'fileId' => 9,
            'projectId' => 0,
            'metadata' => ['isBinary' => true, 'sizeBytes' => 512],
        ]);

        $this->assertStringNotContainsString('project_id=', $output);
        $this->assertStringContainsString('file_id=9', $output);
    }

    /**
     * Execute a plugin template the way Kanboard's template engine would:
     * variables extracted into scope, $this bound to a helper providing text->e().
     *
     * @param array<string, mixed> $vars
     */
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
 * Binds $this->text inside included plugin templates.
 */
class FakeTemplateHelper
{
    public FakeTextHelper $text;
    public FakeUrlHelper $url;

    public function __construct()
    {
        $this->text = new FakeTextHelper();
        $this->url = new FakeUrlHelper();
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $path, array $vars): string
    {
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
