<div class="page-header">
    <h2>
        <i class="fa fa-file-pdf-o" style="color: #d9534f;"></i>
        <?= $this->text->e($filename) ?>
        <?php if (!empty($metadata['sizeBytes'])): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= $this->text->bytes($metadata['sizeBytes']) ?>
            </span>
        <?php endif; ?>
    </h2>
</div>

<?php
$downloadParams = [
    'task_id' => $taskId,
    'file_id' => $fileId,
];
if ($projectId > 0) {
    $downloadParams['project_id'] = $projectId;
}

$streamParams = [
    'plugin' => 'FileInteractionCore',
    'project_id' => $projectId,
    'task_id' => $taskId,
    'file_id' => $fileId,
];
$inlineUrl = $this->url->href('FileStreamController', 'inline', $streamParams);
$downloadUrl = $this->url->href('FileViewerController', 'download', $downloadParams);
?>

<div class="pdf-viewer-wrapper" style="width: 100%; height: 520px; border: 1px solid #dee2e6; border-radius: 6px; overflow: hidden; background: #525659; margin-bottom: 10px;">
    <object data="<?= $inlineUrl ?>" type="application/pdf" width="100%" height="100%" style="border: none;">
        <div style="padding: 30px; text-align: center; color: #fff; background: #323639; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center;">
            <p style="font-size: 16px; margin-bottom: 15px;">
                <i class="fa fa-exclamation-circle"></i> <?= t('Inline PDF viewing is not supported by your browser or plugin.') ?>
            </p>
            <a href="<?= $downloadUrl ?>" class="btn btn-blue" target="_blank" rel="noopener noreferrer" style="font-weight: 600; padding: 8px 16px;">
                <i class="fa fa-download"></i> <?= t('Download PDF Document') ?>
            </a>
        </div>
    </object>
</div>

<?= $this->render('FileInteractionCore:file/modal_actions', [
    'typeLabel' => $typeLabel ?? 'PDF Document',
    'metaSummary' => t('Inline viewer'),
    'isEditableFormat' => false,
    'editParams' => [],
    'taskId' => $taskId ?? 0,
    'projectId' => $projectId ?? 0,
    'fileId' => $fileId ?? 0,
    'showEditSwitcher' => false,
    'rawViewAvailable' => false,
    'viewMode' => $viewMode ?? 'rendered',
    'viewToggleParams' => $viewToggleParams ?? [],
    'openTabUrl' => $inlineUrl,
    'is_ajax' => $is_ajax ?? true,
]) ?>
