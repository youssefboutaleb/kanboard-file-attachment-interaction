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
 * Task 38: Excel multi-sheet tab switching.
 *
 * The defect: the switcher shipped as an inline <script> inside
 * excel_preview.php, which could never have run for two independent reasons —
 *
 *   1. Kanboard's CSP is `default-src 'self'` and `script-src` inherits it
 *      without `'unsafe-inline'`, so the block is refused outright.
 *   2. Modal content is injected with `element.innerHTML = html`
 *      (assets/js/core/dom.js:82) and, per the HTML spec, a <script> inserted
 *      that way never executes — so the listeners would not bind even under a
 *      permissive CSP.
 *
 * The logic now lives in the registered `Assets/js/preview-controls.js` asset with
 * delegated listeners. These tests pin the markup contract that asset depends on,
 * and guard against an inline script being reintroduced.
 */
class ExcelSheetSwitchingTest extends TestCase
{
    use InspectsPhpSource;

    /**
     * @param array<string, list<list<string>>> $sheets
     * @param array<string, mixed> $metadataOverrides
     */
    private function render(array $sheets, array $metadataOverrides = []): string
    {
        $names = array_keys($sheets);
        $renderer = new FakeTemplateRenderer();

        $sheetData = [];
        foreach ($sheets as $name => $rows) {
            $columnCount = 0;
            foreach ($rows as $row) {
                $columnCount = max($columnCount, count($row));
            }

            $sheetData[$name] = [
                'rows' => $rows,
                'rowCount' => count($rows),
                'columnCount' => $columnCount,
                'truncated' => false,
            ];
        }

        return $renderer->renderPluginTemplate('file/excel_preview', [
            'projectId' => 7,
            'taskId' => 3,
            'fileId' => 42,
            'filename' => 'budget.xlsx',
            'extension' => 'xlsx',
            'handler' => 'ExcelPreviewHandler',
            'content' => '',
            'isFormatted' => true,
            'metadata' => array_merge([
                'handler' => 'ExcelPreviewHandler',
                'sheets' => $sheetData,
                'sheetNames' => $names,
                'sheetCount' => count($names),
                'activeSheet' => $names[0] ?? '',
                'isLegacyFormat' => false,
                'parsed' => $names !== [],
                'truncated' => false,
            ], $metadataOverrides),
        ]);
    }

    /**
     * @return array<string, list<list<string>>>
     */
    private static function threeSheets(): array
    {
        return [
            'Summary' => [['Region', 'Total'], ['EU', '120']],
            'Detail' => [['Id'], ['1']],
            'Notes' => [['Memo']],
        ];
    }

    private function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Assets/js/preview-controls.js');
    }

    // ------------------------------------------------------------------
    // The regression itself
    // ------------------------------------------------------------------

    /**
     * THE fix. An inline <script> here is dead code twice over, so its return
     * would silently break sheet switching again.
     */
    public function testTemplateShipsNoInlineScript(): void
    {
        // Comments are stripped: the template documents the defect in prose that
        // necessarily names the tag, and a naive substring check would flag its
        // own explanation.
        $executable = $this->executablePhpSource(__DIR__ . '/../../Template/file/excel_preview.php');

        $this->assertStringNotContainsString('<script', $executable, 'CSP refuses inline scripts.');
        $this->assertStringNotContainsString('addEventListener', $executable);
        $this->assertStringNotContainsString('onclick', $executable);
    }

    public function testRenderedMarkupContainsNoInlineScript(): void
    {
        $html = $this->render(self::threeSheets());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * The switcher must be reachable from the registered asset that Kanboard
     * actually loads.
     */
    public function testSwitcherLivesInTheRegisteredAsset(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../Plugin.php');

        $this->assertStringContainsString('plugins/FileInteractionCore/Assets/js/preview-controls.js', $plugin);
        $this->assertFileExists(__DIR__ . '/../../Assets/js/preview-controls.js');
        $this->assertStringContainsString('fic-sheet-tab', $this->script());
    }

    /**
     * Listeners must be delegated: the tabs do not exist when the asset runs, and
     * they arrive later via innerHTML injection.
     */
    public function testSwitcherUsesDelegatedClickListener(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("addEventListener('click'", $script);
        $this->assertStringContainsString('closest', $script, 'Delegation needs closest() to find the tab.');
    }

    // ------------------------------------------------------------------
    // Markup contract the asset depends on
    // ------------------------------------------------------------------

    /**
     * Tabs AND panels must both carry data-sheet-index, so the switcher can pair
     * them by value instead of by fragile DOM ordinal.
     */
    public function testTabsAndPanelsArePairedByIndexAttribute(): void
    {
        $html = $this->render(self::threeSheets());

        preg_match_all('/<button[^>]*data-sheet-index="(\d+)"/', $html, $tabMatches);
        preg_match_all('/<div class="fic-sheet-panel"[^>]*data-sheet-index="(\d+)"/', $html, $panelMatches);

        $this->assertSame(['0', '1', '2'], $tabMatches[1]);
        $this->assertSame(['0', '1', '2'], $panelMatches[1], 'Every tab needs a panel with a matching index.');
    }

    /**
     * The switcher resolves panels from the tab strip's parent, so panels must be
     * siblings of the strip rather than nested inside it.
     */
    public function testPanelsAreSiblingsOfTheTabStrip(): void
    {
        $html = $this->render(self::threeSheets());

        $stripStart = strpos($html, 'class="fic-sheet-tabs"');
        $stripEnd = strpos($html, 'class="fic-sheet-panel"');

        $this->assertIsInt($stripStart);
        $this->assertIsInt($stripEnd);
        $this->assertLessThan($stripEnd, $stripStart);

        // No panel may be nested within the tab strip element.
        $strip = substr($html, $stripStart, $stripEnd - $stripStart);
        $this->assertStringNotContainsString('fic-sheet-panel', $strip);
    }

    /**
     * Exactly one panel visible, the rest hidden — every sheet is already in the
     * DOM, which is why switching needs no server round trip.
     */
    public function testOnlyTheActiveSheetPanelIsVisible(): void
    {
        $html = $this->render(self::threeSheets());

        preg_match_all('/<div class="fic-sheet-panel"[^>]*style="([^"]*)"/', $html, $matches);

        $this->assertCount(3, $matches[1]);
        $this->assertSame('', $matches[1][0], 'The active panel must not be hidden.');
        $this->assertStringContainsString('display: none', $matches[1][1]);
        $this->assertStringContainsString('display: none', $matches[1][2]);
    }

    public function testActiveTabIsMarkedForAssistiveTechnology(): void
    {
        $html = $this->render(self::threeSheets());

        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(2, substr_count($html, 'aria-selected="false"'));
        $this->assertStringContainsString('role="tablist"', $html);
    }

    /**
     * A non-first active sheet must open on that sheet.
     */
    public function testActivePanelFollowsTheActiveSheetMetadata(): void
    {
        $html = $this->render(self::threeSheets(), ['activeSheet' => 'Detail']);

        preg_match_all('/<div class="fic-sheet-panel"[^>]*data-sheet-index="(\d+)"[^>]*style="([^"]*)"/', $html, $matches);

        $this->assertSame('', $matches[2][1], 'Sheet index 1 (Detail) must be the visible one.');
        $this->assertStringContainsString('display: none', $matches[2][0]);

        // And its tab must be the selected one.
        $this->assertSame(
            1,
            preg_match('/<button[^>]*data-sheet-index="1"[^>]*aria-selected="true"/', $html)
        );
    }

    /**
     * An unknown activeSheet must not hide every panel.
     */
    public function testUnknownActiveSheetFallsBackToTheFirstPanel(): void
    {
        $html = $this->render(self::threeSheets(), ['activeSheet' => 'DoesNotExist']);

        preg_match_all('/<div class="fic-sheet-panel"[^>]*style="([^"]*)"/', $html, $matches);

        $this->assertSame('', $matches[1][0], 'A missing active sheet must fall back to the first panel.');
    }

    // ------------------------------------------------------------------
    // Safety of the switching path
    // ------------------------------------------------------------------

    /**
     * Sheet names reach the DOM pre-escaped by ExcelPreviewHandler. Pairing on the
     * numeric index means a hostile name can never reach a selector.
     */
    public function testHostileSheetNameCannotBreakTheWiring(): void
    {
        $html = $this->render([
            '"><script>alert(1)</script>' => [['a']],
            "O'Brien\" Sheet" => [['b']],
        ]);

        // Indices remain clean integers regardless of the names.
        preg_match_all('/data-sheet-index="([^"]*)"/', $html, $matches);
        foreach ($matches[1] as $index) {
            $this->assertMatchesRegularExpression('/^\d+$/', $index);
        }

        // No selector in the asset is built from a sheet name.
        $this->assertStringNotContainsString('sheetName', $this->script());
    }

    /**
     * The badge is updated with textContent, never innerHTML: the sheet name is
     * already entity-escaped for HTML output, so assigning it as markup would
     * double-unescape it back into live markup.
     */
    public function testBadgeIsUpdatedAsTextNotMarkup(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('badge.textContent', $script);
        $this->assertStringNotContainsString('badge.innerHTML', $script);
    }

    /**
     * Switching must be scoped to the clicked tab's own container, so two previews
     * cannot drive each other.
     */
    public function testSwitchingIsScopedToTheClickedTabStrip(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("closest('.fic-sheet-tabs')", $script);
        // Panels are looked up from that strip's root, not from the document.
        $this->assertStringContainsString("root.querySelectorAll('.fic-sheet-panel')", $script);
        $this->assertStringNotContainsString("document.querySelectorAll('.fic-sheet-panel')", $script);
    }

    // ------------------------------------------------------------------
    // Degenerate workbooks
    // ------------------------------------------------------------------

    public function testSingleSheetWorkbookRendersNoTabStrip(): void
    {
        $html = $this->render(['OnlySheet' => [['a']]]);

        $this->assertStringNotContainsString('fic-sheet-tabs', $html);
        $this->assertStringNotContainsString('role="tab"', $html);
        // The lone panel is visible and still carries its index.
        $this->assertStringContainsString('data-sheet-index="0"', $html);
        $this->assertStringContainsString('fic-active-sheet-badge', $html);
    }

    public function testLegacyXlsRendersNeitherTabsNorPanels(): void
    {
        $html = $this->render([], ['isLegacyFormat' => true, 'parsed' => false]);

        $this->assertStringNotContainsString('fic-sheet-tabs', $html);
        $this->assertStringNotContainsString('fic-sheet-panel', $html);
        $this->assertStringContainsString('Legacy .xls workbooks', $html);
    }

    public function testUnparseableWorkbookRendersTheErrorNotice(): void
    {
        $html = $this->render([], ['parsed' => false]);

        $this->assertStringNotContainsString('fic-sheet-tabs', $html);
        $this->assertStringContainsString('could not be read', $html);
    }

    /**
     * An empty sheet must still get its panel and index, or its tab would switch
     * to nothing.
     */
    public function testEmptySheetStillGetsAPairedPanel(): void
    {
        $html = $this->render([
            'Filled' => [['a']],
            'Empty' => [],
        ]);

        $this->assertSame(2, substr_count($html, 'role="tabpanel"'));
        $this->assertStringContainsString('data-sheet-index="1"', $html);
        $this->assertStringContainsString('This sheet is empty.', $html);
    }
}
