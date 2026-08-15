<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Service\FileEditValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\InspectsPhpSource;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUserHelper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeContainer.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';
require_once __DIR__ . '/../stubs/InspectsPhpSource.php';

/**
 * Tasks 41 & 42: in-preview Edit switcher and the modal fullscreen toggle.
 *
 * The controls live in one shared partial (`Template/file/modal_actions.php`)
 * rendered by all six modal templates, so these tests drive the real controller to
 * produce the view variables and then render the real templates with them.
 */
class ModalActionsTest extends TestCase
{
    use InspectsPhpSource;

    /**
     * Every template that must carry the fullscreen toggle.
     *
     * @return array<string, array{0: string}>
     */
    public static function modalTemplateProvider(): array
    {
        return [
            'plain text preview' => ['file/preview'],
            'markdown / code preview' => ['file/markdown_preview'],
            'csv preview' => ['file/csv_preview'],
            'pdf preview' => ['file/pdf_preview'],
            'excel preview' => ['file/excel_preview'],
            'live editor' => ['file/edit'],
        ];
    }

    /**
     * Variables broad enough to render any of the six templates.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function commonVars(array $overrides = []): array
    {
        return array_merge([
            'projectId' => 7,
            'taskId' => 3,
            'fileId' => 42,
            'filename' => 'notes.txt',
            'extension' => 'txt',
            'handler' => 'TextPreviewHandler',
            'content' => 'body',
            'isFormatted' => false,
            'metadata' => [
                'lineCount' => 1,
                'charCount' => 4,
                'delimiter' => ',',
                'delimiterLabel' => ',',
                'totalRows' => 1,
                'totalColumns' => 1,
                'rows' => [['a']],
                'sheets' => ['Sheet1' => ['rows' => [['a']], 'rowCount' => 1, 'columnCount' => 1, 'truncated' => false]],
                'sheetNames' => ['Sheet1'],
                'sheetCount' => 1,
                'activeSheet' => 'Sheet1',
                'parsed' => true,
                'isLegacyFormat' => false,
                'sizeBytes' => 1024,
            ],
            'isEditableFormat' => true,
            'editParams' => [
                'plugin' => 'FileInteractionCore',
                'project_id' => 7,
                'task_id' => 3,
                'file_id' => 42,
            ],
            // csv / language control vars, ignored by the other templates
            'languageOptions' => [],
            'selectedLanguage' => 'text',
            'languageSelectorEnabled' => false,
            'languageParams' => [],
            'delimiterOptions' => [],
            'selectedDelimiter' => 'auto',
            'delimiterMode' => 'auto',
            'hasHeaderRow' => true,
            'csvControlsEnabled' => false,
            'csvParams' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function renderTemplate(string $template, array $overrides = [], bool $canWrite = true): string
    {
        $renderer = new FakeTemplateRenderer(null, new FakeUserHelper($canWrite));

        return $renderer->renderPluginTemplate($template, $this->commonVars($overrides));
    }

    // ------------------------------------------------------------------
    // Fullscreen toggle — present everywhere
    // ------------------------------------------------------------------

    /**
     * @dataProvider modalTemplateProvider
     */
    public function testFullscreenToggleIsPresentInEveryModalTemplate(string $template): void
    {
        $html = $this->renderTemplate($template);

        $this->assertStringContainsString(
            'data-fic-fullscreen-toggle',
            $html,
            $template . ' must offer the fullscreen toggle.'
        );
        $this->assertStringContainsString('Fullscreen', $html);
    }

    /**
     * v0.7.1: the toggle became a link in the unified bottom bar. An anchor cannot
     * submit the editor form either, which is what the previous type="button"
     * assertion protected.
     *
     * @dataProvider modalTemplateProvider
     */
    public function testFullscreenToggleIsALinkInTheActionBar(string $template): void
    {
        $html = $this->renderTemplate($template);

        $this->assertSame(
            1,
            preg_match('/<a\s+href="[^"]*"\s*\n?\s*class="fic-btn-fullscreen"/s', $html),
            $template . ': the toggle must be a fic-btn-fullscreen link.'
        );

        // An anchor cannot submit a form, which is what the previous
        // type="button" assertion protected. (The editor's own Save button is a
        // legitimate submit control, so a blanket check would be wrong here.)
        $this->assertSame(
            0,
            preg_match('/<button[^>]*data-fic-fullscreen-toggle/s', $html),
            $template . ': the toggle must not be a <button> any more.'
        );
    }
    /**
     * @dataProvider modalTemplateProvider
     */
    public function testActionBarIsTheUnifiedPanelMetaContainer(string $template): void
    {
        $html = $this->renderTemplate($template);

        $this->assertSame(
            1,
            substr_count($html, 'class="panel-meta"'),
            $template . ' must render exactly one unified .panel-meta action bar.'
        );
        $this->assertStringContainsString('justify-content: space-between', $html);
    }

    /**
     * The bar always offers Fullscreen and Download.
     *
     * @dataProvider modalTemplateProvider
     */
    public function testActionBarAlwaysOffersFullscreenAndDownload(string $template): void
    {
        $html = $this->renderTemplate($template);

        $this->assertStringContainsString('fa-arrows-alt', $html, $template . ': Fullscreen icon');
        $this->assertStringContainsString('fa-download', $html, $template . ': Download icon');
        $this->assertStringContainsString('action=download', $html, $template . ': Download target');
    }

    /**
     * Internal handler class names must never reach the UI.
     *
     * @dataProvider modalTemplateProvider
     */
    public function testNoInternalHandlerNamesAreRendered(string $template): void
    {
        $html = $this->renderTemplate($template);

        foreach ([
            'PdfPreviewHandler',
            'CodePreviewHandler',
            'TextPreviewHandler',
            'CsvPreviewHandler',
            'ExcelPreviewHandler',
            'MarkdownPreviewHandler',
            'JsonPreviewHandler',
        ] as $className) {
            $this->assertStringNotContainsString($className, $html, $template . ' leaks ' . $className);
        }
    }

    /**
     * Security boilerplate is gone from every footer.
     *
     * @dataProvider modalTemplateProvider
     */
    public function testSecurityBoilerplateIsRemoved(string $template): void
    {
        $html = $this->renderTemplate($template);

        foreach ([
            'Safe Read-Only Syntax Highlighted View',
            'Safe Sanitized Markdown View',
            'Safe Escaped Plain Text View',
            'Safe Read-Only CSV Table View',
            'Safe Read-Only Spreadsheet View',
        ] as $boilerplate) {
            $this->assertStringNotContainsString($boilerplate, $html, $template . ' still shows: ' . $boilerplate);
        }
    }

    /**
     * The friendly type name replaces the handler badge.
     */
    public function testFriendlyTypeNameIsShown(): void
    {
        $html = $this->renderTemplate('file/preview', ['typeLabel' => 'Text']);

        $this->assertStringContainsString('fa-info-circle', $html);
        $this->assertStringContainsString('Text Modal', $html);
    }

    public function testFullscreenToggleStartsUnpressedAndCarriesBothLabels(): void
    {
        $html = $this->renderTemplate('file/preview');

        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('data-fic-label-enter="Fullscreen"', $html);
        $this->assertStringContainsString('data-fic-label-exit="Exit Fullscreen"', $html);
    }

    /**
     * The toggle must be handled by the registered asset — an inline handler is
     * refused by CSP and never executes from innerHTML-injected content.
     */
    public function testFullscreenToggleHasNoInlineHandler(): void
    {
        // Comments are stripped: the partial documents the CSP rule in prose that
        // necessarily names the tag.
        $partial = $this->executablePhpSource(__DIR__ . '/../../Template/file/modal_actions.php');

        $this->assertStringNotContainsString('<script', $partial);
        $this->assertStringNotContainsString('onclick', $partial);

        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/preview-controls.js');
        $this->assertStringContainsString('data-fic-fullscreen-toggle', $script);
        $this->assertStringContainsString("addEventListener('click'", $script);
        // The bar is anchors now, so the handler must stop the navigation.
        $this->assertStringContainsString('event.preventDefault()', $script);
    }

    /**
     * The class goes on Kanboard's own modal container, and the stylesheet must be
     * registered — core sets the box width as an INLINE style, so the rules need
     * `!important` to win.
     */
    public function testFullscreenStylesAreRegisteredAndOverrideInlineWidth(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../Plugin.php');

        $this->assertStringContainsString('template:layout:css', $plugin);
        $this->assertStringContainsString('plugins/FileInteractionCore/Assets/css/preview.css', $plugin);

        $css = (string) file_get_contents(__DIR__ . '/../../Assets/css/preview.css');

        $this->assertStringContainsString('#modal-box.fic-modal-fullscreen', $css);
        $this->assertMatchesRegularExpression('/width:\s*100%\s*!important/', $css);
        $this->assertMatchesRegularExpression('/height:\s*100%\s*!important/', $css);
        // Sticky headers are part of the requirement.
        $this->assertStringContainsString('position: sticky', $css);
    }

    public function testFullscreenScriptTogglesTheClassOnTheModalBox(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/preview-controls.js');

        $this->assertStringContainsString("getElementById('modal-box')", $script);
        $this->assertStringContainsString('fic-modal-fullscreen', $script);
        $this->assertStringContainsString('classList.toggle', $script);
        // Keeps assistive technology in sync with the visual state.
        $this->assertStringContainsString("setAttribute('aria-pressed'", $script);
    }

    // ------------------------------------------------------------------
    // Edit switcher — format gate
    // ------------------------------------------------------------------

    /**
     * @dataProvider editableExtensionProvider
     */
    public function testEditSwitcherIsOfferedForEditableFormats(string $extension): void
    {
        $vars = $this->previewVarsFor('notes.' . $extension, 'plain content');

        $this->assertTrue($vars['isEditableFormat'], '.' . $extension . ' must be editable.');

        $html = $this->renderTemplate(
            $vars['handler'] === 'CodePreviewHandler' ? 'file/markdown_preview' : 'file/preview',
            $vars
        );

        $this->assertStringContainsString('data-fic-edit-switcher', $html);
        // v0.7.1: the label shortened to "Edit" in the unified bar.
        $this->assertStringContainsString('> Edit', $html);
    }

    /**
     * Mirrors FileEditValidationService::EDITABLE_EXTENSIONS, which is the list the
     * requirement names.
     *
     * @return array<string, array{0: string}>
     */
    public static function editableExtensionProvider(): array
    {
        $cases = [];

        foreach (FileEditValidationService::EDITABLE_EXTENSIONS as $extension) {
            $cases['.' . $extension] = [$extension];
        }

        return $cases;
    }

    /**
     * The requirement lists exactly these formats; assert the gate matches rather
     * than maintaining a fourth copy of the list.
     */
    public function testEditableFormatListMatchesTheRequirement(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'txt', 'json', 'md', 'markdown',
                'env', 'ini', 'conf', 'log',
                'yml', 'yaml', 'xml',
                'sh', 'bash', 'py', 'python', 'js', 'css', 'sql',
                'html', 'htm',
                'csv', 'tsv', 'xlsx', 'xls',
            ],
            FileEditValidationService::EDITABLE_EXTENSIONS
        );
    }

    /**
     * Binary, tabular and active-content formats must never offer the editor.
     *
     * @dataProvider nonEditableExtensionProvider
     */
    public function testEditSwitcherIsWithheldForNonEditableFormats(string $filename, string $content, string $template): void
    {
        $vars = $this->previewVarsFor($filename, $content);

        $this->assertFalse($vars['isEditableFormat'], $filename . ' must not be editable.');

        $html = $this->renderTemplate($template, $vars);

        $this->assertStringNotContainsString('data-fic-edit-switcher', $html);
        // The fullscreen toggle is still there.
        $this->assertStringContainsString('data-fic-fullscreen-toggle', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function nonEditableExtensionProvider(): array
    {
        return [
            'pdf is binary' => ['doc.pdf', '%PDF-1.4', 'file/pdf_preview'],
            'docx is binary' => ['report.docx', 'PK', 'file/docx_preview'],
            'pptx is binary' => ['slides.pptx', 'PK', 'file/pptx_preview'],
        ];
    }

    /**
     * Resolve the real controller output for a file, so the format gate under test
     * is the one production uses.
     *
     * @return array<string, mixed>
     */
    private function previewVarsFor(string $filename, string $content): array
    {
        $template = new FakeTemplate();

        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 42, 'task_id' => 3, 'project_id' => 7], true),
            'response' => new FakeResponse(),
            'template' => $template,
            'taskFileModel' => new FakeFileModel(['name' => $filename, 'path' => 'tasks/3/f', 'task_id' => 3]),
            'objectStorage' => new FakeObjectStorage($content),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        return $template->renderedVars;
    }

    // ------------------------------------------------------------------
    // Edit switcher — permission and target gates
    // ------------------------------------------------------------------

    /**
     * Without write access the switcher must not appear, even for an editable
     * format. `TaskFileController::remove` is the core ACL entry for mutating
     * attachments, matching the gate the dropdown already uses.
     */
    public function testEditSwitcherIsWithheldWithoutWritePermission(): void
    {
        $html = $this->renderTemplate('file/preview', [], false);

        $this->assertStringNotContainsString('data-fic-edit-switcher', $html);
        $this->assertStringContainsString('data-fic-fullscreen-toggle', $html);
    }

    /**
     * A project-overview attachment has no task id, and FileEditController resolves
     * files through taskFileModel only — so there is no editable target.
     */
    public function testEditSwitcherIsWithheldForProjectAttachments(): void
    {
        $html = $this->renderTemplate('file/preview', ['taskId' => 0]);

        $this->assertStringNotContainsString('data-fic-edit-switcher', $html);
    }

    public function testEditSwitcherIsWithheldWithoutAProject(): void
    {
        $html = $this->renderTemplate('file/preview', ['projectId' => 0]);

        $this->assertStringNotContainsString('data-fic-edit-switcher', $html);
    }

    /**
     * The editor itself must not offer a switch to the editor.
     */
    public function testEditorTemplateDoesNotOfferTheEditSwitcher(): void
    {
        $html = $this->renderTemplate('file/edit');

        $this->assertStringNotContainsString('data-fic-edit-switcher', $html);
        $this->assertStringContainsString('data-fic-fullscreen-toggle', $html);
    }

    // ------------------------------------------------------------------
    // Edit switcher — how the switch happens
    // ------------------------------------------------------------------

    /**
     * The switch reuses core's own in-modal navigation class, whose delegated
     * handler calls KB.modal.replace() when a modal is already open. No custom
     * JavaScript is involved.
     */
    public function testEditSwitcherUsesCoreInModalNavigation(): void
    {
        $html = $this->renderTemplate('file/preview');

        $this->assertSame(
            1,
            preg_match('/<a href="([^"]*)"[^>]*class="js-modal-medium fic-edit-switcher"/s', $html, $matches),
            'The switcher must be a js-modal-medium link.'
        );

        $this->assertSame('/b/7/task/3/file/42/edit', $matches[1]);
    }

    /**
     * A degraded URL must still be dispatchable, so the plugin param has to survive
     * when Route::findUrl() cannot match the pretty route.
     */
    public function testEditSwitcherUrlCarriesPluginParamWhenRouteIsUnavailable(): void
    {
        $renderer = new FakeTemplateRenderer(
            new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUrlHelper()
        );

        $html = $renderer->renderPluginTemplate('file/preview', $this->commonVars());

        $this->assertSame(1, preg_match('/class="js-modal-medium fic-edit-switcher"/', $html));
        $this->assertStringContainsString('controller=FileEditController', $html);
        $this->assertStringContainsString('action=edit', $html);
        $this->assertStringContainsString('plugin=FileInteractionCore', $html);
    }

    public function testEditSwitcherPreservesProjectSourceInItsUrl(): void
    {
        $html = $this->renderTemplate('file/preview', [
            'editParams' => [
                'plugin' => 'FileInteractionCore',
                'project_id' => 7,
                'task_id' => 3,
                'file_id' => 42,
                'source' => 'project',
            ],
        ]);

        $this->assertSame(1, preg_match('/fic-edit-switcher/', $html));
        $this->assertStringContainsString('source=project', $html);
    }

    // ------------------------------------------------------------------
    // Controller wiring
    // ------------------------------------------------------------------

    public function testControllerReportsEditableFormatAndParams(): void
    {
        $vars = $this->previewVarsFor('config.json', '{"a":1}');

        $this->assertTrue($vars['isEditableFormat']);
        $this->assertSame(
            ['plugin' => 'FileInteractionCore', 'project_id' => 7, 'task_id' => 3, 'file_id' => 42],
            $vars['editParams']
        );
    }

    /**
     * The unknown-extension path renders the same text view, so it must carry the
     * switcher variables too — and an unrecognised extension is never editable.
     */
    public function testUnclassifiedAttachmentReportsNotEditable(): void
    {
        $vars = $this->previewVarsFor('dump.bak', "plain text\n");

        $this->assertArrayHasKey('isEditableFormat', $vars);
        $this->assertFalse($vars['isEditableFormat']);
    }

    public function testEditSwitcherInStandaloneModeDoesNotUseModalClass(): void
    {
        $html = $this->renderTemplate('file/preview', ['is_ajax' => false]);

        $this->assertStringContainsString('fic-edit-switcher', $html);
        $this->assertStringNotContainsString('js-modal-medium fic-edit-switcher', $html);
        $this->assertStringNotContainsString('data-fic-fullscreen-toggle', $html);
        $this->assertStringNotContainsString('fic-btn-open-tab', $html);
    }
}

