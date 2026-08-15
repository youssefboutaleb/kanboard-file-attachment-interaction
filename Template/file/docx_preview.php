<?php
/**
 * Word document preview template (Milestone 9 - High-Fidelity DOCX Engine).
 *
 * Variables supplied by FilePreviewController::show():
 *   $filename, $handler, $extension, $metadata, $content, $is_ajax
 */
$paragraphCount = (int) ($metadata['paragraphCount'] ?? 0);
$headingCount = (int) ($metadata['headingCount'] ?? 0);
$tableCount = (int) ($metadata['tableCount'] ?? 0);
$wordCount = (int) ($metadata['wordCount'] ?? 0);
$isLegacyFormat = !empty($metadata['isLegacyFormat']);
$isParsed = !empty($metadata['parsed']);

$streamParams = [
    'plugin' => 'FileInteractionCore',
    'project_id' => $projectId ?? 0,
    'task_id' => $taskId ?? 0,
    'file_id' => $fileId ?? 0,
];
$inlineUrl = $this->url->href('FileStreamController', 'inline', $streamParams);

$downloadParams = [
    'task_id' => $taskId ?? 0,
    'file_id' => $fileId ?? 0,
];
if (!empty($projectId)) {
    $downloadParams['project_id'] = $projectId;
}
$downloadUrl = $this->url->href('FileViewerController', 'download', $downloadParams);
?>
<div class="page-header">
    <h2>
        <i class="fa fa-file-word-o" style="color: #2b579a;"></i>
        <?= $this->text->e($filename) ?>
        <?php if ($isParsed && !$isLegacyFormat): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #2b579a; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= t('%d Words', $wordCount) ?>
            </span>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= t('%d Paragraphs', $paragraphCount) ?>
            </span>
            <?php if ($tableCount > 0): ?>
                <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                    <?= t('%d Tables', $tableCount) ?>
                </span>
            <?php endif; ?>
        <?php endif; ?>
    </h2>
</div>

<?php if ($isLegacyFormat): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 12px 16px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('Legacy .doc documents use the binary Word format and cannot be rendered inline. Download the file to open it.') ?>
        <div style="margin-top: 10px;">
            <a href="<?= $downloadUrl ?>" class="btn btn-blue">
                <i class="fa fa-download"></i> <?= t('Download Document') ?>
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="docx-document-wrapper fic-docx-container"
         data-fic-stream-url="<?= $inlineUrl ?>"
         style="width: 100%; height: 560px; max-height: calc(100vh - 190px); overflow-y: auto; background: #f0f2f5; border: 1px solid #dee2e6; border-radius: 6px; padding: 16px; box-sizing: border-box; margin-bottom: 10px; position: relative;">
        
        <div class="fic-office-loading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 380px; color: #2b579a; font-size: 14px;">
            <i class="fa fa-spinner fa-spin fa-3x" style="margin-bottom: 14px;"></i>
            <span><?= t('Loading Word document preview...') ?></span>
        </div>

        <div class="fic-office-error alert alert-warning" style="display: none; margin: 20px auto; max-width: 600px; padding: 12px 16px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
            <i class="fa fa-exclamation-triangle"></i> <?= t('High-fidelity rendering failed. Showing text fallback below.') ?>
        </div>

        <div class="fic-docx-render-target" style="display: none; min-height: 100%;">
            <!-- Rendered by docx-preview client-side -->
        </div>

        <noscript>
            <div class="docx-page" style="background: #fff; max-width: 800px; margin: 0 auto; padding: 36px 48px; border: 1px solid #d0d7de; border-radius: 4px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #24292f;">
                <?= $content ?>
            </div>
        </noscript>
    </div>
<?php endif; ?>

<?= $this->render('FileInteractionCore:file/modal_actions', [
    'typeLabel' => $typeLabel ?? 'Word Document',
    'metaSummary' => $isParsed ? t('%d Words', $wordCount) : '',
    'isEditableFormat' => false,
    'editParams' => [],
    'taskId' => $taskId ?? 0,
    'projectId' => $projectId ?? 0,
    'fileId' => $fileId ?? 0,
    'showEditSwitcher' => false,
    'rawViewAvailable' => false,
    'viewMode' => 'rendered',
    'viewToggleParams' => $viewToggleParams ?? [],
    'openTabUrl' => $openTabUrl ?? null,
    'is_ajax' => $is_ajax ?? true,
]) ?>
