<?php
/**
 * Safe markdown preview template (spec 003).
 *
 * Expects: $content (pre-rendered/pre-escaped HTML), $filename, $extension,
 * $taskId, $fileId, $projectId, $metadata (array with headingCount, codeBlockCount, etc.)
 */
$isCodeView = !empty($isCodeView) || ($handler ?? "") === "CodePreviewHandler";
$language = (string) ($metadata["language"] ?? "");
?>
<div class="page-header">
    <h2>
        <i class="fa <?= $isCodeView ? "fa-code" : "fa-file-text-o" ?>"></i>
        <?= $this->text->e($filename) ?>
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

<div class="markdown-body" style="background: #fff; border: 1px solid #e1e4e8; border-radius: 6px; padding: 20px; max-height: 500px; overflow: auto; font-size: 14px; line-height: 1.6; color: #24292e; word-wrap: break-word;">
    <style>
        .markdown-body h1, .markdown-body h2, .markdown-body h3,
        .markdown-body h4, .markdown-body h5, .markdown-body h6 {
            margin: 18px 0 10px; font-weight: 600; line-height: 1.25; color: #1b1f23;
        }
        .markdown-body h1 { font-size: 1.8em; border-bottom: 1px solid #eaecef; padding-bottom: 6px; }
        .markdown-body h2 { font-size: 1.45em; border-bottom: 1px solid #eaecef; padding-bottom: 5px; }
        .markdown-body h3 { font-size: 1.2em; }
        .markdown-body p { margin: 0 0 12px; }
        .markdown-body ul, .markdown-body ol { margin: 0 0 12px; padding-left: 26px; }
        .markdown-body li { margin-bottom: 4px; }
        .markdown-body blockquote {
            margin: 0 0 12px; padding: 4px 14px; color: #6a737d;
            border-left: 4px solid #dfe2e5; background: #f8f9fa;
        }
        .markdown-body pre {
            background: #f6f8fa; padding: 12px; border-radius: 6px; border: 1px solid #e1e4e8;
            overflow-x: auto; font-size: 13px; margin: 0 0 12px;
        }
        .markdown-body pre code { background: none; padding: 0; font-size: inherit; }
        .markdown-body code {
            background: rgba(27, 31, 35, 0.05); padding: 2px 5px; border-radius: 3px;
            font-family: monospace; font-size: 0.9em;
        }
        .markdown-body a { color: #0366d6; text-decoration: none; }
        .markdown-body a:hover { text-decoration: underline; }
        .markdown-body table { border-collapse: collapse; margin: 0 0 12px; }
        .markdown-body th, .markdown-body td { border: 1px solid #dfe2e5; padding: 6px 10px; }
    </style>

    <?php if (trim((string) $content) === ""): ?>
        <div style="padding: 20px; text-align: center; color: #6c757d;">
            <i class="fa fa-info-circle"></i>
            <?= $isCodeView ? t("The source file is empty.") : t("The Markdown document is empty.") ?>
        </div>
    <?php else: ?>
        <?= $content ?>
    <?php endif; ?>
</div>

<?= $this->render("FileInteractionCore:file/modal_actions", [
    "typeLabel" => $typeLabel ?? ($isCodeView ? "Code" : "Markdown"),
    "metaSummary" => $isCodeView
        ? t("%d Lines", (int) ($metadata["lineCount"] ?? 0))
        : t("%d Headings", (int) ($metadata["headingCount"] ?? 0)),
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
