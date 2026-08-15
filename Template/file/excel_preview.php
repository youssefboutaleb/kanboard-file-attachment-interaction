<?php
/**
 * Safe multi-sheet Excel spreadsheet preview modal (spec 006).
 *
 * Render-only: every text node is escaped via htmlspecialchars(), cell values
 * are strings, and the whole payload is structured JSON built by ExcelParserService.
 * NO INLINE SCRIPT: sheet tab clicks are handled by Assets/js/preview-controls.js.
 */
$isLegacyFormat = !empty($metadata["isLegacyFormat"]);
$sheetNames = $metadata["sheetNames"] ?? [];
$sheetCount = (int) ($metadata["sheetCount"] ?? count($sheetNames));
$activeIndex = (int) ($metadata["activeSheetIndex"] ?? 0);
if (isset($metadata["activeSheet"])) {
    $foundIndex = array_search((string) $metadata["activeSheet"], array_values($sheetNames), true);
    if ($foundIndex !== false) {
        $activeIndex = (int) $foundIndex;
    }
}
$sheets = $metadata["sheets"] ?? [];

$isParsed = (isset($metadata["isParsed"]) && $metadata["isParsed"] === false) || (isset($metadata["parsed"]) && $metadata["parsed"] === false)
    ? false
    : (!empty($metadata["isParsed"]) || !empty($metadata["parsed"]) || !empty($sheetNames));

$activeSheet = $sheetNames[$activeIndex] ?? (count($sheetNames) > 0 ? reset($sheetNames) : "");
if (!is_string($activeSheet)) {
    $activeSheet = "";
}

$isTruncated = !empty($metadata["truncated"]) || !empty($metadata["isTruncated"]);

$columnLabel = static function (int $index): string {
    $label = "";
    $index++;
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $label = chr(65 + $rem) . $label;
        $index = (int) (($index - $rem) / 26);
    }
    return $label;
};
?>
<div class="page-header">
    <h2>
        <i class="fa fa-file-excel-o"></i>
        <?= $this->text->e($filename) ?>
        <?php if ($activeSheet !== ""): ?>
            <span class="badge" id="fic-active-sheet-badge" style="font-size: 0.8em; margin-left: 10px; background: #1d6f42; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= $activeSheet ?>
            </span>
        <?php endif; ?>
    </h2>
</div>

<?php if ($isLegacyFormat): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t("Legacy .xls workbooks use the binary Excel format and cannot be rendered inline. Download the file to open it.") ?>
    </div>
<?php elseif (!$isParsed): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t("The spreadsheet could not be read. It may be corrupted or password protected.") ?>
    </div>
<?php endif; ?>

<?php if ($isTruncated): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t("Preview truncated for performance: only the first rows and columns of each sheet are displayed.") ?>
    </div>
<?php endif; ?>

<?php if ($isParsed && !$isLegacyFormat): ?>
    <div class="fic-sheet-container">
        <?php if ($sheetCount > 1): ?>
            <div class="fic-sheet-tabs" role="tablist" style="display: flex; flex-wrap: wrap; gap: 2px; border-bottom: 2px solid #1d6f42; margin-bottom: 0;">
                <?php foreach ($sheetNames as $index => $sheetName): ?>
                    <button type="button"
                            class="fic-sheet-tab<?= $index === $activeIndex ? " is-active" : "" ?>"
                            role="tab"
                            data-sheet-index="<?= (int) $index ?>"
                            aria-selected="<?= $index === $activeIndex ? "true" : "false" ?>"
                            style="padding: 6px 14px; border: 1px solid #dee2e6; border-bottom: none; border-radius: 4px 4px 0 0; cursor: pointer; font-size: 13px; font-family: inherit; background: <?= $index === $activeIndex ? "#1d6f42" : "#f1f3f5" ?>; color: <?= $index === $activeIndex ? "#fff" : "#495057" ?>; font-weight: <?= $index === $activeIndex ? "600" : "normal" ?>;">
                        <i class="fa fa-table"></i> <?= $sheetName ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($sheetNames as $index => $sheetName): ?>
            <?php
            $sheet = $sheets[$sheetName] ?? ["rows" => [], "rowCount" => 0, "columnCount" => 0, "truncated" => false];
            $rows = $sheet["rows"] ?? [];

            $columnTotal = 0;
            foreach ($rows as $row) {
                $columnTotal = max($columnTotal, count($row));
            }
            ?>
            <div class="fic-sheet-panel" id="fic-sheet-<?= (int) $index ?>" role="tabpanel"
                 data-sheet-index="<?= (int) $index ?>"
                 style="<?= $index === $activeIndex ? "" : "display: none;" ?>">

                <div class="excel-table-wrapper" style="max-height: 460px; overflow: auto; border: 1px solid #dee2e6; border-radius: <?= $sheetCount > 1 ? "0 0 6px 6px" : "6px" ?>; margin-bottom: 10px; background: #fff;">
                    <?php if ($columnTotal === 0): ?>
                        <div style="padding: 20px; text-align: center; color: #6c757d;">
                            <i class="fa fa-info-circle"></i> <?= t("This sheet is empty.") ?>
                        </div>
                    <?php else: ?>
                        <table class="table-bordered" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                            <thead style="background: #f1f3f5; position: sticky; top: 0; z-index: 1;">
                                <tr>
                                    <th style="padding: 6px 8px; border: 1px solid #dee2e6; width: 42px; background: #e9ecef; color: #495057; text-align: center;"></th>
                                    <?php for ($column = 0; $column < $columnTotal; $column++): ?>
                                        <th style="padding: 6px 8px; border: 1px solid #dee2e6; font-weight: 600; color: #495057; font-family: monospace; text-align: center; background: #e9ecef;">
                                            <?= $columnLabel($column) ?>
                                        </th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $rowIndex => $row): ?>
                                    <tr style="<?= $rowIndex % 2 === 1 ? "background-color: #f8f9fa;" : "" ?>">
                                        <td style="padding: 6px 8px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f1f3f5; font-weight: bold;">
                                            <?= $rowIndex + 1 ?>
                                        </td>
                                        <?php for ($column = 0; $column < $columnTotal; $column++): ?>
                                            <td style="padding: 6px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; max-width: 280px; overflow: hidden; text-overflow: ellipsis; color: #212529;"><?= $row[$column] ?? "" ?></td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="fic-sheet-meta" style="font-size: 0.85em; color: #6a737d; margin-top: 5px;">
                    <i class="fa fa-list-ol"></i> <?= t("%d Rows", (int) ($sheet["rowCount"] ?? 0)) ?>
                    &nbsp;|&nbsp;
                    <i class="fa fa-columns"></i> <?= t("%d Columns", (int) ($sheet["columnCount"] ?? 0)) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= $this->render("FileInteractionCore:file/modal_actions", [
    "typeLabel" => $typeLabel ?? "File",
    "metaSummary" => t("%d Sheets", $sheetCount),
    "isEditableFormat" => $isEditableFormat ?? true,
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
