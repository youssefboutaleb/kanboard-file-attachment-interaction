<div class="page-header">
    <h2>
        <i class="fa fa-file-pdf-o" style="color: #d9534f;"></i>
        <?= $this->text->e($filename) ?>
        <span class="badge" style="font-size: 0.8em; margin-left: 10px; background: #d9534f; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $this->text->e($handler) ?>
        </span>
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
// Two distinct core actions are required here:
//   - browser:  streams the file with Content-Type: application/pdf and NO
//               attachment disposition, so <object> can render it inline.
//   - download: streams with Content-Disposition: attachment (save dialog).
// Using `download` for the <object> would make every browser show a save
// prompt instead of the embedded viewer.
$inlineUrl = $this->url->href('FileViewerController', 'browser', $downloadParams);
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

<div class="panel-meta" style="font-size: 0.85em; color: #6a737d; display: flex; justify-content: space-between; margin-top: 5px;">
    <span>
        <i class="fa fa-info-circle"></i> <?= t('PDF Reader Modal') ?>
    </span>
    <span>
        <a href="<?= $downloadUrl ?>" target="_blank" rel="noopener noreferrer" style="color: #0366d6; text-decoration: none;">
            <i class="fa fa-external-link"></i> <?= t('Open Fullscreen / Download') ?>
        </a>
    </span>
</div>
