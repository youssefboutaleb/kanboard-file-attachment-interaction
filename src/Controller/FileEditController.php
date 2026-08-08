<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\FileInteractionCore\Service\FileEditValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\FileVersionService;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;

/**
 * Controller handling safe live text/JSON file editing and versioning updates.
 *
 * @property mixed $userSession
 * @property mixed $taskFileModel
 * @property mixed $objectStorage
 * @property mixed $request
 * @property mixed $response
 * @property mixed $template
 */
class FileEditController extends BaseController
{
    private PermissionService $permissionService;
    private FileEditValidationService $editValidationService;
    private FileVersionService $versionService;

    /**
     * @param mixed $container
     */
    public function __construct(
        $container = null,
        ?PermissionService $permissionService = null,
        ?FileEditValidationService $editValidationService = null,
        ?FileVersionService $versionService = null
    ) {
        if ($container !== null) {
            $this->container = $container;
        }

        $this->permissionService = $permissionService ?? new PermissionService();
        $this->editValidationService = $editValidationService ?? new FileEditValidationService();
        $this->versionService = $versionService ?? new FileVersionService();
    }

    /**
     * Helper to safely check DIC service availability without throwing.
     */
    private function hasService(string $serviceName): bool
    {
        return isset($this->container)
            && is_object($this->container)
            && method_exists($this->container, 'offsetExists')
            && $this->container->offsetExists($serviceName);
    }

    /**
     * Render the interactive edit modal.
     *
     * @return mixed
     */
    public function edit(int $fileId = 0, int $taskId = 0, int $projectId = 0, string $filename = '', string $rawContent = '')
    {
        $canRender = $this->hasService('request') && $this->hasService('response') && $this->hasService('template');

        if ($canRender) {
            $fileId = (int) $this->request->getIntegerParam('file_id');
            $taskId = (int) $this->request->getIntegerParam('task_id');
            $projectId = (int) $this->request->getIntegerParam('project_id');
        }

        // Check write permission
        $userId = 0;
        if ($this->hasService('userSession')) {
            $userId = (int) $this->userSession->getId();
        }

        if (!$this->permissionService->canUserWriteFile($projectId, $taskId, $fileId, $userId)) {
            return $this->renderErrorModal($canRender, 'Access Denied: You do not have write permissions for this file.', 403, 'access_denied');
        }

        // Fetch file info
        if ($canRender) {
            $file = $this->taskFileModel->getById($fileId);
            if (!$file) {
                return $this->renderErrorModal(true, 'File not found.', 404, 'not_found');
            }
            $filename = (string) ($file['name'] ?? '');
            $rawContent = (string) $this->objectStorage->get($file['path'] ?? '');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if (!$this->editValidationService->isEditableExtension($extension)) {
            return $this->renderErrorModal($canRender, 'Editing is not supported for this file format.', 400, 'invalid_file');
        }

        $responseData = [
            'fileId' => $fileId,
            'taskId' => $taskId,
            'projectId' => $projectId,
            'filename' => $filename,
            'extension' => strtolower($extension),
            'content' => $rawContent,
        ];

        if ($canRender) {
            return $this->response->html(
                $this->template->render('FileInteractionCore:file/edit', $responseData)
            );
        }

        return $responseData;
    }

    /**
     * Handle POST submission to update file content.
     *
     * @return mixed
     */
    public function update(int $fileId = 0, int $taskId = 0, int $projectId = 0, string $filename = '', string $newContent = '', string $mode = 'overwrite')
    {
        $canRender = $this->hasService('request') && $this->hasService('response') && $this->hasService('template');

        if ($canRender) {
            $fileId = (int) $this->request->getIntegerParam('file_id');
            $taskId = (int) $this->request->getIntegerParam('task_id');
            $projectId = (int) $this->request->getIntegerParam('project_id');
            $newContent = (string) $this->request->getStringParam('content');
            $mode = (string) $this->request->getStringParam('mode', 'overwrite');
        }

        // Check write permission
        $userId = 0;
        if ($this->hasService('userSession')) {
            $userId = (int) $this->userSession->getId();
        }

        if (!$this->permissionService->canUserWriteFile($projectId, $taskId, $fileId, $userId)) {
            return $this->renderErrorModal($canRender, 'Access Denied: Write permissions required.', 403, 'access_denied');
        }

        if ($canRender) {
            $file = $this->taskFileModel->getById($fileId);
            if (!$file) {
                return $this->renderErrorModal(true, 'File not found.', 404, 'not_found');
            }
            $filename = (string) ($file['name'] ?? '');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if (!$this->editValidationService->isEditableExtension($extension)) {
            return $this->renderErrorModal($canRender, 'Editing is not supported for this file format.', 400, 'invalid_file');
        }

        // Pre-save validation
        $valResult = $this->editValidationService->validate($newContent, $extension);
        if (!$valResult['isValid']) {
            return $this->renderErrorModal(
                $canRender,
                $valResult['error'] ?? 'Validation failed.',
                400,
                'validation_error',
                $valResult['errorLine']
            );
        }

        // Persist change
        if ($canRender) {
            $path = (string) ($file['path'] ?? '');
            if ($mode === 'revision') {
                $newFilename = $this->versionService->generateVersionedFilename($filename, 2);
                $this->taskFileModel->uploadContent($taskId, $newFilename, $newContent);
            } else {
                $this->objectStorage->put($path, $newContent);
            }

            return $this->response->json(['success' => true]);
        }

        return [
            'success' => true,
            'fileId' => $fileId,
            'filename' => $filename,
            'mode' => $mode,
        ];
    }

    /**
     * Render an error modal response.
     *
     * @return mixed
     */
    private function renderErrorModal(bool $canRender, string $message, int $statusCode, string $reason, ?int $errorLine = null)
    {
        $errorData = [
            'message' => $message,
            'reason' => $reason,
            'errorLine' => $errorLine,
        ];

        if ($canRender) {
            $this->response->statusCode($statusCode);
            return $this->response->html(
                $this->template->render('FileInteractionCore:file/preview_error', $errorData)
            );
        }

        return array_merge(['success' => false, 'statusCode' => $statusCode], $errorData);
    }
}
