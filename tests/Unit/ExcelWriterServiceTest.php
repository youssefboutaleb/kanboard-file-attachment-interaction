<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\ExcelParserService;
use Kanboard\Plugin\FileInteractionCore\Service\ExcelWriterService;
use PHPUnit\Framework\TestCase;

class ExcelWriterServiceTest extends TestCase
{
    private ExcelWriterService $service;

    protected function setUp(): void
    {
        $this->service = new ExcelWriterService();
    }

    public function testGetColumnLetter(): void
    {
        $this->assertSame('A', $this->service->getColumnLetter(0));
        $this->assertSame('B', $this->service->getColumnLetter(1));
        $this->assertSame('Z', $this->service->getColumnLetter(25));
        $this->assertSame('AA', $this->service->getColumnLetter(26));
        $this->assertSame('AB', $this->service->getColumnLetter(27));
        $this->assertSame('AZ', $this->service->getColumnLetter(51));
        $this->assertSame('BA', $this->service->getColumnLetter(52));
    }

    public function testBuildXlsxFromRowsAndParseBack(): void
    {
        $rows = [
            ['Name', 'Age', 'Department'],
            ['Alice', '30', 'Engineering'],
            ['Bob', '25', 'Marketing'],
        ];

        $xlsxBytes = $this->service->buildXlsxFromRows($rows, 'Staff');
        $this->assertNotEmpty($xlsxBytes);
        $this->assertStringStartsWith("\x50\x4b\x03\x04", $xlsxBytes);

        $parser = new ExcelParserService();
        $parsed = $parser->parseXlsxContent($xlsxBytes);

        $this->assertArrayHasKey('Staff', $parsed['sheets']);
        $sheet = $parsed['sheets']['Staff'];
        $this->assertCount(3, $sheet['rows']);
        $this->assertSame(['Name', 'Age', 'Department'], $sheet['rows'][0]);
        $this->assertSame(['Alice', '30', 'Engineering'], $sheet['rows'][1]);
        $this->assertSame(['Bob', '25', 'Marketing'], $sheet['rows'][2]);
    }

    public function testCsvToXlsxAndXlsxToCsvRoundTrip(): void
    {
        $csv = "Product,Price,Quantity\nLaptop,1200,5\nMouse,25,50\n";
        $xlsxBytes = $this->service->csvToXlsx($csv);

        $this->assertNotEmpty($xlsxBytes);

        $extractedCsv = $this->service->xlsxToCsv($xlsxBytes);
        $this->assertStringContainsString('Product,Price,Quantity', $extractedCsv);
        $this->assertStringContainsString('Laptop,1200,5', $extractedCsv);
        $this->assertStringContainsString('Mouse,25,50', $extractedCsv);
    }

    public function testXlsxToCsvHandlesEmptyContent(): void
    {
        $this->assertSame('', $this->service->xlsxToCsv(''));
        $this->assertSame('', $this->service->xlsxToCsv('not-a-valid-zip'));
    }

    public function testPackZipCreatesValidPkzip(): void
    {
        $files = [
            'file1.txt' => 'Hello World',
            'sub/file2.txt' => 'Nested content',
        ];

        $zip = $this->service->packZip($files);
        $this->assertStringStartsWith("\x50\x4b\x03\x04", $zip);

        $parser = new ExcelParserService();
        $extracted = $parser->extractZipFiles($zip);

        $this->assertArrayHasKey('file1.txt', $extracted);
        $this->assertSame('Hello World', $extracted['file1.txt']);
        $this->assertArrayHasKey('sub/file2.txt', $extracted);
        $this->assertSame('Nested content', $extracted['sub/file2.txt']);
    }

    public function testBuildXlsxFromMultiSheet(): void
    {
        $multiSheetData = [
            'North' => [['Region', 'Sales'], ['North-1', '1000']],
            'South' => [['Region', 'Sales'], ['South-1', '2000']],
        ];

        $xlsxBytes = $this->service->buildXlsxFromMultiSheet($multiSheetData);
        $this->assertNotEmpty($xlsxBytes);

        $parser = new ExcelParserService();
        $parsed = $parser->parseXlsxContent($xlsxBytes);

        $this->assertSame(['North', 'South'], $parsed['sheetNames']);
        $this->assertSame('1000', $parsed['sheets']['North']['rows'][1][1]);
        $this->assertSame('2000', $parsed['sheets']['South']['rows'][1][1]);
    }
}
