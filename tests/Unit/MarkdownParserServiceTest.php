<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\MarkdownParserService;
use PHPUnit\Framework\TestCase;

class MarkdownParserServiceTest extends TestCase
{
    private MarkdownParserService $service;

    protected function setUp(): void
    {
        $this->service = new MarkdownParserService();
    }

    public function testParsesHeadersAndLists(): void
    {
        $md = "# Heading 1\n## Heading 2\n- Item 1\n- Item 2";
        $result = $this->service->parse($md);

        $this->assertSame(2, $result['headingCount']);
        $this->assertStringContainsString('<h1>Heading 1</h1>', $result['html']);
        $this->assertStringContainsString('<h2>Heading 2</h2>', $result['html']);
        $this->assertStringContainsString('<ul>', $result['html']);
        $this->assertStringContainsString('<li>Item 1</li>', $result['html']);
    }

    public function testParsesBoldAndItalicText(): void
    {
        $md = "This is **bold** and *italic* text.";
        $result = $this->service->parse($md);

        $this->assertStringContainsString('<strong>bold</strong>', $result['html']);
        $this->assertStringContainsString('<em>italic</em>', $result['html']);
    }

    public function testParsesCodeFences(): void
    {
        $md = "```php\necho 'hello';\n```";
        $result = $this->service->parse($md);

        $this->assertSame(1, $result['codeBlockCount']);
        $this->assertStringContainsString('<pre class="language-php"><code>echo &#039;hello&#039;;</code></pre>', $result['html']);
    }

    public function testEscapesRawScriptTags(): void
    {
        $md = "# Hello\n<script>alert('XSS')</script>\n<img src=x onerror=alert(1)>";
        $result = $this->service->parse($md);

        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringNotContainsString('<img', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;', $result['html']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $result['html']);
    }

    public function testSanitizesJavascriptUriLinks(): void
    {
        $md = "[Malicious Link](javascript:alert(1))\n[Valid Link](https://example.com)";
        $result = $this->service->parse($md);

        $this->assertStringContainsString('<a href="#" target="_blank" rel="noopener noreferrer">Malicious Link</a>', $result['html']);
        $this->assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener noreferrer">Valid Link</a>', $result['html']);
    }

    public function testEmptyInputReturnsEmptyResult(): void
    {
        $result = $this->service->parse('');

        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['headingCount']);
    }
}
