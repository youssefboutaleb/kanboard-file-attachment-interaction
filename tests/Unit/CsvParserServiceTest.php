<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\CsvParserService;
use PHPUnit\Framework\TestCase;

class CsvParserServiceTest extends TestCase
{
    private CsvParserService $service;

    protected function setUp(): void
    {
        $this->service = new CsvParserService(5, 3);
    }

    public function testDetectsCommaDelimiter(): void
    {
        $csv = "id,name,role\n1,Alice,Admin\n2,Bob,User";
        $this->assertSame(',', $this->service->detectDelimiter($csv));
    }

    public function testDetectsSemicolonDelimiter(): void
    {
        $csv = "id;name;city\n100;Paris;FR\n200;Berlin;DE";
        $this->assertSame(';', $this->service->detectDelimiter($csv));
    }

    public function testDetectsTabAndPipeDelimiters(): void
    {
        $tsv = "id\tname\tage\n1\tAlice\t30";
        $pipe = "id|name|status\n1|Task A|Done";

        $this->assertSame("\t", $this->service->detectDelimiter($tsv));
        $this->assertSame('|', $this->service->detectDelimiter($pipe));
    }

    public function testParseCommaCsvSuccessfully(): void
    {
        $csv = "id,name,role\n1,Alice,Admin\n2,Bob,User";
        $result = $this->service->parse($csv);

        $this->assertSame(',', $result['delimiter']);
        $this->assertCount(3, $result['rows']);
        $this->assertSame(['id', 'name', 'role'], $result['rows'][0]);
        $this->assertSame(['1', 'Alice', 'Admin'], $result['rows'][1]);
        $this->assertFalse($result['truncatedRows']);
        $this->assertFalse($result['truncatedColumns']);
    }

    public function testEnforcesMaxRowsLimit(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "{$i},Item{$i}";
        }
        $csv = implode("\n", $lines);

        $result = $this->service->parse($csv);

        $this->assertCount(5, $result['rows']); // Max 5 configured in setUp()
        $this->assertTrue($result['truncatedRows']);
    }

    public function testEnforcesMaxColumnsLimit(): void
    {
        $csv = "c1,c2,c3,c4,c5\n1,2,3,4,5";
        $result = $this->service->parse($csv);

        $this->assertCount(3, $result['rows'][0]); // Max 3 configured in setUp()
        $this->assertSame(['c1', 'c2', 'c3'], $result['rows'][0]);
        $this->assertTrue($result['truncatedColumns']);
    }

    public function testEmptyInputReturnsEmptyResult(): void
    {
        $result = $this->service->parse('');

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['totalRows']);
        $this->assertFalse($result['truncatedRows']);
    }
}
