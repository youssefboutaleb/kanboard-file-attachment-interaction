<?php
/**
 * Unified bottom action bar, rendered by every preview modal.
 *
 * Replaces the per-template footers, which each showed different counts plus a
 * different piece of security boilerplate, and the top-right fullscreen button
 * from the earlier header-based design. One container, one action group:
 *
 *   left  — friendly file type name (never a handler class name) + counts
 *   right — View mode toggle · Edit · Fullscreen · Download
 *
 * MECHANISM NOTES
 *
 * - **Edit** uses core's `js-modal-medium` class. Its delegated handler already
 *   calls `KB.modal.replace()` when a modal is open, so the switch into the editor
 *   needs no custom JavaScript at all.
 * - **View mode** is a server-side round trip on the `view` parameter, the same
 *   pattern as the language picker and CSV controls: rendering stays in PHP where
 *   the payload is already escaped.
 * - **Fullscreen** carries a real `href` AND the `fic-btn-fullscreen` class.
 *   preview-controls.js intercepts the click to toggle fullscreen in place; the
 *   href is the fallback that still works without JavaScript, and is what a
 *   middle-click or "open in new tab" follows. `target="_blank"` is deliberately
 *   NOT set on the in-modal toggle — see the PDF template for the one control that
 *   really does open a new tab.
 *
 * WRITE GATE: `TaskFileController::remove` is the core ACL entry meaning "may
 * mutate this project's attachments" — the same check core uses for its Remove item
 * and the one Template/file/dropdown.php applies to its Edit entry. Our plugin
 * controller is not in Kanboard's access map, so it cannot be queried directly.
 *
 * Expects: $typeLabel, $metaSummary, $isEditableFormat, $editParams, $taskId,
 *          $projectId, $fileId, $showEditSwitcher, $rawViewAvailable, $viewMode,
 *          $viewToggleParams.
 */
$actionTaskId = (int) ($taskId ?? 0);
$actionProjectId = (int) ($projectId ?? 0);
$actionFileId = (int) ($fileId ?? 0);
$label = (string) ($typeLabel ?? 'File');
$summary = (string) ($metaSummary ?? '');
$currentView = (string) ($viewMode ?? 'rendered');
$isRawView = $currentView === 'raw';

/**
 * The editor resolves attachments through taskFileModel only, so a
 * project-overview attachment (no task id) has no editable target.
 */
$canSwitchToEditor = !empty($showEditSwitcher)
    && !empty($isEditableFormat)
    && $actionTaskId > 0
    && $actionProjectId > 0
    && $this->user->hasProjectAccess('TaskFileController', 'remove', $actionProjectId);

$downloadParams = ['task_id' => $actionTaskId, 'file_id' => $actionFileId];
if ($actionProjectId > 0) {
    $downloadParams['project_id'] = $actionProjectId;
}
$downloadUrl = $this->url->href('FileViewerController', 'download', $downloadParams);

$toggleBaseParams = isset($viewToggleParams) && is_array($viewToggleParams) ? $viewToggleParams : [];
// The toggle points at the mode it would switch TO, so the server always renders
// the correct next target and the control needs no client-side state.
$toggleUrl = $this->url->href('FilePreviewController', 'show', array_merge($toggleBaseParams, [
    'view' => $isRawView ? 'rendered' : 'raw',
]));
// Fallback target for the fullscreen link when JavaScript is unavailable.
$selfUrl = $this->url->href('FilePreviewController', 'show', array_merge($toggleBaseParams, [
    'view' => $currentView,
]));
$isAjaxRequest = !isset($is_ajax) || $is_ajax !== false;
$openInNewTabUrl = !empty($openTabUrl) ? (string) $openTabUrl : $selfUrl;
?>
<div class="panel-meta" style="font-size: 0.85em; color: #6a737d; display: flex; justify-content: space-between; margin-top: 5px;">
    <span>
        <i class="fa fa-info-circle"></i> <?= $this->text->e($label) ?><?= $isAjaxRequest ? ' ' . t('Modal') : '' ?>
        <?php if ($summary !== ''): ?>
            &nbsp;|&nbsp; <?= $this->text->e($summary) ?>
        <?php endif ?>
    </span>
    <span>
        <?php if (!empty($rawViewAvailable)): ?>
            <?php
            /**
             * Rendered ON / Raw OFF. The icon carries the state so the control reads
             * correctly without relying on colour alone.
             */
            ?>
            <a href="<?= $toggleUrl ?>"
               class="<?= $isAjaxRequest ? 'js-modal-medium ' : '' ?>fic-btn-view-mode"
               data-fic-view-mode="<?= $isRawView ? 'raw' : 'rendered' ?>"
               title="<?= $isRawView ? t('Show the rendered view') : t('Show the raw source') ?>"
               style="color: #0366d6; text-decoration: none; margin-right: 12px;">
                <i class="fa <?= $isRawView ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                <?= $isRawView ? t('Raw') : t('Rendered') ?>
            </a>
        <?php endif ?>

        <?php if ($canSwitchToEditor): ?>
            <a href="<?= $this->url->href('FileEditController', 'edit', $editParams ?? []) ?>"
               class="<?= $isAjaxRequest ? 'js-modal-medium ' : '' ?>fic-edit-switcher"
               data-fic-edit-switcher="1"
               title="<?= t('Switch to the live editor') ?>"
               style="color: #0366d6; text-decoration: none; margin-right: 12px;">
                <i class="fa fa-pencil-square-o"></i> <?= t('Edit File') ?>
            </a>
        <?php endif ?>

                <?php if ($isAjaxRequest): ?>
            <a href="<?= $openInNewTabUrl ?>" target="_blank" rel="noopener noreferrer" class="fic-btn-open-tab" title="<?= t('Open in new tab') ?>" style="color: #0366d6; text-decoration: none; margin-right: 12px;">
                <i class="fa fa-external-link"></i> <?= t('Open in new tab') ?>
            </a>

            <a href="<?= $selfUrl ?>" class="fic-btn-fullscreen" data-fic-fullscreen-toggle="1" data-fic-label-enter="<?= $this->text->e(t('Fullscreen')) ?>" data-fic-label-exit="<?= $this->text->e(t('Exit Fullscreen')) ?>" aria-pressed="false" rel="noopener noreferrer" title="<?= t('Toggle fullscreen') ?>" style="color: #0366d6; text-decoration: none; margin-right: 12px;">
                <i class="fa fa-arrows-alt"></i> <span class="fic-fullscreen-label"><?= t('Fullscreen') ?></span>
            </a>
        <?php endif ?>

        <a href="<?= $downloadUrl ?>" style="color: #0366d6; text-decoration: none;">
            <i class="fa fa-download"></i> <?= t('Download') ?>
        </a>
    </span>
</div>
