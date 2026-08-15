<?php
/**
 * Live attachment editor modal (spec 005 & spreadsheet grid editor).
 *
 * Variables supplied by FileEditController::edit():
 *   $fileId, $taskId, $projectId, $filename, $extension, $content, $isSpreadsheet, $sheets, $sheetNames, $activeSheet
 */
$editorContent = (string) ($content ?? '');
$editorExtension = strtolower((string) ($extension ?? ''));
$isSpreadsheet = !empty($isSpreadsheet);
$sheets = $sheets ?? [];
$sheetNames = $sheetNames ?? [];
$activeSheet = $activeSheet ?? ($sheetNames[0] ?? 'Sheet1');
$sheetCount = count($sheetNames);

$lineCount = $editorContent === '' ? 0 : substr_count($editorContent, "\n") + 1;
$charCount = strlen($editorContent);
$isJson = $editorExtension === 'json';

// Initial server-side syntax state; Assets/js/editor.js re-evaluates on input.
$jsonIsValid = true;
if ($isJson && trim($editorContent) !== '') {
    json_decode($editorContent);
    $jsonIsValid = json_last_error() === JSON_ERROR_NONE;
}

$updateUrl = $this->url->href('FileEditController', 'update', [
    'plugin' => 'FileInteractionCore',
    'file_id' => (int) ($fileId ?? 0),
    'task_id' => (int) ($taskId ?? 0),
    'project_id' => (int) ($projectId ?? 0),
]);

$columnLabel = static function (int $index): string {
    $label = '';
    do {
        $label = chr(65 + ($index % 26)) . $label;
        $index = intdiv($index, 26) - 1;
    } while ($index >= 0);
    return $label;
};
?>
<div class="page-header">
    <h2>
        <i class="fa <?= $isSpreadsheet ? 'fa-table' : 'fa-pencil-square-o' ?>" style="<?= $isSpreadsheet ? 'color: #1d6f42;' : '' ?>"></i>
        <?= $this->text->e($filename ?? '') ?>
        <span class="badge" style="font-size: 0.8em; margin-left: 10px; background: #2b579a; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $this->text->e(strtoupper($editorExtension)) ?>
        </span>
        <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $this->text->bytes($charCount) ?>
        </span>
    </h2>
</div>

<div id="fic-edit-alert" class="alert alert-error" style="display: none; margin-bottom: 12px; padding: 8px 12px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;"></div>

<div class="fic-editor-status" style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85em; color: #6a737d; padding: 6px 10px; background: #f6f8fa; border: 1px solid #e1e4e8; border-bottom: none; border-radius: 6px 6px 0 0;">
    <span>
        <?php if ($isSpreadsheet): ?>
            <i class="fa fa-table"></i> <span id="fic-grid-info"><?= t('Interactive Spreadsheet Grid Mode') ?></span>
        <?php else: ?>
            <i class="fa fa-align-left"></i> <span id="fic-line-count"><?= $lineCount ?></span> <?= t('Lines') ?>
            &nbsp;|&nbsp;
            <i class="fa fa-font"></i> <span id="fic-char-count"><?= $charCount ?></span> <?= t('Characters') ?>
        <?php endif; ?>
    </span>
    <span id="fic-syntax-status" data-format="<?= $this->text->e($editorExtension) ?>"
          <?php if ($isJson): ?>
          data-label-valid="<?= $this->text->e(t('Valid JSON')) ?>"
          data-label-invalid="<?= $this->text->e(t('Invalid JSON Syntax')) ?>"
          <?php endif; ?>
          style="font-weight: 600; color: <?= $isJson && !$jsonIsValid ? '#d9534f' : '#28a745' ?>;">
        <?php if ($isJson): ?>
            <i class="fa <?= $jsonIsValid ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
            <?= $jsonIsValid ? t('Valid JSON') : t('Invalid JSON Syntax') ?>
        <?php elseif ($isSpreadsheet): ?>
            <i class="fa fa-check-circle"></i> <?= t('Spreadsheet Editor') ?>
        <?php else: ?>
            <i class="fa fa-check-circle"></i> <?= t('Plain Text Mode') ?>
        <?php endif; ?>
    </span>
</div>

<form id="fic-edit-form" class="js-modal-ignore-form" method="post" action="<?= $updateUrl ?>" autocomplete="off"
      data-format="<?= $this->text->e($editorExtension) ?>"
      data-is-spreadsheet="<?= $isSpreadsheet ? '1' : '0' ?>"
      data-label-error="<?= $this->text->e(t('Unable to save the attachment.')) ?>"
      data-label-saved="<?= $this->text->e(t('File saved successfully.')) ?>">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="file_id" value="<?= (int) ($fileId ?? 0) ?>">
    <input type="hidden" name="task_id" value="<?= (int) ($taskId ?? 0) ?>">
    <input type="hidden" name="project_id" value="<?= (int) ($projectId ?? 0) ?>">

    <?php if ($isSpreadsheet): ?>
        <?php
        $isMultiSheet = !in_array(strtolower($editorExtension), ['csv', 'tsv', 'txt', 'log']);
        $activeSheetData = $sheets[$activeSheet] ?? ['rows' => []];
        $activeRows = $activeSheetData['rows'] ?? [];
        $columnCount = 0;
        foreach ($activeRows as $r) {
            $columnCount = max($columnCount, count($r));
        }
        $columnCount = max($columnCount, 3);
        if (empty($activeRows)) {
            $activeRows = array_fill(0, 5, array_fill(0, $columnCount, ''));
        }
        ?>
        <input type="hidden" id="fic-grid-data" name="grid_data" value="<?= htmlspecialchars(json_encode($sheets ?: ['Sheet1' => ['rows' => $activeRows]]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <textarea id="fic-edit-content" name="content" style="display: none;"><?= $this->text->e($editorContent) ?></textarea>

        <div class="fic-spreadsheet-editor">
            <!-- Formula / Active Cell Bar & Toolbar -->
            <div class="fic-grid-toolbar">
                <span class="fic-active-cell-ref badge">A1</span>
                <span class="fic-formula-fx">fx</span>
                <input type="text" id="fic-formula-input" placeholder="<?= t('Cell formula or value...') ?>">
                <button type="button" class="btn btn-sm fic-btn-add-row" title="<?= t('Add Row') ?>"><i class="fa fa-plus"></i> <?= t('Row') ?></button>
                <button type="button" class="btn btn-sm fic-btn-add-col" title="<?= t('Add Column') ?>"><i class="fa fa-plus"></i> <?= t('Col') ?></button>
                <button type="button" class="btn btn-sm fic-btn-del-row" title="<?= t('Delete Selected Row') ?>" style="color: #c82333;"><i class="fa fa-minus"></i> <?= t('Row') ?></button>
                <button type="button" class="btn btn-sm fic-btn-del-col" title="<?= t('Delete Selected Column') ?>" style="color: #c82333;"><i class="fa fa-minus"></i> <?= t('Col') ?></button>
                <button type="button" class="btn btn-sm fic-btn-clear-cell" title="<?= t('Clear Active Cell') ?>" style="color: #6c757d;"><i class="fa fa-eraser"></i> <?= t('Clear') ?></button>
                <?php if ($isMultiSheet): ?>
                    <button type="button" class="btn btn-sm fic-btn-add-sheet" title="<?= t('Add New Sheet') ?>" style="color: #107c41;"><i class="fa fa-plus-square"></i> <?= t('Sheet') ?></button>
                <?php endif; ?>
            </div>

            <?php if ($isMultiSheet && $sheetCount > 0): ?>
                <div class="fic-edit-sheet-tabs" role="tablist">
                    <?php foreach ($sheetNames as $idx => $sName): ?>
                        <div class="fic-edit-sheet-tab-wrapper" style="display: inline-flex; align-items: center; background: <?= $sName === $activeSheet ? '#107c41' : '#f1f3f5' ?>; border: 1px solid #d0d7de; border-bottom: none; border-radius: 4px 4px 0 0; padding: 2px 6px; margin-right: 4px; margin-bottom: 4px;">
                            <button type="button" class="fic-edit-sheet-tab<?= $sName === $activeSheet ? ' is-active' : '' ?>"
                                    data-sheet-name="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') ?>"
                                    style="background: none; border: none; cursor: pointer; font-size: 12px; color: <?= $sName === $activeSheet ? '#fff' : '#24292f' ?>; font-weight: <?= $sName === $activeSheet ? '600' : 'normal' ?>; padding: 3px 6px;">
                                <i class="fa fa-table"></i> <span class="fic-sheet-tab-label"><?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                            <button type="button" class="fic-btn-rename-sheet" data-sheet-name="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') ?>" title="<?= t('Rename Sheet') ?>" style="background: none; border: none; cursor: pointer; padding: 1px 3px; color: <?= $sName === $activeSheet ? '#e8f5e9' : '#6c757d' ?>; font-size: 10px;"><i class="fa fa-pencil"></i></button>
                            <?php if ($sheetCount > 1): ?>
                                <button type="button" class="fic-btn-delete-sheet" data-sheet-name="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') ?>" title="<?= t('Delete Sheet') ?>" style="background: none; border: none; cursor: pointer; padding: 1px 3px; color: <?= $sName === $activeSheet ? '#ffcdd2' : '#dc3545' ?>; font-size: 12px; font-weight: bold;">&times;</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Interactive Grid Table -->
            <div class="fic-spreadsheet-grid-wrapper" style="max-height: 420px; overflow: auto; border: 1px solid #dee2e6; border-radius: 4px; background: #fff;">
                <table class="table-bordered fic-spreadsheet-table" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead style="background: #f1f3f5; position: sticky; top: 0; z-index: 2;">
                        <tr>
                            <th style="padding: 4px 6px; border: 1px solid #dee2e6; width: 40px; background: #e9ecef; color: #495057; text-align: center; font-size: 11px;">#</th>
                            <?php for ($col = 0; $col < $columnCount; $col++): ?>
                                <th style="padding: 4px 8px; border: 1px solid #dee2e6; font-weight: 600; color: #495057; font-family: monospace; text-align: center; background: #e9ecef; min-width: 90px;" data-col-index="<?= $col ?>">
                                    <?= $columnLabel($col) ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeRows as $rIdx => $rowCells): ?>
                            <tr>
                                <td style="padding: 4px 6px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f8f9fa; font-weight: bold; user-select: none;" data-row-index="<?= $rIdx ?>"><?= $rIdx + 1 ?></td>
                                <?php for ($col = 0; $col < $columnCount; $col++): ?>
                                    <td contenteditable="true"
                                        class="fic-grid-cell"
                                        data-row="<?= $rIdx ?>"
                                        data-col="<?= $col ?>"
                                        style="padding: 4px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; min-width: 90px; outline: none;"><?= htmlspecialchars((string) ($rowCells[$col] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>

        <div class="fic-editor-wrapper" style="display: flex; border: 1px solid #e1e4e8; border-radius: 0 0 6px 6px; overflow: hidden; background: #fff;">
            <pre id="fic-line-gutter" aria-hidden="true" style="margin: 0; padding: 10px 8px; text-align: right; color: #a0a6ad; background: #f6f8fa; border-right: 1px solid #e1e4e8; font-family: monospace; font-size: 13px; line-height: 1.5; user-select: none; overflow: hidden; min-width: 42px;"><?php
                for ($i = 1; $i <= max($lineCount, 1); $i++) {
                    echo $i, "\n";
                }
            ?></pre>
            <textarea id="fic-edit-content" name="content" spellcheck="false" wrap="off"
                      style="flex: 1; min-height: 340px; max-height: 460px; padding: 10px; border: none; outline: none; resize: vertical; font-family: monospace; font-size: 13px; line-height: 1.5; color: #24292e; white-space: pre; overflow: auto;"><?= $this->text->e($editorContent) ?></textarea>
        </div>

    <?php endif; ?>

    <fieldset class="fic-save-mode" style="margin: 14px 0 10px; padding: 10px 12px; border: 1px solid #e1e4e8; border-radius: 6px;">
        <legend style="font-size: 0.9em; font-weight: 600; color: #24292e; padding: 0 6px;">
            <i class="fa fa-code-fork"></i> <?= t('Save Mode') ?>
        </legend>
        <label style="display: block; margin-bottom: 6px; font-weight: normal; cursor: pointer;">
            <input type="radio" name="mode" value="overwrite" checked>
            <strong><?= t('Overwrite this file') ?></strong>
            <span style="color: #6a737d;">— <?= t('Replaces the current attachment content.') ?></span>
        </label>
        <label style="display: block; font-weight: normal; cursor: pointer;">
            <input type="radio" name="mode" value="revision">
            <strong><?= t('Save as new revision') ?></strong>
            <span style="color: #6a737d;">— <?= t('Keeps the original and adds a versioned copy.') ?></span>
        </label>
    </fieldset>

    <div class="form-actions" style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
        <button type="submit" id="fic-edit-save" class="btn btn-blue" style="cursor: pointer;">
            <i class="fa fa-floppy-o"></i> <?= t('Save Changes') ?>
        </button>
        <span style="color: #6c757d;"><?= t('or') ?></span>
        <a href="#" class="js-modal-close btn btn-link fic-edit-cancel" role="button" style="color: #6a737d; text-decoration: none; cursor: pointer;">
            <?= t('cancel') ?>
        </a>
    </div>
</form>

<?php
/**
 * NO INLINE <script> HERE — it was dead code, for two independent reasons:
 *
 *   1. Kanboard's CSP is `default-src 'self'` and `script-src` inherits it without
 *      `'unsafe-inline'`, so an inline block is refused outright.
 *   2. Modal content is injected with `element.innerHTML = html`
 *      (assets/js/core/dom.js), and per the HTML spec a <script> inserted that way
 *      never executes.
 *
 * While it was inline the counters never updated, the gutter never tracked, JSON was
 * never re-validated, and the form fell back to a plain POST that navigated the
 * browser to the raw JSON body of the update response. The behaviour now lives in
 * Assets/js/editor.js, registered on `template:layout:js`.
 */
?>

<?php
/**
 * The editor shares the unified bottom action bar, minus the controls that make no
 * sense here: no Edit switcher (already editing) and no Rendered/Raw toggle.
 */
?>
<?= $this->render('FileInteractionCore:file/modal_actions', [
    'typeLabel' => strtoupper($editorExtension) !== '' ? strtoupper($editorExtension) : 'File',
    'metaSummary' => t('Editing'),
    'isEditableFormat' => false,
    'editParams' => [],
    'taskId' => $taskId ?? 0,
    'projectId' => $projectId ?? 0,
    'fileId' => $fileId ?? 0,
    'showEditSwitcher' => false,
    'rawViewAvailable' => false,
    'viewMode' => 'rendered',
    'viewToggleParams' => [
        'plugin' => 'FileInteractionCore',
        'project_id' => (int) ($projectId ?? 0),
        'task_id' => (int) ($taskId ?? 0),
        'file_id' => (int) ($fileId ?? 0),
    ],
    'is_ajax' => $is_ajax ?? true,
]) ?>

