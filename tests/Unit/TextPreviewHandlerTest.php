<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler;
use PHPUnit\Framework\TestCase;

class TextPreviewHandlerTest extends TestCase
{
    private TextPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new TextPreviewHandler();
    }

    public function testSupportsReturnsTrueForTxtMdEnvIniYamlXmlLogHtmlExtensions(): void
    {
        $supported = ['txt', 'md', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm'];

        foreach ($supported as $ext) {
            $this->assertTrue(
                $this->handler->supports($ext, 'text/plain'),
                "TextPreviewHandler should support extension: .{$ext}"
            );
        }
    }

    public function testSupportsReturnsFalseForDangerousExecutableExtensions(): void
    {
        $dangerousExtensions = ['php', 'js', 'exe', 'sh', 'py', 'bat', 'cgi', 'dll', 'so'];

        foreach ($dangerousExtensions as $ext) {
            $this->assertFalse(
                $this->handler->supports($ext, 'text/plain'),
                "Handler should reject dangerous extension: {$ext}"
            );
        }
    }

    public function testPreviewHtmlRendersAsEscapedRawTextWithoutExecution(): void
    {
        $htmlContent = "<html><body><h1>Title</h1><script>alert('xss')</script></body></html>";
        $result = $this->handler->preview($htmlContent);

        $this->assertStringContainsString('&lt;html&gt;&lt;body&gt;', $result->getContent());
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $result->getContent());
        $this->assertStringNotContainsString('<script>', $result->getContent());
    }

    public function testPreviewEnvFileRendersAsRawText(): void
    {
        $envContent = "DB_HOST=127.0.0.1\nDB_USER=root\nAPP_SECRET=\"supersecret\"";
        $result = $this->handler->preview($envContent);

        $this->assertStringContainsString('DB_HOST=127.0.0.1', $result->getContent());
        $this->assertStringContainsString('&quot;supersecret&quot;', $result->getContent());
        $this->assertSame(3, $result->getMetadata()['lineCount']);
    }

    public function testPreviewEnforcesMaxSizeTruncation(): void
    {
        $smallHandler = new TextPreviewHandler(10);
        $longContent = "Hello World! This text is longer than 10 bytes.";

        $result = $smallHandler->preview($longContent);

        $this->assertSame('Hello Worl', $result->getContent());
        $this->assertTrue($result->getMetadata()['truncated']);
    }
}
