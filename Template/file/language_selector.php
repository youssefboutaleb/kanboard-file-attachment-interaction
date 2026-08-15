<?php
/**
 * Syntax language picker for the Safe Preview modal header.
 *
 * Shared by `preview.php` (escaped plain text) and `markdown_preview.php`
 * (highlighted code), so switching between them is a round trip through the same
 * control.
 *
 * MECHANISM: each option carries the full preview URL for its language, and
 * Assets/js/preview-language-selector.js hands the chosen one to
 * `KB.modal.replace()`. Highlighting therefore stays server-side, where the
 * payload is already entity-escaped — re-tokenizing in the browser would mean a
 * second copy of an XSS-sensitive code path. The script is a registered asset
 * because Kanboard's CSP (`default-src 'self'`, no `script-src 'unsafe-inline'`)
 * silently blocks inline handlers.
 *
 * Expects: $languageOptions, $selectedLanguage, $languageParams.
 */
$options = isset($languageOptions) && is_array($languageOptions) ? $languageOptions : [];
$selected = (string) ($selectedLanguage ?? '');
$baseParams = isset($languageParams) && is_array($languageParams) ? $languageParams : [];

if ($options !== []):
?>
<span class="fic-language-picker" style="margin-left: 12px; font-size: 0.75em; font-weight: normal; white-space: nowrap;">
    <label for="fic-language-select" style="color: #6a737d; margin-right: 4px;">
        <i class="fa fa-language" aria-hidden="true"></i> <?= t('Syntax') ?>
    </label>
    <select
        id="fic-language-select"
        class="fic-language-select"
        data-fic-language-select="1"
        style="font-size: 1em; padding: 2px 4px; border: 1px solid #d1d5da; border-radius: 4px; background: #fff; color: #24292e;"
    >
        <?php foreach ($options as $languageId => $languageLabel): ?>
            <?php
            // `lang` is deliberately an extra parameter beyond the preview route's
            // three, so Route::findUrl() declines to match and href() emits the
            // query-string form carrying `plugin` — which is how Kanboard's Router
            // dispatches to a plugin controller.
            $optionUrl = $this->url->href(
                'FilePreviewController',
                'show',
                array_merge($baseParams, ['lang' => (string) $languageId])
            );
            ?>
            <?php /* Kept on one line so the option's text content carries no stray whitespace. */ ?>
            <option value="<?= $optionUrl ?>"<?= (string) $languageId === $selected ? ' selected="selected"' : '' ?>><?= $this->text->e((string) $languageLabel) ?></option>
        <?php endforeach ?>
    </select>
</span>
<?php endif ?>
