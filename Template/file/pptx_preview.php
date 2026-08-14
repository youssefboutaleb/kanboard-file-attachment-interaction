<?php
/**
 * PowerPoint presentation preview template (Milestone 9 - High-Fidelity PPTX Engine).
 *
 * Variables supplied by FilePreviewController::show():
 *   $filename, $handler, $extension, $metadata, $is_ajax
 */
$slides = $metadata['slides'] ?? [];
$slideCount = (int) ($metadata['slideCount'] ?? 0);
$presentationTitle = (string) ($metadata['title'] ?? '');
$isLegacyFormat = !empty($metadata['isLegacyFormat']);
$isParsed = !empty($metadata['parsed']);

$streamParams = [
    'plugin' => 'FileInteractionCore',
    'project_id' => $projectId ?? 0,
    'task_id' => $taskId ?? 0,
    'file_id' => $fileId ?? 0,
];
$inlineUrl = $this->url->href('FileStreamController', 'inline', $streamParams);

$downloadParams = [
    'task_id' => $taskId ?? 0,
    'file_id' => $fileId ?? 0,
];
if (!empty($projectId)) {
    $downloadParams['project_id'] = $projectId;
}
$downloadUrl = $this->url->href('FileViewerController', 'download', $downloadParams);
?>
<div class="page-header">
    <h2>
        <i class="fa fa-file-powerpoint-o" style="color: #d24726;"></i>
        <?= $this->text->e($filename) ?>
        <?php if ($isParsed && !$isLegacyFormat && $slideCount > 0): ?>
            <span class="badge" style="font-size: 0.8em; margin-left: 5px; background: #d24726; color: #fff; padding: 2px 8px; border-radius: 4px;">
                <?= t('%d Slides', $slideCount) ?>
            </span>
        <?php endif; ?>
    </h2>
</div>

<?php if ($isLegacyFormat): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 12px 16px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('Legacy .ppt presentations use the binary PowerPoint format and cannot be rendered inline. Download the file to open it.') ?>
        <div style="margin-top: 10px;">
            <a href="<?= $downloadUrl ?>" class="btn btn-blue">
                <i class="fa fa-download"></i> <?= t('Download Presentation') ?>
            </a>
        </div>
    </div>
<?php elseif (!$isParsed || $slideCount === 0): ?>
    <div class="alert alert-warning" style="margin-bottom: 15px; padding: 12px 16px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px;">
        <i class="fa fa-exclamation-triangle"></i>
        <?= t('The PowerPoint presentation could not be read. It may be corrupted or password protected.') ?>
    </div>
<?php else: ?>
    <div class="fic-slide-container fic-pptx-container"
         data-fic-stream-url="<?= $inlineUrl ?>"
         data-slide-count="<?= $slideCount ?>"
         style="margin-bottom: 10px;">
        
        <div class="fic-pptx-toolbar" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 16px; background: #1e272e; color: #fff; border-radius: 6px 6px 0 0; border: 1px solid #1e272e; border-bottom: none;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn btn-sm fic-pptx-prev" style="background: #485460; color: #fff; border: none; padding: 4px 12px; border-radius: 3px; cursor: pointer; font-weight: 600;">
                    <i class="fa fa-chevron-left"></i> <?= t('Prev') ?>
                </button>
                <span class="fic-pptx-counter badge" style="background: #d24726; color: #fff; padding: 4px 10px; font-size: 12px; font-weight: 600;">
                    <?= t('Slide 1 of %d', max(1, $slideCount)) ?>
                </span>
                <button type="button" class="btn btn-sm fic-pptx-next" style="background: #485460; color: #fff; border: none; padding: 4px 12px; border-radius: 3px; cursor: pointer; font-weight: 600;">
                    <?= t('Next') ?> <i class="fa fa-chevron-right"></i>
                </button>
            </div>

            <?php if ($slideCount > 1): ?>
                <div class="fic-slide-tabs" style="display: flex; gap: 4px; overflow-x: auto; max-width: 50%; padding: 2px 0;">
                    <?php for ($idx = 0; $idx < $slideCount; $idx++): ?>
                        <button type="button"
                                class="fic-slide-tab<?= $idx === 0 ? ' is-active' : '' ?>"
                                data-slide-index="<?= $idx ?>"
                                style="padding: 3px 8px; border: 1px solid #485460; border-radius: 3px; cursor: pointer; font-size: 11px; font-family: inherit; background: <?= $idx === 0 ? '#d24726' : '#2f3542' ?>; color: #fff;">
                            <?= t('Slide %d', $idx + 1) ?>
                        </button>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="fic-office-loading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 420px; background: #2f3542; color: #fff; border: 1px solid #1e272e; border-radius: 0 0 6px 6px;">
            <i class="fa fa-spinner fa-spin fa-3x" style="margin-bottom: 14px; color: #d24726;"></i>
            <span><?= t('Loading PowerPoint presentation...') ?></span>
        </div>

        <div class="fic-pptx-render-target" style="display: none; min-height: 440px; background: #2f3542; border: 1px solid #1e272e; border-radius: 0 0 6px 6px; padding: 20px; box-sizing: border-box; align-items: center; justify-content: center; overflow: auto;">
            <!-- Vector slide rendered client-side with exact shapes, backgrounds, typography, and logos -->
        </div>

        <div class="fic-pptx-deck-viewport" style="display: none; background: #2f3542; border: 1px solid #1e272e; border-radius: 0 0 6px 6px; padding: 20px; box-sizing: border-box; min-height: 420px; max-height: 560px; overflow-y: auto;">
            <?php foreach ($slides as $idx => $slide): ?>
                <div class="fic-slide-panel" id="fic-slide-<?= (int) $idx ?>" role="tabpanel"
                     data-slide-index="<?= (int) $idx ?>"
                     style="<?= $idx === 0 ? '' : 'display: none;' ?>">

                    <div class="pptx-slide-canvas" style="background: #fff; min-height: 380px; max-height: 500px; overflow-y: auto; border: 1px solid #d0d7de; border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.25); padding: 36px 44px; box-sizing: border-box;">
                        <?php if (!empty($slide['title'])): ?>
                            <h3 class="pptx-slide-title" style="margin-top: 0; margin-bottom: 20px; color: #d24726; font-size: 1.5em; font-weight: 700; border-bottom: 2px solid #f1f3f5; padding-bottom: 10px;">
                                <?= $slide['title'] ?>
                            </h3>
                        <?php endif; ?>

                        <?php if (!empty($slide['bulletPoints'])): ?>
                            <ul class="pptx-bullet-list" style="margin: 14px 0 18px 24px; padding: 0; color: #24292f; font-size: 15px;">
                                <?php foreach ($slide['bulletPoints'] as $bp): ?>
                                    <li style="margin-bottom: 10px; line-height: 1.5;"><?= $bp ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if (!empty($slide['paragraphs'])): ?>
                            <?php foreach ($slide['paragraphs'] as $p): ?>
                                <p class="pptx-paragraph" style="margin: 0 0 12px; line-height: 1.6; color: #24292f; font-size: 14px;">
                                    <?= $p ?>
                                </p>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($slide['tables'])): ?>
                            <?php foreach ($slide['tables'] as $tbl): ?>
                                <div class="pptx-table-wrapper" style="max-width: 100%; overflow-x: auto; margin: 16px 0;">
                                    <table class="table-bordered pptx-table" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; border: 1px solid #d0d7de;">
                                        <?php foreach ($tbl as $rIdx => $row): ?>
                                            <tr style="<?= $rIdx === 0 ? 'background: #f6f8fa; font-weight: 600;' : ($rIdx % 2 === 1 ? 'background: #fafbfc;' : 'background: #fff;') ?>">
                                                <?php foreach ($row as $cell): ?>
                                                    <?php $tag = $rIdx === 0 ? 'th' : 'td'; ?>
                                                    <<?= $tag ?> style="padding: 8px 12px; border: 1px solid #d0d7de; vertical-align: top;">
                                                        <?= $cell !== '' ? $cell : '&nbsp;' ?>
                                                    </<?= $tag ?>>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (empty($slide['title']) && empty($slide['bulletPoints']) && empty($slide['paragraphs']) && empty($slide['tables'])): ?>
                            <div style="padding: 40px; text-align: center; color: #6c757d;">
                                <i class="fa fa-info-circle fa-2x" style="margin-bottom: 10px;"></i>
                                <p><?= t('This slide has no text content.') ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?= $this->render('FileInteractionCore:file/modal_actions', [
    'typeLabel' => $typeLabel ?? 'PowerPoint Presentation',
    'metaSummary' => $isParsed ? t('%d Slides', $slideCount) : '',
    'isEditableFormat' => false,
    'editParams' => [],
    'taskId' => $taskId ?? 0,
    'projectId' => $projectId ?? 0,
    'fileId' => $fileId ?? 0,
    'showEditSwitcher' => false,
    'rawViewAvailable' => false,
    'viewMode' => 'rendered',
    'viewToggleParams' => $viewToggleParams ?? [],
    'openTabUrl' => $openTabUrl ?? null,
    'is_ajax' => $is_ajax ?? true,
]) ?>
