<div class="page-header">
    <h2>
        <i class="fa fa-exclamation-triangle"></i>
        <?= $reason === 'access_denied' ? t('Preview Not Permitted') : t('Preview Not Available') ?>
    </h2>
</div>

<div class="alert alert-error" style="padding: 12px 15px; background: #fdecea; color: #7f231c; border: 1px solid #f5c6cb; border-radius: 6px;">
    <?php if (!empty($filename)): ?>
        <p style="margin: 0 0 8px;"><strong><?= $this->text->e($filename) ?></strong></p>
    <?php endif ?>
    <p style="margin: 0;"><?= $this->text->e($message) ?></p>
</div>

<div class="panel-meta" style="margin-top: 10px; font-size: 0.85em; color: #6a737d;">
    <i class="fa fa-lock"></i> <?= t('Safe Escaped Plain Text View') ?>
</div>
