<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use PHPUnit\Framework\TestCase;

class EdgeCasesTest extends TestCase
{
    public function testEmptyFileContentHandledGracefully(): void
    {
        $textHandler = new TextPreviewHandler();
        $jsonHandler = new JsonPreviewHandler();

        $textResult = $textHandler->preview('');
        $this->assertSame('', $textResult->getContent());
        $this->assertSame(0, $textResult->getMetadata()['lineCount']);

        $jsonResult = $jsonHandler->preview('');
        $this->assertFalse($jsonResult->getMetadata()['validJson']);
        $this->assertStringContainsString('Invalid JSON', $jsonResult->getMetadata()['errorMessage']);
    }

    public function testUnicodeMultiByteCharacterCountAccuracy(): void
    {
        $textHandler = new TextPreviewHandler();
        $unicodeContent = "こんにちは世界 🌍 🚀";

        $result = $textHandler->preview($unicodeContent);

        $this->assertSame(11, $result->getMetadata()['charCount']);
        $this->assertSame('こんにちは世界 🌍 🚀', $result->getContent());
    }

    public function testMixedCaseFileExtensionsAndWhitelisting(): void
    {
        $validator = new FileValidationService();

        $this->assertSame('env', $validator->validateExtension(' .ENV '));
        $this->assertSame('html', $validator->validateExtension('INDEX.HTML'));
        $this->assertSame('json', $validator->validateExtension('Data.JsOn'));
    }

    public function testSingleLineNoNewlineCount(): void
    {
        $textHandler = new TextPreviewHandler();
        $result = $textHandler->preview('Single line without newline');

        $this->assertSame(1, $result->getMetadata()['lineCount']);
    }
}
