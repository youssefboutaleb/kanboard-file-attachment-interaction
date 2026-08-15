<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\HtmlPreviewHandler;
use PHPUnit\Framework\TestCase;

class HtmlPreviewHandlerTest extends TestCase
{
    private HtmlPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new HtmlPreviewHandler();
    }

    public function testSupportsReturnsTrueForHtmlExtensionAndMime(): void
    {
        $this->assertTrue($this->handler->supports('html', 'text/html'));
        $this->assertTrue($this->handler->supports('htm', 'text/html'));
        $this->assertTrue($this->handler->supports('.HTML', 'text/plain'));
        $this->assertTrue($this->handler->supports('.htm', 'application/octet-stream'));
        $this->assertTrue($this->handler->supports('other', 'text/html'));
    }

    public function testSupportsReturnsFalseForNonHtml(): void
    {
        $this->assertFalse($this->handler->supports('txt', 'text/plain'));
        $this->assertFalse($this->handler->supports('pdf', 'application/pdf'));
        $this->assertFalse($this->handler->supports('php', 'application/x-php'));
        $this->assertFalse($this->handler->supports('json', 'application/json'));
    }

    public function testPreviewReturnsContentAndMetadata(): void
    {
        $html = "<h1>Hello</h1>\n<p>World</p>";
        $result = $this->handler->preview($html);

        $this->assertTrue($result->isFormatted());
        $this->assertSame($html, $result->getContent());
        $metadata = $result->getMetadata();
        $this->assertSame('HtmlPreviewHandler', $metadata['handler']);
        $this->assertSame(2, $metadata['lineCount']);
        $this->assertSame(mb_strlen($html, 'UTF-8'), $metadata['charCount']);
        $this->assertFalse($metadata['truncated']);
    }

    public function testPreviewTruncatesOversizedContent(): void
    {
        $smallHandler = new HtmlPreviewHandler(10);
        $html = '<h1>Very Long Heading That Exceeds Limit</h1>';

        $result = $smallHandler->preview($html);

        $this->assertTrue($result->getMetadata()['truncated']);
        $this->assertSame(10, strlen($result->getContent()));
        $this->assertSame('<h1>Very L', $result->getContent());
    }

    public function testGetHandlerName(): void
    {
        $this->assertSame('HtmlPreviewHandler', $this->handler->getHandlerName());
    }
}
