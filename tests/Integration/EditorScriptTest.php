<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\InspectsPhpSource;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';
require_once __DIR__ . '/../stubs/InspectsPhpSource.php';

/**
 * The live editor's client-side layer, extracted from a dead inline <script>.
 *
 * FOUND WHILE IMPLEMENTING TASKS 41/42, outside their requested scope: the editor
 * shipped its entire behaviour as an inline <script> inside
 * Template/file/edit.php, which could never execute — the same double failure that
 * broke the Excel sheet tabs (Task 39):
 *
 *   1. Kanboard's CSP is `default-src 'self'` and `script-src` inherits it without
 *      `'unsafe-inline'`.
 *   2. Modal content is injected with `element.innerHTML = html`
 *      (assets/js/core/dom.js:82), and an injected <script> never runs.
 *
 * So in v0.5.0-v0.7.0 the counters never updated, the gutter never tracked, JSON
 * was never re-validated, and the form fell back to a plain POST that navigated the
 * browser to the raw JSON body of the update response. The logic now lives in
 * Assets/js/editor.js.
 */
class EditorScriptTest extends TestCase
{
    use InspectsPhpSource;

    /**
     * @param array<string, mixed> $overrides
     */
    private function renderEditor(array $overrides = []): string
    {
        $renderer = new FakeTemplateRenderer();

        return $renderer->renderPluginTemplate('file/edit', array_merge([
            'fileId' => 42,
            'taskId' => 3,
            'projectId' => 7,
            'filename' => 'notes.txt',
            'extension' => 'txt',
            'content' => "line one\nline two\n",
        ], $overrides));
    }

    private function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Assets/js/editor.js');
    }

    // ------------------------------------------------------------------
    // The regression
    // ------------------------------------------------------------------

    public function testEditorTemplateShipsNoInlineScript(): void
    {
        // Comments stripped — the template documents the defect in prose.
        $executable = $this->executablePhpSource(__DIR__ . '/../../Template/file/edit.php');

        $this->assertStringNotContainsString('<script', $executable, 'CSP refuses inline scripts.');
        $this->assertStringNotContainsString('addEventListener', $executable);
        $this->assertStringNotContainsString('JSON.parse', $executable);
        $this->assertStringNotContainsString('fetch(', $executable);
    }

    public function testRenderedEditorContainsNoInlineScript(): void
    {
        $html = $this->renderEditor();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onsubmit', $html);
        $this->assertStringNotContainsString('oninput', $html);
    }

    public function testEditorScriptIsRegisteredAsAnAsset(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../Plugin.php');

        $this->assertStringContainsString('plugins/FileInteractionCore/Assets/js/editor.js', $plugin);
        $this->assertFileExists(__DIR__ . '/../../Assets/js/editor.js');
    }

    /**
     * Listeners must be delegated — the form does not exist when the asset runs.
     */
    public function testListenersAreDelegatedFromTheDocument(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("document.addEventListener('input'", $script);
        $this->assertStringContainsString("document.addEventListener('submit'", $script);
        // `scroll` does not bubble, so it needs the capture phase.
        $this->assertStringContainsString("document.addEventListener('scroll', onScroll, true)", $script);
    }

    /**
     * The save must go over fetch(): the update action answers with JSON, so a
     * normal submit would navigate the browser to a raw JSON document.
     */
    public function testSaveIsSubmittedOverFetchAndPreventsDefault(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('event.preventDefault()', $script);
        $this->assertStringContainsString('fetch(form.action', $script);
        $this->assertStringContainsString("method: 'POST'", $script);
        $this->assertStringContainsString('new FormData(form)', $script);
        // CSRF cookie must ride along.
        $this->assertStringContainsString("credentials: 'same-origin'", $script);
    }

    /**
     * Core's own modal submit handler would POST this form and replace the modal
     * with the raw JSON response, so the form opts out of it.
     */
    public function testFormOptsOutOfCoreModalSubmitHandling(): void
    {
        $html = $this->renderEditor();

        $this->assertStringContainsString('js-modal-ignore-form', $html);
    }

    // ------------------------------------------------------------------
    // Data the static asset needs from the template
    // ------------------------------------------------------------------

    /**
     * The asset cannot call t(), so translated strings travel as attributes.
     */
    public function testTranslatedStringsTravelAsDataAttributes(): void
    {
        $html = $this->renderEditor(['filename' => 'config.json', 'extension' => 'json', 'content' => '{"a":1}']);

        $this->assertStringContainsString('data-label-valid="Valid JSON"', $html);
        $this->assertStringContainsString('data-label-invalid="Invalid JSON Syntax"', $html);
        $this->assertStringContainsString('data-label-error="Unable to save the attachment."', $html);

        $script = $this->script();
        $this->assertStringContainsString("getAttribute('data-label-valid')", $script);
        $this->assertStringContainsString("getAttribute('data-label-invalid')", $script);
        $this->assertStringContainsString("getAttribute('data-label-error')", $script);
    }

    /**
     * JSON vocabulary is only emitted where the validator applies.
     */
    public function testJsonLabelsAreOmittedForNonJsonFormats(): void
    {
        $html = $this->renderEditor(['filename' => 'notes.md', 'extension' => 'md']);

        $this->assertStringNotContainsString('data-label-valid', $html);
        $this->assertStringNotContainsString('data-label-invalid', $html);
        $this->assertStringContainsString('Plain Text Mode', $html);
    }

    /**
     * The format flag on the status element is what gates validation, so it must be
     * emitted for the asset to read.
     */
    public function testStatusElementCarriesTheFormatFlag(): void
    {
        $html = $this->renderEditor(['filename' => 'q.sql', 'extension' => 'sql']);

        $this->assertStringContainsString('data-format="sql"', $html);
        $this->assertStringContainsString("getAttribute('data-format') !== 'json'", $this->script());
    }

    /**
     * Every element the asset addresses by id must exist in the rendered editor,
     * or the behaviour silently no-ops again.
     */
    public function testEveryElementTheScriptAddressesIsRendered(): void
    {
        $html = $this->renderEditor();

        foreach ([
            'fic-edit-form',
            'fic-edit-content',
            'fic-line-gutter',
            'fic-line-count',
            'fic-char-count',
            'fic-syntax-status',
            'fic-edit-alert',
            'fic-edit-save',
        ] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, $id . ' is referenced by editor.js.');
        }
    }

    // ------------------------------------------------------------------
    // Safety
    // ------------------------------------------------------------------

    /**
     * Server messages and file content must never be assigned as markup.
     */
    public function testScriptNeverAssignsInnerHtml(): void
    {
        // Comments stripped: the file explains the innerHTML defect in prose.
        $executable = $this->executableJsSource(__DIR__ . '/../../Assets/js/editor.js');

        $this->assertStringNotContainsString('innerHTML', $executable);

        $script = $this->script();
        $this->assertStringContainsString('alertBox.textContent', $script);
        $this->assertStringContainsString('gutter.textContent', $script);
    }

    /**
     * The textarea content stays escaped — an unescaped `</textarea>` in the payload
     * would break out of the field.
     */
    public function testEditorContentRemainsEscaped(): void
    {
        $html = $this->renderEditor(['content' => '</textarea><script>alert(1)</script>']);

        $this->assertStringNotContainsString('</textarea><script>', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;', $html);
    }

    public function testCsrfTokenIsStillEmitted(): void
    {
        $html = $this->renderEditor();

        $this->assertStringContainsString('name="csrf_token"', $html);
    }

    public function testSpreadsheetEditorRendersGridToolbarAndTable(): void
    {
        $html = $this->renderEditor([
            'filename' => 'budget.xlsx',
            'extension' => 'xlsx',
            'isSpreadsheet' => true,
            'sheets' => [
                'Q1' => ['rows' => [['Income', '1000'], ['Expense', '400']]],
            ],
            'sheetNames' => ['Q1'],
            'activeSheet' => 'Q1',
        ]);

        $this->assertStringContainsString('fic-spreadsheet-editor', $html);
        $this->assertStringContainsString('fic-grid-toolbar', $html);
        $this->assertStringContainsString('fic-active-cell-ref', $html);
        $this->assertStringContainsString('fic-spreadsheet-table', $html);
        $this->assertStringContainsString('fic-grid-cell', $html);
        $this->assertStringContainsString('fic-edit-cancel', $html);
        $this->assertStringContainsString('js-modal-close', $html);
    }

    public function testCsvEditorDoesNotRenderAddSheetButton(): void
    {
        $html = $this->renderEditor([
            'filename' => 'data.csv',
            'extension' => 'csv',
            'isSpreadsheet' => true,
            'sheets' => [
                'Sheet1' => ['rows' => [['Col1', 'Col2'], ['Val1', 'Val2']]],
            ],
            'sheetNames' => ['Sheet1'],
            'activeSheet' => 'Sheet1',
        ]);

        $this->assertStringContainsString('fic-spreadsheet-editor', $html);
        $this->assertStringNotContainsString('fic-btn-add-sheet', $html, 'CSV files must not offer multi-sheet additions.');
        $this->assertStringNotContainsString('fic-edit-sheet-tabs', $html, 'CSV files must not render sheet tabs.');
    }

    public function testMultiSheetXlsxRendersRenameAndDeleteControls(): void
    {
        $html = $this->renderEditor([
            'filename' => 'multi.xlsx',
            'extension' => 'xlsx',
            'isSpreadsheet' => true,
            'sheets' => [
                'Sheet1' => ['rows' => [['A', 'B']]],
                'Sheet2' => ['rows' => [['C', 'D']]],
            ],
            'sheetNames' => ['Sheet1', 'Sheet2'],
            'activeSheet' => 'Sheet1',
        ]);

        $this->assertStringContainsString('fic-btn-add-sheet', $html);
        $this->assertStringContainsString('fic-edit-sheet-tabs', $html);
        $this->assertStringContainsString('fic-btn-rename-sheet', $html);
        $this->assertStringContainsString('fic-btn-delete-sheet', $html);
    }

    public function testEditorScriptContainsSheetManagementFunctions(): void
    {
        $script = $this->script();
        $this->assertStringContainsString('function addSheet()', $script);
        $this->assertStringContainsString('function renameSheet(', $script);
        $this->assertStringContainsString('function deleteSheet(', $script);
        $this->assertStringContainsString('function switchEditSheet(', $script);
    }
}

