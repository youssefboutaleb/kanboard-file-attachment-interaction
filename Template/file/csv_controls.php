<?php
/**
 * Delimiter picker and header toggle for the CSV preview modal.
 *
 * MECHANISM (same as the Task 36 language picker): every control carries the
 * fully-built preview URL for the state it would select, and
 * Assets/js/preview-controls.js hands it to `KB.modal.replace()` — so the table
 * re-renders in place without closing the modal, and parsing stays server-side
 * where every cell is entity-escaped.
 *
 * Delimiters travel as TOKENS, never as literal characters: a raw tab or pipe
 * survives neither URL encoding nor attribute escaping reliably, and a raw value
 * would flow straight into str_getcsv().
 *
 * The header checkbox carries the URL for its TOGGLED state, so the server always
 * renders the correct "next" target and the control needs no client-side state.
 *
 * Expects: $delimiterOptions, $selectedDelimiter, $delimiterMode, $hasHeaderRow,
 *          $csvParams.
 */
$options = isset($delimiterOptions) && is_array($delimiterOptions) ? $delimiterOptions : [];
$selected = (string) ($selectedDelimiter ?? 'auto');
$mode = (string) ($delimiterMode ?? 'auto');
$headerOn = !empty($hasHeaderRow);
$baseParams = isset($csvParams) && is_array($csvParams) ? $csvParams : [];

// Preserve the header state across a delimiter change, and vice versa.
$headerValue = $headerOn ? '1' : '0';
$toggledHeaderUrl = $this->url->href('FilePreviewController', 'show', array_merge($baseParams, [
    'delimiter' => $mode,
    'header' => $headerOn ? '0' : '1',
]));

if ($options !== []):
?>
<div class="fic-csv-controls" style="display: flex; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 10px; padding: 8px 12px; background: #f1f3f5; border: 1px solid #dee2e6; border-radius: 6px; font-size: 0.85em;">
    <span style="display: inline-flex; align-items: center; gap: 6px;">
        <label for="fic-csv-delimiter" style="color: #495057; font-weight: 600; margin: 0;">
            <i class="fa fa-columns" aria-hidden="true"></i> <?= t('Delimiter') ?>
        </label>
        <select
            id="fic-csv-delimiter"
            class="fic-csv-delimiter"
            data-fic-csv-control="delimiter"
            style="font-size: 1em; padding: 2px 4px; border: 1px solid #d1d5da; border-radius: 4px; background: #fff; color: #24292e;"
        >
            <?php foreach ($options as $token => $label): ?>
                <?php
                $optionUrl = $this->url->href('FilePreviewController', 'show', array_merge($baseParams, [
                    'delimiter' => (string) $token,
                    'header' => $headerValue,
                ]));
                $isSelected = $token === $mode;
                ?>
                <option value="<?= $optionUrl ?>"<?= $isSelected ? ' selected="selected"' : '' ?>><?= $this->text->e((string) $label) ?></option>
            <?php endforeach ?>
        </select>
    </span>

    <span style="display: inline-flex; align-items: center; gap: 6px;">
        <input
            type="checkbox"
            id="fic-csv-header"
            class="fic-csv-header"
            data-fic-csv-control="header"
            data-fic-url="<?= $toggledHeaderUrl ?>"
            <?= $headerOn ? 'checked="checked"' : '' ?>
            style="margin: 0;"
        >
        <label for="fic-csv-header" style="color: #495057; font-weight: 600; margin: 0; cursor: pointer;">
            <?= t('First row is header') ?>
        </label>
    </span>

    <?php if ($mode === 'auto'): ?>
        <span style="color: #6a737d;">
            <i class="fa fa-magic" aria-hidden="true"></i>
            <?= t('Auto-detected: %s', $this->text->e(strtoupper($selected))) ?>
        </span>
    <?php else: ?>
        <span style="color: #6a737d;">
            <?= t('Delimiter: "%s"', $this->text->e(strtoupper($mode === 'tab' ? 'TAB' : ($options[$mode] ?? $mode)))) ?>
        </span>
    <?php endif ?>
</div>
<?php endif ?>
