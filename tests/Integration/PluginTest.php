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
        $this->assertSame('0.3.0', $this->plugin->getPluginVersion());
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
}

/**
 * Binds $this->text inside included plugin templates.
 */
class FakeTemplateHelper
{
    public FakeTextHelper $text;

    public function __construct()
    {
        $this->text = new FakeTextHelper();
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
