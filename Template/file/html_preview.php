<?php
/**
 * Safe HTML preview modal rendering .html and .htm attachments in a sandboxed iframe.
 *
 * SECURITY: The iframe carries `sandbox=""` (no allow-scripts, no allow-same-origin),
 * guaranteeing zero JavaScript execution, zero cookie/storage access, and zero DOM access
 * to the parent Kanboard window.
 *
 * Variables supplied by FilePreviewController::show():
 *   $filename, $handler, $extension, $content, $metadata
 */
?>
<div class="page-header">
    <h2>
        <i class="fa fa-file-code-o" style="color: #e34c26;"></i>
        <?= $this->text->e($filename) ?>
        <?php if (!empty($metadata['originalSizeBytes'])): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= $this->text->bytes($metadata['originalSizeBytes']) ?>
            </span>
        <?php endif; ?>
    </h2>
</div>

<?php if (!empty($metadata['truncated'])): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('File content exceeds maximum preview size limit (%d KB) and has been truncated.', round(((int) ($metadata['maxSizeBytes'] ?? 0)) / 1024)) ?>
    </div>
<?php endif; ?>

<div class="html-viewer-wrapper" style="width: 100%; height: 520px; border: 1px solid #dee2e6; border-radius: 6px; overflow: hidden; background: #fff; margin-bottom: 10px;">
    <iframe sandbox=""
            srcdoc="<?= htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            style="width: 100%; height: 100%; border: none; display: block;"
            title="<?= $this->text->e($filename) ?>">
    </iframe>
</div>

<?= $this->render('FileInteractionCore:file/modal_actions', [
    'typeLabel' => $typeLabel ?? 'HTML',
    'metaSummary' => t('%d Lines', (int) ($metadata['lineCount'] ?? 0)),
    'isEditableFormat' => $isEditableFormat ?? true,
    'editParams' => $editParams ?? [],
    'taskId' => $taskId ?? 0,
    'projectId' => $projectId ?? 0,
    'fileId' => $fileId ?? 0,
    'showEditSwitcher' => true,
    'rawViewAvailable' => $rawViewAvailable ?? true,
    'viewMode' => $viewMode ?? 'rendered',
    'viewToggleParams' => $viewToggleParams ?? [],
    'openTabUrl' => $openTabUrl ?? null,
    'is_ajax' => $is_ajax ?? true,
]) ?>
