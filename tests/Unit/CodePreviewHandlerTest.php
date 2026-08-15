<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\CodePreviewHandler;
use PHPUnit\Framework\TestCase;

class CodePreviewHandlerTest extends TestCase
{
    private CodePreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CodePreviewHandler();
    }

    public function testSupportsCodeExtensions(): void
    {
        $this->assertTrue($this->handler->supports('py', 'text/x-python'));
        $this->assertTrue($this->handler->supports('sh', 'text/x-sh'));
        $this->assertTrue($this->handler->supports('js', 'application/javascript'));
        $this->assertTrue($this->handler->supports('sql', 'text/x-sql'));
        $this->assertFalse($this->handler->supports('docx', 'application/docx'));
    }

    public function testHighlightsKeywordsAndStrings(): void
    {
        $code = "def hello():\n    return \"world\"";
        $result = $this->handler->preview($code, ['extension' => 'py']);

        $this->assertTrue($result->isFormatted());
        $html = $result->getContent();

        $this->assertStringContainsString('span class="tok-keyword"', $html);
        $this->assertStringContainsString('def', $html);
        $this->assertStringContainsString('return', $html);
        $this->assertStringContainsString('span class="tok-string"', $html);
    }

    public function testEscapesHtmlTagsInCode(): void
    {
        $code = "<script>alert('xss')</script>";
        $result = $this->handler->preview($code, ['extension' => 'html']);

        $html = $result->getContent();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPreviewMetadataCalculation(): void
    {
        $code = "line1\nline2\nline3";
        $result = $this->handler->preview($code, ['extension' => 'sh']);

        $metadata = $result->getMetadata();
        $this->assertSame('CodePreviewHandler', $metadata['handler']);
        $this->assertSame('sh', $metadata['language']);
        $this->assertSame(3, $metadata['lineCount']);
    }
}
