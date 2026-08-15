<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\PreviewViewModeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * v0.7.1: friendly type names and the Rendered/Raw view-mode rules.
 */
class PreviewViewModeRegistryTest extends TestCase
{
    private PreviewViewModeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PreviewViewModeRegistry();
    }

    // ------------------------------------------------------------------
    // Friendly type names (requirement 2)
    // ------------------------------------------------------------------

    /**
     * @dataProvider typeLabelProvider
     */
    public function testResolvesFriendlyTypeName(string $extension, string $handler, string $expected): void
    {
        $this->assertSame($expected, $this->registry->getTypeLabel($extension, $handler));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function typeLabelProvider(): array
    {
        return [
            'pdf' => ['pdf', 'PdfPreviewHandler', 'PDF Document'],
            'csv' => ['csv', 'CsvPreviewHandler', 'CSV Table'],
            'xlsx' => ['xlsx', 'ExcelPreviewHandler', 'Spreadsheet'],
            'md' => ['md', 'MarkdownPreviewHandler', 'Markdown'],
            'json' => ['json', 'JsonPreviewHandler', 'JSON'],
            'txt' => ['txt', 'TextPreviewHandler', 'Text'],
            'sh' => ['sh', 'CodePreviewHandler', 'Shell Script'],
            'py' => ['py', 'CodePreviewHandler', 'Python'],
            'uppercase extension' => ['PDF', 'PdfPreviewHandler', 'PDF Document'],
            'leading dot' => ['.csv', 'CsvPreviewHandler', 'CSV Table'],
            'no extension falls back to the handler' => ['', 'TextPreviewHandler', 'Text'],
            'binary notice' => ['', 'BinaryNotice', 'Binary File'],
        ];
    }

    /**
     * THE requirement-2 guarantee: a class name must never reach the UI, whatever
     * combination arrives.
     */
    public function testNeverReturnsAnInternalClassName(): void
    {
        $handlers = [
            'PdfPreviewHandler', 'CsvPreviewHandler', 'ExcelPreviewHandler',
            'MarkdownPreviewHandler', 'CodePreviewHandler', 'JsonPreviewHandler',
            'TextPreviewHandler', 'BinaryNotice', 'SomeFutureHandler',
        ];

        foreach ($handlers as $handler) {
            foreach (['', 'pdf', 'zzz', 'md'] as $extension) {
                $label = $this->registry->getTypeLabel($extension, $handler);

                $this->assertStringNotContainsString('Handler', $label);
                $this->assertNotSame($handler, $label);
                $this->assertNotSame('', $label);
            }
        }
    }

    public function testUnknownCombinationFallsBackToNeutralLabel(): void
    {
        $this->assertSame('File', $this->registry->getTypeLabel('zzz', 'SomeFutureHandler'));
    }

    // ------------------------------------------------------------------
    // Raw view availability (requirement 3)
    // ------------------------------------------------------------------

    /**
     * The toggle is offered only where a rich rendering exists to switch away from.
     *
     * @dataProvider rawSupportProvider
     */
    public function testRawViewAvailability(string $handler, bool $expected): void
    {
        $this->assertSame($expected, $this->registry->supportsRawView($handler));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function rawSupportProvider(): array
    {
        return [
            'markdown renders rich HTML' => ['MarkdownPreviewHandler', true],
            'code renders highlighted HTML' => ['CodePreviewHandler', true],
            'html renders rich HTML' => ['HtmlPreviewHandler', true],
            'csv renders a table' => ['CsvPreviewHandler', true],
            'excel is rich grid without raw text' => ['ExcelPreviewHandler', false],
            // PDF has no text source to fall back to.
            'pdf has no raw source' => ['PdfPreviewHandler', false],
            // Plain text and JSON are already escaped source.
            'text is already raw' => ['TextPreviewHandler', false],
            'json is already source' => ['JsonPreviewHandler', false],
            'binary notice renders nothing' => ['BinaryNotice', false],
        ];
    }

    // ------------------------------------------------------------------
    // Parameter safety
    // ------------------------------------------------------------------

    /**
     * The `view` request parameter is untrusted.
     *
     * @dataProvider hostileViewProvider
     */
    public function testUnknownViewValuesFallBackToRendered(?string $hostile): void
    {
        $this->assertSame(
            PreviewViewModeRegistry::VIEW_RENDERED,
            $this->registry->normalizeViewMode($hostile)
        );
        $this->assertFalse($this->registry->isRawView($hostile));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function hostileViewProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'unknown mode' => ['source'],
            'script tag' => ['<script>alert(1)</script>'],
            'quote break out' => ['" onmouseover="alert(1)'],
            'traversal' => ['../../etc/passwd'],
        ];
    }

    public function testRawIsRecognisedCaseInsensitively(): void
    {
        $this->assertTrue($this->registry->isRawView('raw'));
        $this->assertTrue($this->registry->isRawView('RAW'));
        $this->assertTrue($this->registry->isRawView('  Raw  '));
    }

    public function testOppositeViewModeFlips(): void
    {
        $this->assertSame(
            PreviewViewModeRegistry::VIEW_RAW,
            $this->registry->oppositeViewMode(PreviewViewModeRegistry::VIEW_RENDERED)
        );
        $this->assertSame(
            PreviewViewModeRegistry::VIEW_RENDERED,
            $this->registry->oppositeViewMode(PreviewViewModeRegistry::VIEW_RAW)
        );
        // An unknown current mode is treated as rendered, so it flips to raw.
        $this->assertSame(PreviewViewModeRegistry::VIEW_RAW, $this->registry->oppositeViewMode('nonsense'));
    }
}
