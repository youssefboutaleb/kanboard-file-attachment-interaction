<?php
/**
 * Safe text/code/json preview template (spec 001).
 *
 * Expects: $content (pre-escaped HTML), $filename, $extension, $taskId, $fileId,
 * $projectId, $metadata (array with lineCount, byteSize, isTruncated, etc.)
 */
$isCodeView = ($extension ?? "") !== "txt";
?>
<div class="page-header">
    <h2>
        <i class="fa fa-file-text-o"></i>
        <?= $this->text->e($filename) ?>
        <?php if (!empty($metadata['detectedAsText'])): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= t('Detected Text') ?>
            </span>
        <?php endif; ?>
        <?php if (!empty($languageSelectorEnabled)): ?>
            <?= $this->render('FileInteractionCore:file/language_selector', [
                'languageOptions' => $languageOptions ?? [],
                'selectedLanguage' => $selectedLanguage ?? '',
                'languageParams' => $languageParams ?? [],
            ]) ?>
        <?php endif; ?>
    </h2>
</div>

<?php if (!empty($metadata["truncated"])): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t("File content exceeds maximum preview size limit (%d KB) and has been truncated.", (int) round(((int) ($metadata["maxSizeBytes"] ?? 0)) / 1024)) ?>
    </div>
<?php endif; ?>

<div class="fic-preview-container" style="background: #f6f8fa; border: 1px solid #e1e4e8; border-radius: 6px; padding: 15px; max-height: 500px; overflow: auto;">
    <pre style="margin: 0; font-family: monospace; white-space: pre-wrap; word-wrap: break-word; font-size: 13px; color: #24292e;"><code><?= $content ?></code></pre>
</div>

<?= $this->render("FileInteractionCore:file/modal_actions", [
    "typeLabel" => $typeLabel ?? "File",
    "metaSummary" => t("%d Lines", (int) ($metadata["lineCount"] ?? 0)),
    "isEditableFormat" => $isEditableFormat ?? false,
    "editParams" => $editParams ?? [],
    "taskId" => $taskId ?? 0,
    "projectId" => $projectId ?? 0,
    "fileId" => $fileId ?? 0,
    "showEditSwitcher" => true,
    "rawViewAvailable" => $rawViewAvailable ?? false,
    "viewMode" => $viewMode ?? "rendered",
    "viewToggleParams" => $viewToggleParams ?? [],
    "openTabUrl" => $openTabUrl ?? null,
    "is_ajax" => $is_ajax ?? true,
]) ?>
