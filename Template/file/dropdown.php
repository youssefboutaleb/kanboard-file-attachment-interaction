<?php

$allowedExtensions = [
    'txt', 'json', 'md', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm',
    'csv', 'tsv',
    'markdown', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql',
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

if (in_array($extension, $allowedExtensions, true)):
?>
    <li>
        <?= $this->modal->medium('eye', t('Safe Preview'), 'FilePreviewController', 'show', $previewParams) ?>
    </li>
<?php endif ?>
