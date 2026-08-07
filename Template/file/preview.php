<div class="page-header">
    <h2>
        <i class="fa fa-file-text-o"></i>
        <?= $this->text->e($filename) ?>
        <span class="badge" style="font-size: 0.8em; margin-left: 10px; background: #2b579a; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $this->text->e($handler) ?>
        </span>
    </h2>
</div>

<div class="file-preview-container" style="background: #f8f9fa; border: 1px solid #e1e4e8; border-radius: 6px; padding: 15px; max-height: 500px; overflow-y: auto;">
    <?php if (!empty($metadata['truncated'])): ?>
        <div class="alert alert-warning" style="margin-bottom: 10px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
            <i class="fa fa-exclamation-triangle"></i>
            <?= t('File content exceeds maximum preview size limit (%d KB) and has been truncated.', round($metadata['maxSizeBytes'] / 1024)) ?>
        </div>
    <?php endif; ?>

    <pre style="margin: 0; font-family: monospace; white-space: pre-wrap; word-wrap: break-word; font-size: 13px; color: #24292e;"><code><?= $content ?></code></pre>
</div>

<div class="panel-meta" style="margin-top: 10px; font-size: 0.85em; color: #6a737d; display: flex; justify-content: space-between;">
    <span>
        <i class="fa fa-align-left"></i> <?= t('%d Lines', $metadata['lineCount'] ?? 0) ?>
        &nbsp;|&nbsp;
        <i class="fa fa-font"></i> <?= t('%d Characters', $metadata['charCount'] ?? 0) ?>
    </span>
    <span>
        <i class="fa fa-lock"></i> <?= t('Safe Escaped Plain Text View') ?>
    </span>
</div>
