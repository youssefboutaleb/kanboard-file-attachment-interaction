<?php

$allowedExtensions = [
    'txt', 'json', 'md', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm',
    'csv', 'tsv',
    'markdown', 'sh', 'bash', 'py', 'python', 'php', 'js', 'css', 'sql',
    'pdf', 'xlsx', 'xls',
    'docx', 'dotx', 'doc',
    'pptx', 'potx', 'ppt',
];

/**
 * Media formats Kanboard core previews itself — mirrors
 * FileValidationService::CORE_MEDIA_EXTENSIONS.
 *
 * Task 36 lets unknown extensions reach Safe Preview, but these are excluded:
 * core already renders a working image/audio/video viewer, and `svg` is active
 * content that must stay out of every preview path. Keeping them out also
 * preserves Task 35's guarantee that the dropdown cleanup script never removes
 * core's "View file" action for a format we cannot replace.
 */
$coreMediaExtensions = [
    'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'tiff', 'tif', 'svg',
    'mp3', 'ogg', 'flac', 'wav', 'wma', 'm4a', 'aac', 'opus', 'amr', 'midi', 'mid',
    'mp4', 'avi', 'mov', 'mkv', 'webm', 'm4v', 'wmv', 'flv', 'mpg', 'mpeg', '3gp',
];

/**
 * Formats offered for in-app editing — mirrors
 * FileEditValidationService::EDITABLE_EXTENSIONS.
 */
$editableExtensions = [
    'txt', 'json', 'md', 'markdown',
    'env', 'ini', 'conf', 'log',
    'yml', 'yaml', 'xml',
    'sh', 'bash', 'py', 'python', 'js', 'css', 'sql',
    'html', 'htm',
    'csv', 'tsv', 'xlsx', 'xls',
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

$isHandledExtension = in_array($extension, $allowedExtensions, true);

/**
 * Task 36: attachments with an unknown or missing extension also get a Safe
 * Preview entry. FilePreviewController inspects their content and answers with
 * either an escaped text view or a "Binary File — download instead" notice, so
 * the action is always useful and never renders an unclassified payload.
 *
 * Core-owned media is excluded (see $coreMediaExtensions above).
 */
$isUnclassifiedExtension = !$isHandledExtension
    && !in_array($extension, $coreMediaExtensions, true);

if ($isHandledExtension || $isUnclassifiedExtension):
?>
    <?php
    /**
     * The `fic-safe-preview` marker is the cleanup gate for Task 35.
     *
     * Core renders its own un-sandboxed "View file" action into this same <ul>
     * BEFORE the dropdown hook fires, so it cannot be suppressed from here.
     * Assets/js/dropdown-cleanup.js removes it client-side, scoped to the
     * dropdown owning this marker — which only exists for formats Safe Preview
     * claims, so core keeps its view action for audio/video/svg attachments.
     *
     * The marker is withheld for unclassified extensions: core renders no view
     * action for them (both getPreviewType() and getBrowserViewType() return
     * null), so there is no orphan to clean and no reason to arm the script.
     *
     * $extension derives from an uploaded filename and is escaped regardless.
     */
    ?>
    <li<?= $isHandledExtension
        ? ' class="fic-safe-preview" data-fic-ext="' . htmlspecialchars($extension, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
        : '' ?>>
        <?= $this->modal->medium('eye', t('Safe Preview'), 'FilePreviewController', 'show', $previewParams) ?>
    </li>
<?php endif ?>

<?php if ($canEditAttachment && in_array($extension, $editableExtensions, true)): ?>
    <li>
        <?= $this->modal->medium('pencil', t('Edit Attachment'), 'FileEditController', 'edit', $previewParams) ?>
    </li>
<?php endif ?>

