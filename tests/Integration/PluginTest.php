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
        $this->assertSame('0.2.0', $this->plugin->getPluginVersion());
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
}
