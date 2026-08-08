<?php

$allowedExtensions = [
    'txt', 'json', 'md', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm',
    'csv', 'tsv',
    'markdown', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql',
    'pdf', 'xlsx', 'xls',
];

/**
 * Formats offered for in-app editing — mirrors
 * FileEditValidationService::EDITABLE_EXTENSIONS and is deliberately narrower
 * than $allowedExtensions: binary (pdf), tabular (csv/tsv) and active-content
 * (html) formats must never open in a plain-text editor.
 */
$editableExtensions = [
    'txt', 'json', 'md', 'markdown',
    'yml', 'yaml',
    'sh', 'py', 'js', 'css', 'sql',
];
$extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

// The project-overview hook passes `project` + `file` only; the task hooks pass `task` + `file`.
$previewTask = isset($task) && is_array($task) ? $task : [];
$previewProject = isset($project) && is_array($project) ? $project : [];
$isProjectFile = $previewTask === [] && $previewProject !== [];

$previewParams = [
    'plugin' => 'FileInteractionCore',
    'file_id' => $file['id'] ?? 0,
    'task_id' => $previewTask['id'] ?? $file['task_id'] ?? 0,
    'project_id' => $previewTask['project_id'] ?? $previewProject['id'] ?? $file['project_id'] ?? 0,
];

if ($isProjectFile) {
    $previewParams['source'] = 'project';
}

/**
 * Write gate for the editor entry point.
 *
 * `TaskFileController::remove` is the core ACL entry meaning "may mutate this
 * project's attachments" (the same check core uses for the Remove item in
 * app/Template/task_file/files.php). Our plugin controller is not registered in
 * Kanboard's access map, so it cannot be queried here.
 *
 * Project-overview attachments are excluded: FileEditController resolves files
 * through taskFileModel only, so there is no editable target without a task.
 */
$canEditAttachment = !$isProjectFile
    && (int) $previewParams['project_id'] > 0
    && $this->user->hasProjectAccess('TaskFileController', 'remove', (int) $previewParams['project_id']);

if (in_array($extension, $allowedExtensions, true)):
?>
    <li>
        <?= $this->modal->medium('eye', t('Safe Preview'), 'FilePreviewController', 'show', $previewParams) ?>
    </li>
<?php endif ?>

<?php if ($canEditAttachment && in_array($extension, $editableExtensions, true)): ?>
    <li>
        <?= $this->modal->medium('pencil', t('Edit Attachment'), 'FileEditController', 'edit', $previewParams) ?>
    </li>
<?php endif ?>
