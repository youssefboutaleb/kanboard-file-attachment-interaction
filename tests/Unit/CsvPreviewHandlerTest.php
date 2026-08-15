<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler;
use PHPUnit\Framework\TestCase;

class CsvPreviewHandlerTest extends TestCase
{
    private CsvPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CsvPreviewHandler();
    }

    public function testSupportsCsvAndTsvExtensions(): void
    {
        $this->assertTrue($this->handler->supports('csv', 'text/csv'));
        $this->assertTrue($this->handler->supports('tsv', 'text/tab-separated-values'));
        $this->assertTrue($this->handler->supports('CSV', 'application/octet-stream'));
        $this->assertFalse($this->handler->supports('json', 'application/json'));
    }

    public function testPreviewEscapesCellHtmlContent(): void
    {
        $csv = "name,script\nAlice,\"<script>alert(1)</script>\"\nBob,\"<img src=x onerror=alert(2)>\"";
        $result = $this->handler->preview($csv);

        $this->assertTrue($result->isFormatted());
        $metadata = $result->getMetadata();

        $this->assertSame('CsvPreviewHandler', $metadata['handler']);
        $this->assertSame(',', $metadata['delimiter']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $metadata['rows'][1][1]);
        $this->assertSame('&lt;img src=x onerror=alert(2)&gt;', $metadata['rows'][2][1]);
    }

    public function testPreviewReturnsParsedRowsAndMetadata(): void
    {
        $csv = "id;name;city\n10;Paris;FR\n20;London;UK";
        $result = $this->handler->preview($csv);

        $metadata = $result->getMetadata();
        $this->assertSame(';', $metadata['delimiter']);
        $this->assertSame(3, $metadata['totalRows']);
        $this->assertSame(3, $metadata['totalColumns']);
        $this->assertFalse($metadata['truncatedRows']);
    }
}
