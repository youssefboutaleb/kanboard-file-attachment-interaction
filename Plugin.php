<?php

namespace Kanboard\Plugin\FileInteractionCore;

use Kanboard\Core\Plugin\Base;

// Register autoloader for src/ directory in production plugin environment
spl_autoload_register(function (string $className): void {
    $prefix = 'Kanboard\\Plugin\\FileInteractionCore\\';
    if (strncmp($prefix, $className, strlen($prefix)) === 0) {
        $relativeClass = substr($className, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

/**
 * Kanboard Plugin Entry Point: FileInteractionCore
 *
 * Secure, modular file interaction framework for Kanboard task attachments.
 */
class Plugin extends Base
{
    public function initialize()
    {
        // 1. Register Plugin Routes
        $this->route->addRoute(
            '/b/:project_id/task/:task_id/file/:file_id/preview',
            'FilePreviewController',
            'show',
            'FileInteractionCore'
        );

        // 2. Attach UI Action Hooks to Task File Attachment Dropdowns
        $this->template->hook->attach('template:task-file:documents:dropdown', 'FileInteractionCore:file/dropdown');
        $this->template->hook->attach('template:task-file:images:dropdown', 'FileInteractionCore:file/dropdown');
        // Kanboard core only exposes a documents dropdown hook on the project overview
        $this->template->hook->attach('template:project-overview:documents:dropdown', 'FileInteractionCore:file/dropdown');
    }

    public function getPluginName()
    {
        return 'FileInteractionCore';
    }

    public function getPluginDescription()
    {
        return 'Secure, modular file interaction framework for task attachments.';
    }

    public function getPluginAuthor()
    {
        return 'Security & Engineering Team';
    }

    public function getPluginVersion()
    {
        return '0.1.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/youssefboutaleb/kanboard-file-attachment-interaction';
    }
}
