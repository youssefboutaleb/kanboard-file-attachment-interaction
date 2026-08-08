<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\PdfPreviewHandler;
use PHPUnit\Framework\TestCase;

class PdfPreviewHandlerTest extends TestCase
{
    private PdfPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new PdfPreviewHandler();
    }

    public function testSupportsPdfExtensionAndMimeType(): void
    {
        $this->assertTrue($this->handler->supports('pdf', 'application/pdf'));
        $this->assertTrue($this->handler->supports('PDF', 'application/x-pdf'));
        $this->assertFalse($this->handler->supports('txt', 'text/plain'));
    }

    public function testPreviewReturnsPdfMetadata(): void
    {
        $dummyPdfContent = "%PDF-1.4 ... binary data ...";
        $result = $this->handler->preview($dummyPdfContent);

        $this->assertTrue($result->isFormatted());
        $metadata = $result->getMetadata();

        $this->assertSame('PdfPreviewHandler', $metadata['handler']);
        $this->assertTrue($metadata['isBinary']);
        $this->assertSame(strlen($dummyPdfContent), $metadata['sizeBytes']);
    }
}
