<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\ExcelParserService;
use PHPUnit\Framework\TestCase;

class ExcelParserServiceTest extends TestCase
{
    private ExcelParserService $service;

    protected function setUp(): void
    {
        $this->service = new ExcelParserService(5, 5); // 5 rows / 5 cols for testing
    }

    public function testHandlesEmptyContentGracefully(): void
    {
        $result = $this->service->parseXlsxContent('');

        $this->assertIsArray($result['sheets']);
        $this->assertEmpty($result['sheets']);
    }

    public function testHandlesInvalidZipFile(): void
    {
        $result = $this->service->parseXlsxContent('NOT_A_ZIP_FILE_DATA');

        $this->assertIsArray($result['sheets']);
        $this->assertIsArray($result['sheetNames']);
    }
}

