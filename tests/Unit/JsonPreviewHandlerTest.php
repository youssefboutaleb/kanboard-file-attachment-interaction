<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler;
use PHPUnit\Framework\TestCase;

class JsonPreviewHandlerTest extends TestCase
{
    private JsonPreviewHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new JsonPreviewHandler();
    }

    public function testSupportsReturnsTrueForJsonExtensionAndMime(): void
    {
        $this->assertTrue($this->handler->supports('json', 'application/json'));
        $this->assertTrue($this->handler->supports('.JSON', 'text/json'));
        $this->assertTrue($this->handler->supports('json', 'text/plain'));
    }

    public function testSupportsReturnsFalseForNonJson(): void
    {
        $this->assertFalse($this->handler->supports('txt', 'text/plain'));
        $this->assertFalse($this->handler->supports('html', 'text/html'));
        $this->assertFalse($this->handler->supports('php', 'application/x-php'));
    }

    public function testPreviewValidJsonPrettyPrints(): void
    {
        $rawJson = '{"name":"Kanboard","active":true,"version":1}';
        $result = $this->handler->preview($rawJson);

        $this->assertTrue($result->isFormatted());
        $this->assertTrue($result->getMetadata()['validJson']);
        $this->assertNull($result->getMetadata()['errorMessage']);

        $expectedPretty = "{\n    &quot;name&quot;: &quot;Kanboard&quot;,\n    &quot;active&quot;: true,\n    &quot;version&quot;: 1\n}";
        $this->assertSame($expectedPretty, $result->getContent());
    }

    public function testPreviewEscapesScriptTagsInJsonValues(): void
    {
        $unsafeJson = '{"payload":"<script>alert(1)</script>"}';
        $result = $this->handler->preview($unsafeJson);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result->getContent());
        $this->assertStringNotContainsString('<script>', $result->getContent());
    }

    public function testPreviewInvalidJsonReturnsFriendlyError(): void
    {
        $invalidJson = '{name: Kanboard, active: true}'; // Missing quotes around keys/values
        $result = $this->handler->preview($invalidJson);

        $this->assertFalse($result->isFormatted());
        $this->assertFalse($result->getMetadata()['validJson']);
        $this->assertNotNull($result->getMetadata()['errorMessage']);
        $this->assertStringContainsString('Invalid JSON', $result->getMetadata()['errorMessage']);

        $this->assertStringContainsString('[JSON Validation Error:', $result->getContent());
        $this->assertStringContainsString('{name: Kanboard, active: true}', $result->getContent());
    }

    public function testPreviewEnforcesMaxSizeTruncation(): void
    {
        $smallHandler = new JsonPreviewHandler(15);
        $oversizedJson = '{"key":"value_longer_than_15_bytes"}';

        $result = $smallHandler->preview($oversizedJson);

        $this->assertTrue($result->getMetadata()['truncated']);
        $this->assertFalse($result->getMetadata()['validJson']);
    }
}
