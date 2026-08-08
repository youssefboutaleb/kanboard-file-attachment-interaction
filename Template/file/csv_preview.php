<div class="page-header">
    <h2>
        <i class="fa fa-table"></i>
        <?= $this->text->e($filename) ?>
        <span class="badge" style="font-size: 0.8em; margin-left: 10px; background: #2b579a; color: #fff; padding: 2px 8px; border-radius: 4px;">
            <?= $this->text->e($handler) ?>
        </span>
        <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #6c757d; color: #fff; padding: 2px 8px; border-radius: 4px;">
            Delimiter: "<?= $this->text->e($metadata['delimiter'] === "\t" ? 'TAB' : $metadata['delimiter']) ?>"
        </span>
    </h2>
</div>

<?php if (!empty($metadata['truncatedRows']) || !empty($metadata['truncatedColumns'])): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 8px 12px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('Preview truncated: displaying first %d rows and %d columns for performance.', $metadata['totalRows'], $metadata['totalColumns']) ?>
    </div>
<?php endif; ?>

<?php
$rows = $metadata['rows'] ?? [];
$headerRow = !empty($rows) ? array_shift($rows) : [];
?>

<div class="csv-table-wrapper" style="max-height: 480px; overflow: auto; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 10px;">
    <?php if (empty($headerRow) && empty($rows)): ?>
        <div style="padding: 20px; text-align: center; color: #6c757d;">
            <i class="fa fa-info-circle"></i> <?= t('The CSV file is empty.') ?>
        </div>
    <?php else: ?>
        <table class="table-striped table-bordered" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <?php if (!empty($headerRow)): ?>
                <thead style="background: #f1f3f5; position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th style="padding: 8px; border: 1px solid #dee2e6; width: 40px; background: #e9ecef; color: #495057; text-align: center;">#</th>
                        <?php foreach ($headerRow as $headerCell): ?>
                            <th style="padding: 8px; border: 1px solid #dee2e6; font-weight: 600; color: #212529; font-family: monospace; white-space: nowrap;"><?= $headerCell ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
            <?php endif; ?>

            <tbody>
                <?php foreach ($rows as $rowIndex => $row): ?>
                    <tr style="<?= $rowIndex % 2 === 1 ? 'background-color: #f8f9fa;' : '' ?>">
                        <td style="padding: 6px 8px; border: 1px solid #dee2e6; color: #6c757d; font-family: monospace; text-align: center; font-size: 11px; background: #f8f9fa; font-weight: bold;"><?= $rowIndex + 1 ?></td>
                        <?php foreach ($row as $cell): ?>
                            <td style="padding: 6px 8px; border: 1px solid #dee2e6; font-family: monospace; white-space: nowrap; max-width: 300px; overflow: hidden; text-overflow: ellipsis; color: #212529;"><?= $cell ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="panel-meta" style="font-size: 0.85em; color: #6a737d; display: flex; justify-content: space-between; margin-top: 5px;">
    <span>
        <i class="fa fa-list-ol"></i> <?= t('%d Rows', $metadata['totalRows']) ?>
        &nbsp;|&nbsp;
        <i class="fa fa-columns"></i> <?= t('%d Columns', $metadata['totalColumns']) ?>
    </span>
    <span>
        <i class="fa fa-shield"></i> <?= t('Safe Read-Only CSV Table View') ?>
    </span>
</div>
