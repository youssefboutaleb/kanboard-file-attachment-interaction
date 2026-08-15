<?php

declare(strict_types=1);

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
        $this->route->addRoute(
            '/b/:project_id/task/:task_id/file/:file_id/edit',
            'FileEditController',
            'edit',
            'FileInteractionCore'
        );
        $this->route->addRoute(
            '/b/:project_id/task/:task_id/file/:file_id/update',
            'FileEditController',
            'update',
            'FileInteractionCore'
        );
        $this->route->addRoute(
            '/b/:project_id/task/:task_id/file/:file_id/stream',
            'FileStreamController',
            'inline',
            'FileInteractionCore'
        );

        // 2. Attach UI Action Hooks to Task File Attachment Dropdowns
        $this->template->hook->attach('template:task-file:documents:dropdown', 'FileInteractionCore:file/dropdown');
        $this->template->hook->attach('template:task-file:images:dropdown', 'FileInteractionCore:file/dropdown');
        $this->template->hook->attach('template:project-overview:documents:dropdown', 'FileInteractionCore:file/dropdown');

        // 3. Register the dropdown cleanup script.
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/dropdown-cleanup.js'
        );

        // 4. Register the in-modal preview controls script
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/preview-controls.js'
        );

        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/preview-language-selector.js'
        );

        // 5. Register the live editor script.
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/editor.js'
        );

        // 6. Register high-fidelity office viewer vendor libraries and controller.
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/vendor/jszip.min.js'
        );
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/vendor/docx-preview.min.js'
        );
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/vendor/pptx-viewer.umd.js'
        );
        $this->template->hook->attach(
            'template:layout:js',
            'plugins/FileInteractionCore/Assets/js/office-viewer.js'
        );

        // 7. Register modal fullscreen styles.
        $this->template->hook->attach(
            'template:layout:css',
            'plugins/FileInteractionCore/Assets/css/preview.css'
        );

        // 8. Register Project Access Permissions
        if (isset($this->projectAccessMap) && is_object($this->projectAccessMap)) {
            $memberRole = class_exists('Kanboard\\Core\\Security\\Role') ? \Kanboard\Core\Security\Role::PROJECT_MEMBER : 'app-project-member';
            $viewerRole = class_exists('Kanboard\\Core\\Security\\Role') ? \Kanboard\Core\Security\Role::PROJECT_VIEWER : 'app-project-viewer';
            $this->projectAccessMap->add('FileEditController', ['edit', 'update'], $memberRole);
            $this->projectAccessMap->add('FilePreviewController', ['show'], $viewerRole);
            $this->projectAccessMap->add('FileStreamController', ['inline'], $viewerRole);
        }
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
        return '0.9.0';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/youssefboutaleb/kanboard-file-attachment-interaction';
    }
}
