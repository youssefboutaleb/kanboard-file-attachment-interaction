<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller;

// Require stub if running standalone outside Kanboard runtime
if (!class_exists('Kanboard\Controller\BaseController')) {
    require_once __DIR__ . '/../../tests/stubs/BaseController.php';
}

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileContentFetcherInterface;
use Kanboard\Plugin\FileInteractionCore\Core\FileInteractionManager;
use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;
use Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;

/**
 * Controller handling safe attachment preview requests with ACL enforcement.
 */
class FilePreviewController extends BaseController
{
    private PermissionService $permissionService;
    private FileValidationService $validationService;
    private FileInteractionManager $interactionManager;
    private ?FileContentFetcherInterface $contentFetcher;

    /**
     * @param mixed $container
     */
    public function __construct(
        $container = null,
        ?PermissionService $permissionService = null,
        ?FileValidationService $validationService = null,
        ?FileInteractionManager $interactionManager = null,
        ?FileContentFetcherInterface $contentFetcher = null
    ) {
        if ($container !== null && is_object($container)) {
            parent::__construct($container);
        }
        $this->permissionService = $permissionService ?? new PermissionService();
        $this->validationService = $validationService ?? new FileValidationService();

        if ($interactionManager === null) {
            $interactionManager = new FileInteractionManager();
            // Registration order matters: TextPreviewHandler accepts ANY text/* MIME
            // type, so format-specific handlers must be registered ahead of it.
            $interactionManager->registerHandler(new CsvPreviewHandler());
            $interactionManager->registerHandler(new JsonPreviewHandler());
            $interactionManager->registerHandler(new TextPreviewHandler());
        }

        $this->interactionManager = $interactionManager;
        $this->contentFetcher = $contentFetcher;
    }

    /**
     * Determine whether a Kanboard container service is available.
     *
     * NOTE: `Kanboard\Core\Base` exposes services through `__get()` but does NOT
     * implement `__isset()`. Therefore `isset($this->request)` is ALWAYS false at
     * runtime and must never be used to detect the HTTP context. Probe the
     * container directly instead.
     */
    private function hasService(string $name): bool
    {
        $container = $this->container;

        return $container instanceof \ArrayAccess && (bool) $container->offsetExists($name);
    }

    /**
     * Handle attachment preview request.
     *
     * @param int|null $projectId
     * @param int|null $taskId
     * @param int|null $fileId
     * @param string|null $filename
     * @param string|null $rawContent
     * @param string|null $forcedFormat
     * @param string|null $mimeType
     * @return mixed
     *
     * @throws AccessDeniedException
     * @throws InvalidFileException
     */
    public function show(
        ?int $projectId = null,
        ?int $taskId = null,
        ?int $fileId = null,
        ?string $filename = null,
        ?string $rawContent = null,
        ?string $forcedFormat = null,
        ?string $mimeType = null
    ) {
        $hasRequest = $this->hasService('request');

        // Extract parameters from Kanboard HTTP request if not directly passed
        if (empty($fileId) && $hasRequest) {
            $fileId = (int) $this->request->getIntegerParam('file_id');
        }
        if (empty($taskId) && $hasRequest) {
            $taskId = (int) $this->request->getIntegerParam('task_id');
        }
        if (empty($projectId) && $hasRequest) {
            $projectId = (int) $this->request->getIntegerParam('project_id');
        }
        if (empty($forcedFormat) && $hasRequest) {
            $forcedFormat = (string) $this->request->getStringParam('format') ?: null;
        }

        $fileId = $fileId ?? 0;
        $taskId = $taskId ?? 0;
        $projectId = $projectId ?? 0;

        // Project attachments live in a different table than task attachments
        $source = 'task';
        if ($hasRequest) {
            $source = $this->request->getStringParam('source') === 'project' ? 'project' : 'task';
        }
        $modelName = $source === 'project' ? 'projectFileModel' : 'taskFileModel';

        $file = null;

        // Fetch file metadata & path from Kanboard core models if running inside Kanboard
        if ($fileId > 0 && $this->hasService($modelName)) {
            try {
                $file = $this->{$modelName}->getById($fileId);
            } catch (\Throwable $e) {
                $file = null;
            }
        }

        if (!empty($file) && is_array($file)) {
            $taskId = $taskId > 0 ? $taskId : (int)($file['task_id'] ?? 0);
            $projectId = $projectId > 0 ? $projectId : (int)($file['project_id'] ?? 0);
            $filename = $filename ?? ($file['name'] ?? null);

            if ($rawContent === null && !empty($file['path'])) {
                if ($this->hasService('objectStorage')) {
                    try {
                        $rawContent = $this->objectStorage->get($file['path']);
                    } catch (\Throwable $e) {
                        $rawContent = null;
                    }
                }

                if (empty($rawContent)) {
                    // Fallback to direct file read from FILES_DIR if objectStorage wrapper is uninitialized
                    $filesDir = defined('FILES_DIR') ? FILES_DIR : '/var/www/app/data/files';
                    $filePath = $filesDir . '/' . $file['path'];
                    if (file_exists($filePath) && is_readable($filePath)) {
                        $contentRead = file_get_contents($filePath);
                        if ($contentRead !== false) {
                            $rawContent = $contentRead;
                        }
                    }
                }
            }
        }

        // Resolve the owning project when only a task id was supplied (direct route access)
        if ($projectId === 0 && $taskId > 0 && $this->hasService('taskFinderModel')) {
            try {
                $projectId = (int) $this->taskFinderModel->getProjectId($taskId);
            } catch (\Throwable $e) {
                $projectId = 0;
            }
        }

        // Fallback to content fetcher interface if rawContent still null
        if ($rawContent === null && $this->contentFetcher !== null) {
            $rawContent = $this->contentFetcher->getFileContent($fileId);
        }

        $content = $rawContent ?? '';

        // Rendering is only possible when both the template engine and the HTTP
        // response service are present (i.e. running inside the Kanboard runtime).
        $canRender = $this->hasService('response') && $this->hasService('template');

        try {
            $safeFilename = $filename !== null ? $this->validationService->sanitizeFilename($filename) : 'attachment.txt';

            // Step 1: Enforce ACL permissions
            $this->permissionService->assertUserCanReadFile($projectId, $taskId, $fileId);

            // Step 2: Validate file extension & size bounds
            $extension = $this->validationService->validateExtension($safeFilename);
            $this->validationService->validateFileSize(strlen($content));

            // Infer default MIME type if not provided
            $resolvedMime = $mimeType ?? match ($extension) {
                'json' => 'application/json',
                'csv' => 'text/csv',
                'tsv' => 'text/tab-separated-values',
                default => 'text/plain',
            };

            // Step 3: Resolve appropriate handler
            $handler = $this->interactionManager->resolveHandler($extension, $resolvedMime, $forcedFormat);

            if ($handler === null) {
                throw new InvalidFileException(sprintf('No handler available to preview file ".%s".', $extension));
            }
        } catch (AccessDeniedException | InvalidFileException $e) {
            // Inside the HTTP context, surface a clean modal instead of a 500 error page
            if ($canRender) {
                return $this->renderError($e, $filename);
            }

            throw $e;
        }

        // Step 4: Generate safe preview result
        $result = $handler->preview($content);

        $responseData = [
            'success' => true,
            'projectId' => $projectId,
            'taskId' => $taskId,
            'fileId' => $fileId,
            'filename' => $safeFilename,
            'extension' => $extension,
            'handler' => $handler->getHandlerName(),
            'content' => $result->getContent(),
            'isFormatted' => $result->isFormatted(),
            'metadata' => $result->getMetadata(),
        ];

        // If running inside Kanboard HTTP response context, render template HTML
        if ($canRender) {
            $templateName = $handler->getHandlerName() === 'CsvPreviewHandler'
                ? 'FileInteractionCore:file/csv_preview'
                : 'FileInteractionCore:file/preview';

            return $this->response->html($this->template->render($templateName, $responseData));
        }

        return $responseData;
    }

    /**
     * Render a safe, escaped error modal for rejected preview requests.
     *
     * @return mixed
     */
    private function renderError(\Throwable $e, ?string $filename)
    {
        $isDenied = $e instanceof AccessDeniedException;

        return $this->response->html($this->template->render('FileInteractionCore:file/preview_error', [
            'success' => false,
            'filename' => $filename ?? '',
            'reason' => $isDenied ? 'access_denied' : 'invalid_file',
            'message' => $e->getMessage(),
        ]), $isDenied ? 403 : 400);
    }
}
