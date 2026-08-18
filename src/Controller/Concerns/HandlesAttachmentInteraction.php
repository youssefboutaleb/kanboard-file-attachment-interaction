<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller\Concerns;

use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Service\KanboardPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;

/**
 * Trait providing shared attachment resolution, container probing, and rendering operations.
 */
trait HandlesAttachmentInteraction
{
    /**
     * Determine whether a Kanboard container service is available.
     *
     * NOTE: `Kanboard\Core\Base` exposes services through `__get()` but does NOT
     * implement `__isset()`. Therefore `isset($this->request)` is ALWAYS false at
     * runtime and must never be used to detect the HTTP context. Probe the
     * container directly instead.
     */
    protected function hasService(string $name): bool
    {
        $container = $this->container ?? null;

        return $container instanceof \ArrayAccess && (bool) $container->offsetExists($name);
    }

    /**
     * Resolve attachment metadata array from task or project file model.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveAttachmentRecord(int $fileId, string $source = 'task'): ?array
    {
        if ($fileId <= 0) {
            return null;
        }

        $modelName = $source === 'project' ? 'projectFileModel' : 'taskFileModel';

        if (!$this->hasService($modelName)) {
            return null;
        }

        try {
            $file = $this->{$modelName}->getById($fileId);
            return is_array($file) ? $file : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve project ID from task ID when only task ID is supplied.
     */
    protected function resolveProjectIdFromTask(int $taskId): int
    {
        if ($taskId <= 0 || !$this->hasService('taskFinderModel')) {
            return 0;
        }

        try {
            return (int) $this->taskFinderModel->getProjectId($taskId);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Build the PermissionService a controller should use when none was injected.
     *
     * Inside Kanboard the container carries `projectPermissionModel`, so the real
     * ACL checker is installed and membership is genuinely enforced. Outside it —
     * unit tests constructing a controller with no container, or a bare container —
     * there is nothing to ask, so the historical mock stands in. Production always
     * takes the first branch; the mock can no longer reach a real request.
     */
    protected function createDefaultPermissionService(): PermissionService
    {
        if ($this->hasService('projectPermissionModel')) {
            return new PermissionService(new KanboardPermissionChecker($this->container));
        }

        return new PermissionService();
    }

    /**
     * Assert that the attachment actually belongs to the task/project named in the URL.
     *
     * WHY THIS IS REQUIRED, AND WHY THE ROUTE ACL IS NOT ENOUGH: Kanboard authorizes
     * these routes through `projectAccessMap`, which `ProjectAuthorization` evaluates
     * against the `project_id` **in the URL** — it proves the caller holds a role on
     * *that* project and nothing more. It never inspects `file_id`. Meanwhile every
     * controller here loads the attachment with `getById($fileId)`, a lookup keyed on
     * the id alone.
     *
     * Without this gate the two facts are never joined, so any member of any project
     * can name their own project in the path and any attachment id in the query:
     *
     *     /b/<project the caller can access>/task/<any>/file/<file in a FOREIGN project>/preview
     *
     * The ACL passes, `getById()` happily returns the foreign row, and its bytes are
     * rendered (or, on the edit route, overwritten). That is a cross-project
     * disclosure and, via `FileEditController::update()`, a cross-project write.
     *
     * The check joins them: the row's own ownership columns must match the URL. A row
     * that declares no owner cannot be cross-checked and is left to the route ACL —
     * those columns are database-controlled, never request-controlled, so an attacker
     * cannot blank them to slip through.
     *
     * @param array<string, mixed> $file Attachment row from task_files/project_files.
     *
     * @throws AccessDeniedException when the row belongs to a different task or project.
     */
    protected function assertAttachmentOwnership(array $file, int $taskId, int $projectId, string $source = 'task'): void
    {
        if ($source === 'project') {
            $ownerProjectId = (int) ($file['project_id'] ?? 0);

            if ($ownerProjectId > 0 && $projectId > 0 && $ownerProjectId !== $projectId) {
                throw new AccessDeniedException(
                    'Access Denied: this attachment does not belong to the requested project.'
                );
            }

            return;
        }

        $ownerTaskId = (int) ($file['task_id'] ?? 0);

        if ($ownerTaskId > 0 && $taskId > 0 && $ownerTaskId !== $taskId) {
            throw new AccessDeniedException(
                'Access Denied: this attachment does not belong to the requested task.'
            );
        }

        // The task the attachment really hangs off must also sit in the project the
        // URL claimed, otherwise the role check ran against the wrong project.
        $effectiveTaskId = $ownerTaskId > 0 ? $ownerTaskId : $taskId;

        if ($effectiveTaskId > 0 && $projectId > 0) {
            $owningProjectId = $this->resolveProjectIdFromTask($effectiveTaskId);

            if ($owningProjectId > 0 && $owningProjectId !== $projectId) {
                throw new AccessDeniedException(
                    'Access Denied: this attachment does not belong to the requested project.'
                );
            }
        }
    }

    /**
     * Read attachment bytes from object storage, falling back to safe filesystem read.
     */
    protected function readAttachmentBytes(string $path): ?string
    {
        if ($this->hasService('objectStorage')) {
            try {
                $content = $this->objectStorage->get($path);
                if (is_string($content) && $content !== '') {
                    return $content;
                }
            } catch (\Throwable $e) {
                // fall through to filesystem fallback
            }
        }

        // Fallback for runtimes where the objectStorage wrapper is uninitialized.
        // Reject traversal sequences and null bytes before touching disk.
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        $filesDir = defined('FILES_DIR') ? FILES_DIR : '/var/www/app/data/files';
        $filePath = rtrim($filesDir, '/') . '/' . ltrim($path, '/');

        if (is_file($filePath) && is_readable($filePath)) {
            $contentRead = file_get_contents($filePath);
            if ($contentRead !== false) {
                return $contentRead;
            }
        }

        return null;
    }

    /**
     * Render template HTML: if Ajax, renders partial template for modal dialog;
     * if standalone browser request, wraps with full application layout.
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    protected function renderTemplateOrLayout(string $template, array $data, ?string $title = null)
    {
        $isAjax = $this->hasService('request')
            && is_object($this->request)
            && method_exists($this->request, 'isAjax')
            && $this->request->isAjax();
        $data['is_ajax'] = $isAjax;

        $layout = $this->hasService('helper') ? ($this->helper->layout ?? null) : null;
        if (!$isAjax && is_object($layout) && method_exists($layout, 'app')) {
            $data['title'] = $title ?? ($data['filename'] ?? (function_exists('t') ? t('File Preview') : 'File Preview'));
            return $this->response->html(
                $layout->app($template, $data)
            );
        }

        return $this->response->html(
            $this->template->render($template, $data)
        );
    }

    /**
     * Render a safe error modal for rejected requests.
     *
     * @return mixed
     */
    protected function renderErrorModalResponse(
        bool $canRender,
        string $message,
        int $statusCode,
        string $reason,
        ?int $errorLine = null,
        ?string $filename = null
    ) {
        $errorData = [
            'success' => false,
            'filename' => $filename ?? '',
            'reason' => $reason,
            'message' => $message,
            'errorLine' => $errorLine,
            'title' => function_exists('t') ? t('Error') : 'Error',
        ];

        if ($canRender) {
            $isAjax = $this->hasService('request')
                && is_object($this->request)
                && method_exists($this->request, 'isAjax')
                && $this->request->isAjax();
            $errorData['is_ajax'] = $isAjax;

            $layout = $this->hasService('helper') ? ($this->helper->layout ?? null) : null;
            if (!$isAjax && is_object($layout) && method_exists($layout, 'app')) {
                return $this->response->html(
                    $layout->app('FileInteractionCore:file/preview_error', $errorData),
                    $statusCode
                );
            }

            return $this->response->html(
                $this->template->render('FileInteractionCore:file/preview_error', $errorData),
                $statusCode
            );
        }

        return array_merge(['statusCode' => $statusCode], $errorData);
    }
}
