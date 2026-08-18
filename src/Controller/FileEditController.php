<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\FileInteractionCore\Controller\Concerns\HandlesAttachmentInteraction;
use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Service\CsvParserService;
use Kanboard\Plugin\FileInteractionCore\Service\ExcelParserService;
use Kanboard\Plugin\FileInteractionCore\Service\ExcelWriterService;
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
 * @property mixed $helper
 * @property mixed $flash
 */
class FileEditController extends BaseController
{
    use HandlesAttachmentInteraction;

    private PermissionService $permissionService;
    private FileEditValidationService $editValidationService;
    private FileVersionService $versionService;
    private ExcelParserService $excelParser;
    private ExcelWriterService $excelWriter;
    private CsvParserService $csvParser;

    /**
     * @param mixed $container
     */
    public function __construct(
        $container = null,
        ?PermissionService $permissionService = null,
        ?FileEditValidationService $editValidationService = null,
        ?FileVersionService $versionService = null,
        ?ExcelParserService $excelParser = null,
        ?ExcelWriterService $excelWriter = null,
        ?CsvParserService $csvParser = null
    ) {
        if ($container !== null) {
            $this->container = $container;
        }

        $this->permissionService = $permissionService ?? $this->createDefaultPermissionService();
        $this->editValidationService = $editValidationService ?? new FileEditValidationService();
        $this->versionService = $versionService ?? new FileVersionService();
        $this->excelParser = $excelParser ?? new ExcelParserService(1000, 100);
        $this->excelWriter = $excelWriter ?? new ExcelWriterService();
        $this->csvParser = $csvParser ?? new CsvParserService();
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

            // The route ACL only proved a role on the URL's project; this proves the
            // attachment is actually part of it before its bytes are handed back.
            try {
                $this->assertAttachmentOwnership((array) $file, $taskId, $projectId);
            } catch (AccessDeniedException $e) {
                return $this->renderErrorModal(true, $e->getMessage(), 403, 'access_denied');
            }

            $filename = (string) ($file['name'] ?? '');
            $rawContent = (string) $this->objectStorage->get($file['path'] ?? '');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $normalizedExt = strtolower($extension);
        if (!$this->editValidationService->isEditableExtension($normalizedExt)) {
            return $this->renderErrorModal($canRender, 'Editing is not supported for this file format.', 400, 'invalid_file');
        }

        $isSpreadsheet = in_array($normalizedExt, ['xlsx', 'xls', 'csv', 'tsv'], true);
        $sheets = [];
        $sheetNames = [];
        $contentToEdit = $rawContent;

        if (in_array($normalizedExt, ['xlsx', 'xls'], true)) {
            $parsed = $this->excelParser->parseXlsxContent($rawContent);
            $sheets = $parsed['sheets'];
            $sheetNames = $parsed['sheetNames'];
            $contentToEdit = $this->excelWriter->xlsxToCsv($rawContent);
        } elseif (in_array($normalizedExt, ['csv', 'tsv'], true)) {
            $delimiter = $normalizedExt === 'tsv' ? "\t" : ',';
            $parsed = $this->csvParser->parse($rawContent, $delimiter);
            $rows = $parsed['rows'];
            if (empty($rows)) {
                $rows = [['']];
            }
            $sheetNames = ['Sheet1'];
            $sheets = ['Sheet1' => [
                'rows' => $rows,
                'rowCount' => count($rows),
                'columnCount' => count($rows[0] ?? []),
                'truncated' => $parsed['truncatedRows'] || $parsed['truncatedColumns'],
            ]];
        }

        $responseData = [
            'fileId' => $fileId,
            'taskId' => $taskId,
            'projectId' => $projectId,
            'filename' => $filename,
            'extension' => $normalizedExt,
            'content' => $contentToEdit,
            'isSpreadsheet' => $isSpreadsheet,
            'sheets' => $sheets,
            'sheetNames' => $sheetNames,
            'activeSheet' => $sheetNames[0] ?? 'Sheet1',
        ];

        if ($canRender) {
            $title = function_exists('t') ? t('Edit %s', $filename) : "Edit {$filename}";
            return $this->renderTemplateOrLayout('FileInteractionCore:file/edit', $responseData, $title);
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
        $gridDataRaw = '';

        if ($canRender) {
            $rawPost = [];
            if (is_object($this->request) && method_exists($this->request, 'getRawFormValues')) {
                $rawPost = (array) $this->request->getRawFormValues();
            }

            $csrfToken = (string) (
                $rawPost['csrf_token']
                ?? ($_POST['csrf_token'] ?? null)
                ?? (is_object($this->request) && method_exists($this->request, 'getRawValue') ? $this->request->getRawValue('csrf_token') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('csrf_token') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getStringParam') ? $this->request->getStringParam('csrf_token', '') : '')
            );

            $fileId = (int) (
                (is_object($this->request) && method_exists($this->request, 'getIntegerParam') ? $this->request->getIntegerParam('file_id') : 0)
                ?: ($rawPost['file_id'] ?? ($_POST['file_id'] ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('file_id', 0) : 0)))
            );

            $taskId = (int) (
                (is_object($this->request) && method_exists($this->request, 'getIntegerParam') ? $this->request->getIntegerParam('task_id') : 0)
                ?: ($rawPost['task_id'] ?? ($_POST['task_id'] ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('task_id', 0) : 0)))
            );

            $projectId = (int) (
                (is_object($this->request) && method_exists($this->request, 'getIntegerParam') ? $this->request->getIntegerParam('project_id') : 0)
                ?: ($rawPost['project_id'] ?? ($_POST['project_id'] ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('project_id', 0) : 0)))
            );

            $newContent = (string) (
                $rawPost['content']
                ?? ($_POST['content'] ?? null)
                ?? (is_object($this->request) && method_exists($this->request, 'getRawValue') ? $this->request->getRawValue('content') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('content') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getStringParam') ? $this->request->getStringParam('content', '') : '')
            );

            $gridDataRaw = (string) (
                $rawPost['grid_data']
                ?? ($_POST['grid_data'] ?? null)
                ?? (is_object($this->request) && method_exists($this->request, 'getRawValue') ? $this->request->getRawValue('grid_data') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('grid_data') : null)
                ?? ''
            );

            $mode = (string) (
                $rawPost['mode']
                ?? ($_POST['mode'] ?? null)
                ?? (is_object($this->request) && method_exists($this->request, 'getRawValue') ? $this->request->getRawValue('mode') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getValue') ? $this->request->getValue('mode') : null)
                ?? (is_object($this->request) && method_exists($this->request, 'getStringParam') ? $this->request->getStringParam('mode', 'overwrite') : 'overwrite')
            );

            // CSRF check: validate form POST token first, fallback to checkCSRFParam
            $csrfValid = true;
            if ($this->hasService('token') && is_object($this->token) && method_exists($this->token, 'validateCSRFToken')) {
                $valRes = $this->token->validateCSRFToken($csrfToken);
                $reuseRes = method_exists($this->token, 'validateReusableCSRFToken') ? $this->token->validateReusableCSRFToken($csrfToken) : false;
                $csrfValid = $csrfToken !== '' && ($valRes || $reuseRes);
            } elseif (method_exists($this, 'checkCSRFParam')) {
                $csrfValid = $this->checkCSRFParam();
            }

            if (!$csrfValid) {
                return $this->renderErrorModal(true, 'CSRF validation failed.', 403, 'csrf_error');
            }
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

            // Highest-stakes instance of the gate: without it a member of any project
            // could overwrite an attachment belonging to a project they cannot see.
            try {
                $this->assertAttachmentOwnership((array) $file, $taskId, $projectId);
            } catch (AccessDeniedException $e) {
                return $this->renderErrorModal(true, $e->getMessage(), 403, 'access_denied');
            }

            $filename = (string) ($file['name'] ?? '');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $normalizedExt = strtolower($extension);
        if (!$this->editValidationService->isEditableExtension($normalizedExt)) {
            return $this->renderErrorModal($canRender, 'Editing is not supported for this file format.', 400, 'invalid_file');
        }

        $contentToSave = $newContent;

        if (in_array($normalizedExt, ['xlsx', 'xls'], true)) {
            if ($gridDataRaw !== '') {
                $gridData = @json_decode($gridDataRaw, true);
                if (is_array($gridData) && !empty($gridData)) {
                    $contentToSave = $this->excelWriter->buildXlsxFromMultiSheet($gridData);
                } else {
                    $contentToSave = $this->excelWriter->csvToXlsx($newContent);
                }
            } else {
                $contentToSave = $this->excelWriter->csvToXlsx($newContent);
            }
        } elseif (in_array($normalizedExt, ['csv', 'tsv'], true) && $gridDataRaw !== '') {
            $gridData = @json_decode($gridDataRaw, true);
            if (is_array($gridData) && !empty($gridData)) {
                $firstSheetRows = is_array(reset($gridData)) ? reset($gridData) : [];
                if (isset($firstSheetRows['rows']) && is_array($firstSheetRows['rows'])) {
                    $firstSheetRows = $firstSheetRows['rows'];
                }
                $fp = fopen('php://temp', 'r+');
                if ($fp !== false) {
                    $delimiter = $normalizedExt === 'tsv' ? "\t" : ',';
                    foreach ($firstSheetRows as $row) {
                        if (is_array($row)) {
                            fputcsv($fp, array_map('strval', $row), $delimiter, '"', "\\");
                        }
                    }
                    rewind($fp);
                    $csvFormatted = stream_get_contents($fp);
                    fclose($fp);
                    if ($csvFormatted !== false) {
                        $contentToSave = $csvFormatted;
                    }
                }
            }
        }

        // Pre-save validation
        $valResult = $this->editValidationService->validate($contentToSave, $normalizedExt);
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
                $this->taskFileModel->uploadContent($taskId, $newFilename, $contentToSave);
            } else {
                $this->objectStorage->put($path, $contentToSave);
            }

            $isAjax = $this->hasService('request') && is_object($this->request) && method_exists($this->request, 'isAjax') && $this->request->isAjax();
            if (!$isAjax) {
                $flash = $this->hasService('flash') ? ($this->flash ?? null) : null;
                if (is_object($flash) && method_exists($flash, 'success')) {
                    $flash->success(function_exists('t') ? t('File saved successfully.') : 'File saved successfully.');
                }
                $urlHelper = $this->hasService('helper') ? ($this->helper->url ?? null) : null;
                if (is_object($urlHelper) && method_exists($urlHelper, 'to')) {
                    return $this->response->redirect(
                        $urlHelper->to('FilePreviewController', 'show', [
                            'plugin' => 'FileInteractionCore',
                            'file_id' => $fileId,
                            'task_id' => $taskId,
                            'project_id' => $projectId,
                        ])
                    );
                }
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
        return $this->renderErrorModalResponse(
            $canRender,
            $message,
            $statusCode,
            $reason,
            $errorLine
        );
    }
}

