<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller\Concerns;

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
