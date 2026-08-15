<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\MarkdownPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\MarkdownParserService;
use PHPUnit\Framework\TestCase;

class MarkdownPreviewHandlerTest extends TestCase
{
    private MarkdownPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new MarkdownPreviewHandler();
    }

    public function testSupportsMarkdownExtensions(): void
    {
        $this->assertTrue($this->handler->supports('md', 'text/markdown'));
        $this->assertTrue($this->handler->supports('markdown', 'text/x-markdown'));
        $this->assertTrue($this->handler->supports('.MD', 'application/octet-stream'));
        $this->assertTrue($this->handler->supports(' Markdown ', ''));
    }

    public function testSupportsMarkdownMimeTypesWithoutExtension(): void
    {
        $this->assertTrue($this->handler->supports('', 'text/markdown'));
        $this->assertTrue($this->handler->supports('', 'TEXT/X-MARKDOWN'));
    }

    /**
     * Generic text/* MIME types belong to TextPreviewHandler; claiming them here
     * would make resolution depend on registration order alone.
     */
    public function testDoesNotSupportUnrelatedFormats(): void
    {
        $this->assertFalse($this->handler->supports('json', 'application/json'));
        $this->assertFalse($this->handler->supports('csv', 'text/csv'));
        $this->assertFalse($this->handler->supports('txt', 'text/plain'));
        $this->assertFalse($this->handler->supports('html', 'text/html'));
    }

    public function testGetHandlerName(): void
    {
        $this->assertSame('MarkdownPreviewHandler', $this->handler->getHandlerName());
    }

    public function testPreviewRendersFormattedHtml(): void
    {
        $markdown = "# Release Notes\n\nShipped **today**.\n\n- First item\n- Second item";
        $result = $this->handler->preview($markdown);

        $this->assertTrue($result->isFormatted());
        $html = $result->getContent();

        $this->assertStringContainsString('<h1>Release Notes</h1>', $html);
        $this->assertStringContainsString('<strong>today</strong>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>First item</li>', $html);
        $this->assertStringContainsString('</ul>', $html);
    }

    public function testPreviewReturnsRequiredMetadataCounts(): void
    {
        $markdown = "# Title\n## Subtitle\nBody line\n```php\necho 1;\n```";
        $result = $this->handler->preview($markdown);

        $metadata = $result->getMetadata();

        $this->assertSame('MarkdownPreviewHandler', $metadata['handler']);
        $this->assertSame(2, $metadata['headingCount']);
        $this->assertSame(6, $metadata['lineCount']);
        $this->assertSame(mb_strlen($markdown, 'UTF-8'), $metadata['charCount']);
        $this->assertSame(1, $metadata['codeBlockCount']);
        $this->assertFalse($metadata['truncated']);
    }

    public function testPreviewEscapesRawHtmlPayloads(): void
    {
        $markdown = "# Report\n<script>alert('XSS')</script>\n<img src=x onerror=alert(1)>";
        $result = $this->handler->preview($markdown);

        $html = $result->getContent();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testPreviewSanitizesMaliciousLinkSchemes(): void
    {
        $markdown = "[Trap](javascript:alert(1))\n\n[Docs](https://example.com)";
        $result = $this->handler->preview($markdown);

        $html = $result->getContent();

        $this->assertStringContainsString('<a href="#" target="_blank" rel="noopener noreferrer">Trap</a>', $html);
        $this->assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener noreferrer">Docs</a>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function testPreviewRendersCodeFencesWithLanguageClass(): void
    {
        $markdown = "```python\ndef hello():\n    print(\"World\")\n```";
        $result = $this->handler->preview($markdown);

        $html = $result->getContent();

        $this->assertSame(1, $result->getMetadata()['codeBlockCount']);
        $this->assertStringContainsString('<pre class="language-python"><code>', $html);
        $this->assertStringContainsString('print(&quot;World&quot;)', $html);
    }

    public function testPreviewHandlesEmptyContentGracefully(): void
    {
        foreach (['', "   \n  \n"] as $emptyInput) {
            $result = $this->handler->preview($emptyInput);
            $metadata = $result->getMetadata();

            $this->assertSame('', $result->getContent());
            $this->assertSame(0, $metadata['headingCount']);
            $this->assertSame(0, $metadata['lineCount']);
            $this->assertSame(0, $metadata['codeBlockCount']);
            $this->assertFalse($metadata['truncated']);
        }
    }

    public function testPreviewTruncatesOversizedContent(): void
    {
        $handler = new MarkdownPreviewHandler(null, 32);
        $markdown = "# Heading\n" . str_repeat('long body text ', 100);

        $result = $handler->preview($markdown);
        $metadata = $result->getMetadata();

        $this->assertTrue($metadata['truncated']);
        $this->assertSame(32, $metadata['previewSizeBytes']);
        $this->assertSame(strlen($markdown), $metadata['originalSizeBytes']);
        $this->assertSame(32, $metadata['maxSizeBytes']);
        $this->assertStringContainsString('<h1>Heading</h1>', $result->getContent());
    }

    /**
     * A cut landing inside an open code fence must still yield balanced markup.
     */
    public function testTruncationInsideOpenCodeFenceStillClosesBlock(): void
    {
        $handler = new MarkdownPreviewHandler(null, 24);
        $result = $handler->preview("```php\necho 'a very long statement';\n```");

        $html = $result->getContent();

        $this->assertSame(1, $result->getMetadata()['codeBlockCount']);
        $this->assertStringContainsString('</code></pre>', $html);
    }

    public function testCharCountUsesMultiByteAwareLength(): void
    {
        $markdown = "こんにちは世界 🌍 🚀";
        $result = $this->handler->preview($markdown);

        $this->assertSame(11, $result->getMetadata()['charCount']);
    }

    public function testParsingIsDelegatedToInjectedParserService(): void
    {
        $markdown = '# Delegated';

        $parser = $this->createMock(MarkdownParserService::class);
        $parser->expects($this->once())
            ->method('parse')
            ->with($markdown)
            ->willReturn([
                'html' => '<h1>Delegated</h1>',
                'headingCount' => 1,
                'lineCount' => 1,
                'codeBlockCount' => 0,
            ]);

        $handler = new MarkdownPreviewHandler($parser);
        $result = $handler->preview($markdown);

        $this->assertSame('<h1>Delegated</h1>', $result->getContent());
        $this->assertSame(1, $result->getMetadata()['headingCount']);
    }
}
