<?php
/**
 * Notice shown when an attachment cannot be previewed as text.
 *
 * Reached only through the Task 36 unknown-extension flow, after
 * BinaryContentDetector classified the payload as binary — or when the file was
 * too large to read at all. NOTHING from the attachment is rendered here: the
 * body is never echoed, only its metadata, so a hostile payload has no surface.
 *
 * Expects: $filename, $extension, $taskId, $fileId, $projectId, $metadata.
 */
$reason = (string) ($metadata['reason'] ?? 'binary');
$sizeBytes = (int) ($metadata['sizeBytes'] ?? 0);

$downloadParams = [
    'task_id' => $taskId,
    'file_id' => $fileId,
];
if ($projectId > 0) {
    $downloadParams['project_id'] = $projectId;
}
$downloadUrl = $this->url->href('FileViewerController', 'download', $downloadParams);

$reasonLabel = match ($reason) {
    'too_large' => t('The file is too large to inspect safely (limit %d MB).', (int) round(((int) ($metadata['maxSizeBytes'] ?? 0)) / 1048576)),
    'null_byte' => t('The content contains null bytes, which never occur in text files.'),
    'control_characters' => t('The content is mostly non-printable control characters.'),
    'invalid_encoding' => t('The content is not valid UTF-8 text.'),
    default => t('The content could not be interpreted as text.'),
};
?>
<div class="page-header">
    <h2>
        <i class="fa fa-file-o" style="color: #6c757d;"></i>
        <?= $this->text->e($filename) ?>
        <span class="badge" style="font-size: 0.8em; margin-left: 10px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $extension === '' ? t('No Extension') : $this->text->e(strtoupper($extension)) ?>
        </span>
        <?php if ($sizeBytes > 0): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= $this->text->bytes($sizeBytes) ?>
            </span>
        <?php endif ?>
    </h2>
</div>

<div class="fic-binary-notice" style="background: #f8f9fa; border: 1px solid #e1e4e8; border-radius: 6px; padding: 30px; text-align: center;">
    <p style="font-size: 42px; margin: 0 0 10px; color: #adb5bd;">
        <i class="fa fa-file-archive-o" aria-hidden="true"></i>
    </p>
    <p style="font-size: 16px; font-weight: 600; color: #24292e; margin: 0 0 6px;">
        <?= t('Binary File (Preview not supported, click Download)') ?>
    </p>
    <p style="font-size: 0.9em; color: #6a737d; margin: 0 0 18px;">
        <?= $reasonLabel ?>
    </p>
    <a href="<?= $downloadUrl ?>" class="btn btn-blue" style="font-weight: 600; padding: 8px 16px;">
        <i class="fa fa-download" aria-hidden="true"></i> <?= t('Download') ?>
    </a>

    <?php if (!empty($renderAvailable)): ?>
        <?php
        $isAjaxRequest = !isset($is_ajax) || $is_ajax !== false;
        ?>
        <a href="<?= $this->url->href('FilePreviewController', 'show', array_merge($renderParams ?? [], ['view' => 'rendered'])) ?>"
           class="<?= $isAjaxRequest ? 'js-modal-medium ' : '' ?>fic-btn-render"
           style="font-weight: 600; padding: 8px 16px; margin-left: 8px; color: #0366d6; text-decoration: none;">
            <i class="fa fa-eye" aria-hidden="true"></i> <?= t('Render') ?>
        </a>
    <?php endif ?>
</div>

<?= $this->render('FileInteractionCore:file/modal_actions', [
    'typeLabel' => $typeLabel ?? 'Binary File',
    'metaSummary' => t('Detected by content inspection (%d bytes sampled)', (int) ($metadata['sniffedBytes'] ?? 0)),
    'isEditableFormat' => false,
    'editParams' => [],
    'taskId' => $taskId ?? 0,
    'projectId' => $projectId ?? 0,
    'fileId' => $fileId ?? 0,
    'showEditSwitcher' => false,
    'rawViewAvailable' => false,
    'viewMode' => 'rendered',
    'viewToggleParams' => $renderParams ?? [],
    'openTabUrl' => $openTabUrl ?? null,
    'is_ajax' => $is_ajax ?? true,
]) ?>
