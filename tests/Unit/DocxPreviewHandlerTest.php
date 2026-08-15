<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\DocxPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\DocxParserService;
use PHPUnit\Framework\TestCase;

class DocxPreviewHandlerTest extends TestCase
{
    private DocxPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new DocxPreviewHandler();
    }

    public function testSupportsDocxAndDotxAndDoc(): void
    {
        $this->assertTrue($this->handler->supports('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertTrue($this->handler->supports('dotx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.template'));
        $this->assertTrue($this->handler->supports('doc', 'application/msword'));
        $this->assertTrue($this->handler->supports('docx', 'application/octet-stream'));
    }

    public function testRejectsForeignExtensions(): void
    {
        $this->assertFalse($this->handler->supports('txt', 'application/msword'));
        $this->assertFalse($this->handler->supports('pdf', 'application/pdf'));
        $this->assertFalse($this->handler->supports('xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $this->assertFalse($this->handler->supports('pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'));
    }

    public function testLegacyDocReportsIsLegacyFormat(): void
    {
        $result = $this->handler->preview('BINARY_OLE2_DOC_DATA', ['extension' => 'doc']);

        $this->assertTrue($result->isFormatted());
        $this->assertSame('', $result->getContent());
        $this->assertTrue($result->getMetadata()['isLegacyFormat']);
        $this->assertFalse($result->getMetadata()['parsed']);
    }

    public function testValidDocxReturnsPreEscapedHtmlAndMetadata(): void
    {
        $mockParser = $this->createMock(DocxParserService::class);
        $mockParser->expects($this->once())
            ->method('parseDocxContent')
            ->willReturn([
                'html' => '<h1 class="docx-heading">Title</h1><p class="docx-paragraph">Hello World</p>',
                'paragraphCount' => 1,
                'headingCount' => 1,
                'tableCount' => 0,
                'wordCount' => 2,
                'parsed' => true,
            ]);

        $handler = new DocxPreviewHandler($mockParser);
        $result = $handler->preview('MOCK_DOCX_ZIP_BYTES', ['extension' => 'docx']);

        $this->assertTrue($result->isFormatted());
        $this->assertStringContainsString('Hello World', $result->getContent());
        $this->assertSame(1, $result->getMetadata()['paragraphCount']);
        $this->assertSame(1, $result->getMetadata()['headingCount']);
        $this->assertSame(2, $result->getMetadata()['wordCount']);
        $this->assertFalse($result->getMetadata()['isLegacyFormat']);
        $this->assertTrue($result->getMetadata()['parsed']);
    }
}
