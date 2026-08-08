<?php
/**
 * Live attachment editor modal (spec 005).
 *
 * Variables supplied by FileEditController::edit():
 *   $fileId, $taskId, $projectId, $filename, $extension, $content
 *
 * SECURITY: every value below is attacker-controlled and MUST stay escaped.
 * $content in particular is raw file bytes and is emitted only through
 * $this->text->e() inside the textarea — an unescaped "</textarea>" in the
 * payload would otherwise break out of the field and inject markup.
 */
$editorContent = (string) ($content ?? '');
$editorExtension = strtolower((string) ($extension ?? ''));
$lineCount = $editorContent === '' ? 0 : substr_count($editorContent, "\n") + 1;
$charCount = strlen($editorContent);
$isJson = $editorExtension === 'json';

// Initial server-side syntax state; the inline script re-evaluates on input.
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
?>
<div class="page-header">
    <h2>
        <i class="fa fa-pencil-square-o"></i>
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
        <i class="fa fa-align-left"></i> <span id="fic-line-count"><?= $lineCount ?></span> <?= t('Lines') ?>
        &nbsp;|&nbsp;
        <i class="fa fa-font"></i> <span id="fic-char-count"><?= $charCount ?></span> <?= t('Characters') ?>
    </span>
    <span id="fic-syntax-status" data-format="<?= $this->text->e($editorExtension) ?>" style="font-weight: 600; color: <?= $isJson && !$jsonIsValid ? '#d9534f' : '#28a745' ?>;">
        <?php if ($isJson): ?>
            <i class="fa <?= $jsonIsValid ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
            <?= $jsonIsValid ? t('Valid JSON') : t('Invalid JSON Syntax') ?>
        <?php else: ?>
            <i class="fa fa-check-circle"></i> <?= t('Plain Text Mode') ?>
        <?php endif; ?>
    </span>
</div>

<form id="fic-edit-form" method="post" action="<?= $updateUrl ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="file_id" value="<?= (int) ($fileId ?? 0) ?>">
    <input type="hidden" name="task_id" value="<?= (int) ($taskId ?? 0) ?>">
    <input type="hidden" name="project_id" value="<?= (int) ($projectId ?? 0) ?>">

    <div class="fic-editor-wrapper" style="display: flex; border: 1px solid #e1e4e8; border-radius: 0 0 6px 6px; overflow: hidden; background: #fff;">
        <pre id="fic-line-gutter" aria-hidden="true" style="margin: 0; padding: 10px 8px; text-align: right; color: #a0a6ad; background: #f6f8fa; border-right: 1px solid #e1e4e8; font-family: monospace; font-size: 13px; line-height: 1.5; user-select: none; overflow: hidden; min-width: 42px;"><?php
            for ($i = 1; $i <= max($lineCount, 1); $i++) {
                echo $i, "\n";
            }
        ?></pre>
        <textarea id="fic-edit-content" name="content" spellcheck="false" wrap="off"
                  style="flex: 1; min-height: 340px; max-height: 460px; padding: 10px; border: none; outline: none; resize: vertical; font-family: monospace; font-size: 13px; line-height: 1.5; color: #24292e; white-space: pre; overflow: auto;"><?= $this->text->e($editorContent) ?></textarea>
    </div>

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

    <div class="form-actions">
        <button type="submit" id="fic-edit-save" class="btn btn-blue">
            <i class="fa fa-floppy-o"></i> <?= t('Save Changes') ?>
        </button>
        <?= t('or') ?>
        <a href="#" class="close-popover"><?= t('cancel') ?></a>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('fic-edit-form');
    var textarea = document.getElementById('fic-edit-content');
    var gutter = document.getElementById('fic-line-gutter');
    var lineCount = document.getElementById('fic-line-count');
    var charCount = document.getElementById('fic-char-count');
    var status = document.getElementById('fic-syntax-status');
    var alertBox = document.getElementById('fic-edit-alert');
    var saveButton = document.getElementById('fic-edit-save');

    if (!form || !textarea) {
        return;
    }

    function showError(message) {
        alertBox.textContent = message;
        alertBox.style.display = 'block';
    }

    function refresh() {
        var value = textarea.value;
        var lines = value === '' ? 0 : value.split('\n').length;

        lineCount.textContent = lines;
        charCount.textContent = value.length;

        var gutterText = '';
        for (var i = 1; i <= Math.max(lines, 1); i++) {
            gutterText += i + '\n';
        }
        gutter.textContent = gutterText;

<?php if ($isJson): ?>
        var valid = true;
        if (value.trim() !== '') {
            try {
                JSON.parse(value);
            } catch (e) {
                valid = false;
            }
        }
        status.style.color = valid ? '#28a745' : '#d9534f';
        status.textContent = valid ? '✓ ' + <?= json_encode(t('Valid JSON')) ?>
                                   : '✗ ' + <?= json_encode(t('Invalid JSON Syntax')) ?>;
<?php endif; ?>
    }

    textarea.addEventListener('input', refresh);
    textarea.addEventListener('scroll', function () {
        gutter.scrollTop = textarea.scrollTop;
    });

    // The update action answers with JSON, so submit over fetch() and act on the
    // response instead of letting the browser navigate to a raw JSON document.
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        alertBox.style.display = 'none';
        saveButton.disabled = true;

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            return response.json().catch(function () {
                return {success: response.ok};
            }).then(function (payload) {
                if (response.ok && payload && payload.success) {
                    window.location.reload();
                    return;
                }
                saveButton.disabled = false;
                showError((payload && payload.message) ? payload.message : <?= json_encode(t('Unable to save the attachment.')) ?>);
            });
        }).catch(function () {
            saveButton.disabled = false;
            showError(<?= json_encode(t('Unable to save the attachment.')) ?>);
        });
    });

    refresh();
})();
</script>
