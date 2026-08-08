<?php
/**
 * Multi-sheet spreadsheet preview modal (spec 006).
 *
 * SECURITY: $metadata['sheets'][*]['rows'] cells AND the sheet names are
 * emitted RAW and must never be wrapped in $this->text->e(). ExcelPreviewHandler
 * already ran htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE) over every cell and
 * every sheet name, so escaping again would display literal "&lt;script&gt;"
 * markup to the user. Every OTHER variable below is untrusted and stays escaped.
 *
 * Variables supplied by FilePreviewController::show():
 *   $filename, $handler, $extension, $metadata
 */
$sheets = $metadata['sheets'] ?? [];
$sheetNames = $metadata['sheetNames'] ?? [];
$sheetCount = (int) ($metadata['sheetCount'] ?? 0);
$activeSheet = (string) ($metadata['activeSheet'] ?? '');
$isLegacyFormat = !empty($metadata['isLegacyFormat']);
$isParsed = !empty($metadata['parsed']);
$isTruncated = !empty($metadata['truncated']);

$activeIndex = array_search($activeSheet, $sheetNames, true);
$activeIndex = $activeIndex === false ? 0 : (int) $activeIndex;

/**
 * Spreadsheet column label for a zero-based index: 0 => A, 25 => Z, 26 => AA.
 */
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
        <i class="fa fa-file-excel-o" style="color: #1d6f42;"></i>
        <?= $this->text->e($filename) ?>
        <span class="badge" style="font-size: 0.8em; margin-left: 10px; background: #1d6f42; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $this->text->e($handler) ?>
        </span>
        <?php if ($sheetCount > 0): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= t('%d Sheets', $sheetCount) ?>
            </span>
            <span class="badge" id="fic-active-sheet-badge" style="font-size: 0.8em; margin-left: 5px; background: #2b579a; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= $activeSheet ?>
            </span>
        <?php endif; ?>
    </h2>
</div>

<?php if ($isLegacyFormat): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('Legacy .xls workbooks use the binary Excel format and cannot be rendered inline. Download the file to open it.') ?>
    </div>
<?php elseif (!$isParsed): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('The spreadsheet could not be read. It may be corrupted or password protected.') ?>
    </div>
<?php endif; ?>

<?php if ($isTruncated): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('Preview truncated for performance: only the first rows and columns of each sheet are displayed.') ?>
    </div>
<?php endif; ?>

<?php if ($isParsed && !$isLegacyFormat): ?>

    <?php if ($sheetCount > 1): ?>
        <div class="fic-sheet-tabs" role="tablist" style="display: flex; flex-wrap: wrap; gap: 2px; border-bottom: 2px solid #1d6f42; margin-bottom: 0;">
            <?php foreach ($sheetNames as $index => $sheetName): ?>
                <button type="button"
                        class="fic-sheet-tab<?= $index === $activeIndex ? ' is-active' : '' ?>"
                        role="tab"
                        data-sheet-index="<?= (int) $index ?>"
                        aria-selected="<?= $index === $activeIndex ? 'true' : 'false' ?>"
                        style="padding: 6px 14px; border: 1px solid #dee2e6; border-bottom: none; border-radius: 4px 4px 0 0; cursor: pointer; font-size: 13px; font-family: inherit; background: <?= $index === $activeIndex ? '#1d6f42' : '#f1f3f5' ?>; color: <?= $index === $activeIndex ? '#fff' : '#495057' ?>; font-weight: <?= $index === $activeIndex ? '600' : 'normal' ?>;">
                    <i class="fa fa-table"></i> <?= $sheetName ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($sheetNames as $index => $sheetName): ?>
        <?php
        $sheet = $sheets[$sheetName] ?? ['rows' => [], 'rowCount' => 0, 'columnCount' => 0, 'truncated' => false];
        $rows = $sheet['rows'] ?? [];

        // Trust the widest row rather than the reported count so the header
        // strip can never come up short of the rendered data.
        $columnTotal = 0;
        foreach ($rows as $row) {
            $columnTotal = max($columnTotal, count($row));
        }
        ?>
        <div class="fic-sheet-panel" id="fic-sheet-<?= (int) $index ?>" role="tabpanel"
             style="<?= $index === $activeIndex ? '' : 'display: none;' ?>">

            <div class="excel-table-wrapper" style="max-height: 460px; overflow: auto; border: 1px solid #dee2e6; border-radius: <?= $sheetCount > 1 ? '0 0 6px 6px' : '6px' ?>; margin-bottom: 10px; background: #fff;">
                <?php if ($columnTotal === 0): ?>
                    <div style="padding: 20px; text-align: center; color: #6c757d;">
                        <i class="fa fa-info-circle"></i> <?= t('This sheet is empty.') ?>
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
                                <tr style="<?= $rowIndex % 2 === 1 ? 'background-color: #f8f9fa;' : '' ?>">
                                    <td style="padding: 6px 8px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f1f3f5; font-weight: bold;">
                                        <?= $rowIndex + 1 ?>
                                    </td>
                                    <?php for ($column = 0; $column < $columnTotal; $column++): ?>
                                        <td style="padding: 6px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; max-width: 280px; overflow: hidden; text-overflow: ellipsis; color: #212529;"><?= $row[$column] ?? '' ?></td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="panel-meta" style="font-size: 0.85em; color: #6a737d; display: flex; justify-content: space-between; margin-top: 5px;">
                <span>
                    <i class="fa fa-list-ol"></i> <?= t('%d Rows', (int) ($sheet['rowCount'] ?? 0)) ?>
                    &nbsp;|&nbsp;
                    <i class="fa fa-columns"></i> <?= t('%d Columns', (int) ($sheet['columnCount'] ?? 0)) ?>
                </span>
                <span>
                    <i class="fa fa-shield"></i> <?= t('Safe Read-Only Spreadsheet View') ?>
                </span>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($sheetCount > 1): ?>
        <script>
        (function () {
            var tabs = document.querySelectorAll('.fic-sheet-tab');
            var panels = document.querySelectorAll('.fic-sheet-panel');
            var badge = document.getElementById('fic-active-sheet-badge');

            // Panels are addressed by numeric index, never by sheet name, so a
            // workbook name containing quotes or markup cannot break the wiring.
            Array.prototype.forEach.call(tabs, function (tab) {
                tab.addEventListener('click', function () {
                    var target = tab.getAttribute('data-sheet-index');

                    Array.prototype.forEach.call(panels, function (panel, index) {
                        panel.style.display = String(index) === target ? '' : 'none';
                    });

                    Array.prototype.forEach.call(tabs, function (other) {
                        var isActive = other === tab;
                        other.classList.toggle('is-active', isActive);
                        other.setAttribute('aria-selected', isActive ? 'true' : 'false');
                        other.style.background = isActive ? '#1d6f42' : '#f1f3f5';
                        other.style.color = isActive ? '#fff' : '#495057';
                        other.style.fontWeight = isActive ? '600' : 'normal';
                    });

                    if (badge) {
                        badge.textContent = tab.textContent.trim();
                    }
                });
            });
        })();
        </script>
    <?php endif; ?>

<?php endif; ?>
