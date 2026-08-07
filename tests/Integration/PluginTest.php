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
        $this->assertSame('0.1.0', $this->plugin->getPluginVersion());
        $this->assertSame('Security & Engineering Team', $this->plugin->getPluginAuthor());
        $this->assertSame('https://github.com/youssefboutaleb/kanboard-file-attachment-interaction', $this->plugin->getPluginHomepage());
        $this->assertNotEmpty($this->plugin->getPluginDescription());
    }

    public function testPluginInitialization(): void
    {
        $this->plugin->initialize();
        $this->assertTrue(true);
    }
}
