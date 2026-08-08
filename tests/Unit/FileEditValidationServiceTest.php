<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\FileEditValidationService;
use PHPUnit\Framework\TestCase;

class FileEditValidationServiceTest extends TestCase
{
    private FileEditValidationService $service;

    protected function setUp(): void
    {
        $this->service = new FileEditValidationService(1024); // 1 KB limit for unit tests
    }

    public function testValidPlainTextPayload(): void
    {
        $result = $this->service->validate("Hello World\nLine 2", 'txt');

        $this->assertTrue($result['isValid']);
        $this->assertNull($result['error']);
        $this->assertNull($result['errorLine']);
    }

    public function testOversizedPayloadRejection(): void
    {
        $largeContent = str_repeat('A', 2048);
        $result = $this->service->validate($largeContent, 'txt');

        $this->assertFalse($result['isValid']);
        $this->assertStringContainsString('exceeds maximum edit limit', (string) $result['error']);
    }

    public function testValidJsonPayload(): void
    {
        $json = "{\n  \"name\": \"Kanboard\",\n  \"status\": \"active\"\n}";
        $result = $this->service->validate($json, 'json');

        $this->assertTrue($result['isValid']);
        $this->assertNull($result['error']);
    }

    public function testInvalidJsonSyntaxRejection(): void
    {
        $invalidJson = "{\n  \"name\": \"Kanboard\",\n  \"status\": active\n}"; // Missing quotes around active
        $result = $this->service->validate($invalidJson, 'json');

        $this->assertFalse($result['isValid']);
        $this->assertStringContainsString('JSON Syntax Error', (string) $result['error']);
        $this->assertNotNull($result['errorLine']);
    }
}
