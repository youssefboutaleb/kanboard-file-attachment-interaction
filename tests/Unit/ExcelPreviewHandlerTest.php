<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\ExcelPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\ExcelParserService;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in parser returning a canned workbook.
 *
 * The PHPUnit runtime (php:8.1-cli) has no ext-zip, so the real
 * ExcelParserService always short-circuits to an empty result there. Injecting
 * a fake keeps the HANDLER logic — escaping, metadata, active sheet selection —
 * genuinely covered; the real parser is exercised by the ZIP-guarded tests at
 * the bottom of this file.
 */
final class FakeExcelParser extends ExcelParserService
{
    /**
     * @var array{sheets: array<string, mixed>, sheetNames: list<string>}
     */
    private array $result;

    /**
     * @param array{sheets: array<string, mixed>, sheetNames: list<string>} $result
     */
    public function __construct(array $result)
    {
        parent::__construct();
        $this->result = $result;
    }

    /**
     * @return array{sheets: array<string, mixed>, sheetNames: list<string>}
     */
    public function parseXlsxContent(string $zipContent): array
    {
        return $this->result;
    }
}

class ExcelPreviewHandlerTest extends TestCase
{
    private ExcelPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ExcelPreviewHandler();
    }

    /**
     * @param array<string, array{rows: list<list<string>>, rowCount: int, columnCount: int, truncated: bool}> $sheets
     */
    private function handlerWithSheets(array $sheets): ExcelPreviewHandler
    {
        return new ExcelPreviewHandler(new FakeExcelParser([
            'sheets' => $sheets,
            'sheetNames' => array_keys($sheets),
        ]));
    }

    /**
     * @param list<list<string>> $rows
     * @return array{rows: list<list<string>>, rowCount: int, columnCount: int, truncated: bool}
     */
    private function sheet(array $rows, bool $truncated = false): array
    {
        return [
            'rows' => $rows,
            'rowCount' => count($rows),
            'columnCount' => $rows === [] ? 0 : count($rows[0]),
            'truncated' => $truncated,
        ];
    }

    // ---------------------------------------------------------------------
    // Format resolution
    // ---------------------------------------------------------------------

    public function testSupportsSpreadsheetExtensions(): void
    {
        $this->assertTrue($this->handler->supports('xlsx', ''));
        $this->assertTrue($this->handler->supports('xls', ''));
        $this->assertTrue($this->handler->supports('XLSX', 'application/octet-stream'));
        $this->assertTrue($this->handler->supports(' .Xls ', ''));
    }

    public function testSupportsSpreadsheetMimeTypes(): void
    {
        $this->assertTrue($this->handler->supports(
            'xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ));
        $this->assertTrue($this->handler->supports('xls', 'application/vnd.ms-excel'));
        // MIME alone is enough when the extension is unknown
        $this->assertTrue($this->handler->supports(
            'bin',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ));
    }

    public function testDoesNotSupportUnrelatedFormats(): void
    {
        $this->assertFalse($this->handler->supports('pdf', 'application/pdf'));
        $this->assertFalse($this->handler->supports('md', 'text/markdown'));
        $this->assertFalse($this->handler->supports('json', 'application/json'));
        $this->assertFalse($this->handler->supports('', ''));
    }

    /**
     * Spreadsheet software routinely exports plain .csv with an Excel MIME type.
     * Claiming those would hand tabular text to the spreadsheet grid depending
     * on handler registration order.
     */
    public function testDoesNotHijackCsvLabelledWithExcelMimeType(): void
    {
        $this->assertFalse($this->handler->supports('csv', 'application/vnd.ms-excel'));
        $this->assertFalse($this->handler->supports('tsv', 'application/vnd.ms-excel'));
        $this->assertFalse($this->handler->supports('txt', 'application/vnd.ms-excel'));
    }

    /**
     * Macro-enabled workbooks are outside the spec 006 scope.
     */
    public function testDoesNotSupportMacroEnabledWorkbooks(): void
    {
        $this->assertFalse($this->handler->supports('xlsm', ''));
        $this->assertFalse($this->handler->supports('xlsb', ''));
    }

    public function testHandlerName(): void
    {
        $this->assertSame('ExcelPreviewHandler', $this->handler->getHandlerName());
    }

    // ---------------------------------------------------------------------
    // Matrix & metadata
    // ---------------------------------------------------------------------

    public function testPreviewReturnsSpreadsheetMatrixAndMetadata(): void
    {
        $handler = $this->handlerWithSheets([
            'Summary' => $this->sheet([['Region', 'Total'], ['EMEA', '1200']]),
            'Detail' => $this->sheet([['Id', 'Value']]),
            'Notes' => $this->sheet([]),
        ]);

        $result = $handler->preview('binary', ['extension' => 'xlsx']);
        $metadata = $result->getMetadata();

        $this->assertSame('ExcelPreviewHandler', $metadata['handler']);
        $this->assertSame(3, $metadata['sheetCount']);
        $this->assertSame(['Summary', 'Detail', 'Notes'], $metadata['sheetNames']);
        $this->assertSame('Summary', $metadata['activeSheet']);
        $this->assertTrue($metadata['parsed']);
        $this->assertFalse($metadata['isLegacyFormat']);

        $this->assertSame(
            [['Region', 'Total'], ['EMEA', '1200']],
            $metadata['sheets']['Summary']['rows']
        );
        $this->assertSame(2, $metadata['sheets']['Summary']['rowCount']);
        $this->assertSame(2, $metadata['sheets']['Summary']['columnCount']);
    }

    /**
     * Consistent with CsvPreviewHandler and PdfPreviewHandler: the grid travels
     * in metadata and the content string stays empty.
     */
    public function testPreviewReturnsFormattedResultWithEmptyContent(): void
    {
        $result = $this->handlerWithSheets(['Sheet1' => $this->sheet([['a']])])
            ->preview('binary', ['extension' => 'xlsx']);

        $this->assertSame('', $result->getContent());
        $this->assertTrue($result->isFormatted());
    }

    /**
     * Spec 006 AC-3: every cell is entity-escaped before it can reach the DOM.
     */
    public function testPreviewEscapesMaliciousCellValues(): void
    {
        $handler = $this->handlerWithSheets([
            'Sheet1' => $this->sheet([
                ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>'],
                ["O'Brien & Co", '=1+1'],
            ]),
        ]);

        $rows = $handler->preview('binary', ['extension' => 'xlsx'])
            ->getMetadata()['sheets']['Sheet1']['rows'];

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $rows[0][0]);
        $this->assertStringNotContainsString('<img', $rows[0][1]);
        $this->assertStringContainsString('&lt;img', $rows[0][1]);
        $this->assertSame('O&#039;Brien &amp; Co', $rows[1][0]);
        // Formula text is displayed literally, never evaluated
        $this->assertSame('=1+1', $rows[1][1]);
    }

    /**
     * Sheet names come from the uploaded workbook and are attacker-controlled.
     */
    public function testPreviewEscapesMaliciousSheetNames(): void
    {
        $handler = $this->handlerWithSheets([
            '<script>alert(1)</script>' => $this->sheet([['x']]),
        ]);

        $metadata = $handler->preview('binary', ['extension' => 'xlsx'])->getMetadata();

        $this->assertSame(['&lt;script&gt;alert(1)&lt;/script&gt;'], $metadata['sheetNames']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $metadata['activeSheet']);
        $this->assertArrayHasKey('&lt;script&gt;alert(1)&lt;/script&gt;', $metadata['sheets']);
        $this->assertArrayNotHasKey('<script>alert(1)</script>', $metadata['sheets']);
    }

    public function testActiveSheetDefaultsToFirstSheet(): void
    {
        $handler = $this->handlerWithSheets([
            'Alpha' => $this->sheet([['1']]),
            'Beta' => $this->sheet([['2']]),
        ]);

        $metadata = $handler->preview('binary', ['extension' => 'xlsx'])->getMetadata();

        $this->assertSame('Alpha', $metadata['activeSheet']);
    }

    public function testActiveSheetHonorsRequestedSheet(): void
    {
        $handler = $this->handlerWithSheets([
            'Alpha' => $this->sheet([['1']]),
            'Beta' => $this->sheet([['2']]),
        ]);

        $metadata = $handler->preview('binary', [
            'extension' => 'xlsx',
            'activeSheet' => 'Beta',
        ])->getMetadata();

        $this->assertSame('Beta', $metadata['activeSheet']);
    }

    public function testActiveSheetFallsBackWhenRequestedSheetIsUnknown(): void
    {
        $handler = $this->handlerWithSheets([
            'Alpha' => $this->sheet([['1']]),
            'Beta' => $this->sheet([['2']]),
        ]);

        $metadata = $handler->preview('binary', [
            'extension' => 'xlsx',
            'activeSheet' => 'DoesNotExist',
        ])->getMetadata();

        $this->assertSame('Alpha', $metadata['activeSheet']);
    }

    /**
     * Spec 006 AC-4: truncation must surface per sheet AND in aggregate so the
     * template can show a notice without scanning every sheet.
     */
    public function testTruncationFlagsPropagate(): void
    {
        $handler = $this->handlerWithSheets([
            'Small' => $this->sheet([['a']]),
            'Huge' => $this->sheet([['b']], true),
        ]);

        $metadata = $handler->preview('binary', ['extension' => 'xlsx'])->getMetadata();

        $this->assertTrue($metadata['truncated']);
        $this->assertFalse($metadata['sheets']['Small']['truncated']);
        $this->assertTrue($metadata['sheets']['Huge']['truncated']);
    }

    public function testUntruncatedWorkbookReportsNoTruncation(): void
    {
        $metadata = $this->handlerWithSheets(['Sheet1' => $this->sheet([['a']])])
            ->preview('binary', ['extension' => 'xlsx'])
            ->getMetadata();

        $this->assertFalse($metadata['truncated']);
    }

    // ---------------------------------------------------------------------
    // Graceful degradation
    // ---------------------------------------------------------------------

    public function testPreviewDegradesGracefullyOnUnparsableContent(): void
    {
        foreach (['', 'NOT_A_ZIP_ARCHIVE'] as $content) {
            $metadata = (new ExcelPreviewHandler())
                ->preview($content, ['extension' => 'xlsx'])
                ->getMetadata();

            $this->assertSame(0, $metadata['sheetCount']);
            $this->assertSame([], $metadata['sheetNames']);
            $this->assertSame('', $metadata['activeSheet']);
            $this->assertSame([], $metadata['sheets']);
            $this->assertFalse($metadata['parsed']);
        }
    }

    /**
     * .xls is legacy binary BIFF, not an OpenXML package, so the ZIP parser
     * cannot read it. Flagging it lets the view show an honest notice instead
     * of an empty grid.
     */
    public function testLegacyXlsWorkbookIsFlaggedRatherThanRenderedEmpty(): void
    {
        $metadata = (new ExcelPreviewHandler())
            ->preview("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1legacy", ['extension' => 'xls'])
            ->getMetadata();

        $this->assertTrue($metadata['isLegacyFormat']);
        $this->assertFalse($metadata['parsed']);
        $this->assertSame(0, $metadata['sheetCount']);
    }

    public function testPreviewWithoutExtensionOptionDoesNotClaimLegacyFormat(): void
    {
        $metadata = $this->handlerWithSheets(['Sheet1' => $this->sheet([['a']])])
            ->preview('binary')
            ->getMetadata();

        $this->assertFalse($metadata['isLegacyFormat']);
        $this->assertTrue($metadata['parsed']);
    }

    // ---------------------------------------------------------------------
    // End-to-end against the real parser (requires ext-zip)
    // ---------------------------------------------------------------------

    /**
     * Build a minimal but valid .xlsx OpenXML package.
     *
     * @param array<string, list<list<string>>> $sheets
     */
    private function buildXlsx(array $sheets): string
    {
        $sharedStrings = [];
        foreach ($sheets as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $cell) {
                    if (!in_array($cell, $sharedStrings, true)) {
                        $sharedStrings[] = $cell;
                    }
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'fixture_') . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $sheetXml = '';
        $index = 1;
        foreach (array_keys($sheets) as $name) {
            $sheetXml .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', htmlspecialchars($name), $index, $index);
            $index++;
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook><sheets>' . $sheetXml . '</sheets></workbook>');

        $si = '';
        foreach ($sharedStrings as $string) {
            $si .= '<si><t>' . htmlspecialchars($string) . '</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst>' . $si . '</sst>');

        $index = 1;
        foreach ($sheets as $rows) {
            $rowXml = '';
            $rowNumber = 1;
            foreach ($rows as $row) {
                $cellXml = '';
                foreach ($row as $cell) {
                    $cellXml .= '<c t="s"><v>' . (int) array_search($cell, $sharedStrings, true) . '</v></c>';
                }
                $rowXml .= '<row r="' . $rowNumber . '">' . $cellXml . '</row>';
                $rowNumber++;
            }
            $zip->addFromString(
                'xl/worksheets/sheet' . $index . '.xml',
                '<?xml version="1.0"?><worksheet><sheetData>' . $rowXml . '</sheetData></worksheet>'
            );
            $index++;
        }

        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function requireZipExtension(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ext-zip is not available in this PHP runtime.');
        }
    }

    public function testEndToEndParsesRealXlsxWorkbook(): void
    {
        $this->requireZipExtension();

        $content = $this->buildXlsx([
            'Summary' => [['Region', 'Total'], ['EMEA', '1200']],
            'Detail' => [['Id']],
        ]);

        $metadata = (new ExcelPreviewHandler())
            ->preview($content, ['extension' => 'xlsx'])
            ->getMetadata();

        $this->assertTrue($metadata['parsed']);
        $this->assertSame(2, $metadata['sheetCount']);
        $this->assertSame(['Summary', 'Detail'], $metadata['sheetNames']);
        $this->assertSame('Summary', $metadata['activeSheet']);
        $this->assertSame(
            [['Region', 'Total'], ['EMEA', '1200']],
            $metadata['sheets']['Summary']['rows']
        );
    }

    public function testEndToEndEscapesCellsFromRealWorkbook(): void
    {
        $this->requireZipExtension();

        $content = $this->buildXlsx([
            'Sheet1' => [['<script>alert(1)</script>']],
        ]);

        $rows = (new ExcelPreviewHandler())
            ->preview($content, ['extension' => 'xlsx'])
            ->getMetadata()['sheets']['Sheet1']['rows'];

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $rows[0][0]);
    }
}
