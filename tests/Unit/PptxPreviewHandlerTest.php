<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\PptxPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\PptxParserService;
use PHPUnit\Framework\TestCase;

class PptxPreviewHandlerTest extends TestCase
{
    private PptxPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new PptxPreviewHandler();
    }

    public function testSupportsPptxAndPotxAndPpt(): void
    {
        $this->assertTrue($this->handler->supports('pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'));
        $this->assertTrue($this->handler->supports('potx', 'application/vnd.openxmlformats-officedocument.presentationml.template'));
        $this->assertTrue($this->handler->supports('ppt', 'application/vnd.ms-powerpoint'));
        $this->assertTrue($this->handler->supports('pptx', 'application/octet-stream'));
    }

    public function testRejectsForeignExtensions(): void
    {
        $this->assertFalse($this->handler->supports('txt', 'application/vnd.ms-powerpoint'));
        $this->assertFalse($this->handler->supports('pdf', 'application/pdf'));
        $this->assertFalse($this->handler->supports('xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $this->assertFalse($this->handler->supports('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    public function testLegacyPptReportsIsLegacyFormat(): void
    {
        $result = $this->handler->preview('BINARY_OLE2_PPT_DATA', ['extension' => 'ppt']);

        $this->assertTrue($result->isFormatted());
        $this->assertSame('', $result->getContent());
        $this->assertTrue($result->getMetadata()['isLegacyFormat']);
        $this->assertFalse($result->getMetadata()['parsed']);
    }

    public function testValidPptxReturnsSlideDeckAndMetadata(): void
    {
        $mockParser = $this->createMock(PptxParserService::class);
        $mockParser->expects($this->once())
            ->method('parsePptxContent')
            ->willReturn([
                'slides' => [
                    [
                        'index' => 1,
                        'title' => 'Intro Slide',
                        'paragraphs' => ['Welcome'],
                        'bulletPoints' => ['Point 1'],
                        'tables' => [],
                    ],
                ],
                'slideCount' => 1,
                'title' => 'Intro Slide',
                'parsed' => true,
            ]);

        $handler = new PptxPreviewHandler($mockParser);
        $result = $handler->preview('MOCK_PPTX_ZIP_BYTES', ['extension' => 'pptx']);

        $this->assertTrue($result->isFormatted());
        $this->assertSame(1, $result->getMetadata()['slideCount']);
        $this->assertSame('Intro Slide', $result->getMetadata()['title']);
        $this->assertFalse($result->getMetadata()['isLegacyFormat']);
        $this->assertTrue($result->getMetadata()['parsed']);
    }
}
