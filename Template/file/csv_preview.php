<?php
/**
 * Safe CSV table preview template (spec 002).
 *
 * Expects: $content (pre-rendered/pre-escaped HTML or array), $filename, $extension,
 * $taskId, $fileId, $projectId, $metadata (array with totalRows, totalColumns, etc.)
 */
$hasHeader = !isset($hasHeaderRow) || $hasHeaderRow !== false;
$rows = $metadata["rows"] ?? [];
if ($hasHeader) {
    $headerRow = !empty($rows) ? $rows[0] : [];
    $dataRows = count($rows) > 1 ? array_slice($rows, 1) : [];
} else {
    $headerRow = !empty($rows) ? range(1, count($rows[0])) : [];
    $dataRows = $rows;
}
?>
<div class="page-header">
    <h2>
        <i class="fa fa-table"></i>
        <?= $this->text->e($filename) ?>
        <?php if (!empty($csvControlsEnabled)): ?>
            <?= $this->render('FileInteractionCore:file/csv_controls', [
                'delimiterOptions' => $delimiterOptions ?? [],
                'selectedDelimiter' => $selectedDelimiter ?? '',
                'delimiterMode' => $delimiterMode ?? '',
                'hasHeaderRow' => $hasHeaderRow ?? true,
                'csvParams' => $csvParams ?? [],
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

<div class="excel-table-wrapper" style="max-height: 480px; overflow: auto; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 10px; background: #fff;">
    <?php if (empty($rows)): ?>
        <div style="padding: 20px; text-align: center; color: #6c757d;">
            <i class="fa fa-info-circle"></i> <?= t("The CSV file is empty.") ?>
        </div>
    <?php else: ?>
        <table class="table-bordered" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <?php if (!empty($headerRow)): ?>
                <thead style="background: #f1f3f5; position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th style="padding: 6px 8px; border: 1px solid #dee2e6; width: 42px; background: #e9ecef; color: #495057; text-align: center;">#</th>
                        <?php foreach ($headerRow as $cell): ?>
                            <th style="padding: 6px 8px; border: 1px solid #dee2e6; font-weight: 600; color: #495057; font-family: monospace;"><?= $cell ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
            <?php endif; ?>
            <tbody>
                <?php foreach ($dataRows as $rowIndex => $row): ?>
                    <tr style="<?= $rowIndex % 2 === 1 ? "background-color: #f8f9fa;" : "" ?>">
                        <td style="padding: 6px 8px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f1f3f5; font-weight: bold;"><?= $hasHeader ? ($rowIndex + 2) : ($rowIndex + 1) ?></td>
                        <?php foreach ($row as $cell): ?>
                            <td style="padding: 6px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; max-width: 300px; overflow: hidden; text-overflow: ellipsis; color: #212529;"><?= $cell ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?= $this->render("FileInteractionCore:file/modal_actions", [
    "typeLabel" => $typeLabel ?? "CSV Table",
    "metaSummary" => t("%d Rows", (int) ($metadata["totalRows"] ?? 0))
        . " | " . t("%d Columns", (int) ($metadata["totalColumns"] ?? 0)),
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
