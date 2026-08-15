<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Core\FileInteractionManager;
use PHPUnit\Framework\TestCase;

class FileInteractionManagerTest extends TestCase
{
    public function testPreviewResultImmutabilityAndAccessors(): void
    {
        $result = new PreviewResult('Safe content', true, ['lineCount' => 10]);

        $this->assertSame('Safe content', $result->getContent());
        $this->assertTrue($result->isFormatted());
        $this->assertSame(['lineCount' => 10], $result->getMetadata());
    }

    public function testRegisterAndResolveHandlerMatchingExtensionAndMime(): void
    {
        $manager = new FileInteractionManager();

        $handler = $this->createMock(FileHandlerInterface::class);
        $handler->method('supports')
            ->with('txt', 'text/plain')
            ->willReturn(true);

        $manager->registerHandler($handler);

        $resolved = $manager->resolveHandler('txt', 'text/plain');
        $this->assertSame($handler, $resolved);
    }

    public function testResolveReturnsNullWhenNoHandlerMatches(): void
    {
        $manager = new FileInteractionManager();

        $handler = $this->createMock(FileHandlerInterface::class);
        $handler->method('supports')
            ->willReturn(false);

        $manager->registerHandler($handler);

        $resolved = $manager->resolveHandler('exe', 'application/x-msdownload');
        $this->assertNull($resolved);
    }

    public function testExtensionNormalization(): void
    {
        $manager = new FileInteractionManager();

        $handler = $this->createMock(FileHandlerInterface::class);
        $handler->expects($this->once())
            ->method('supports')
            ->with('json', 'application/json')
            ->willReturn(true);

        $manager->registerHandler($handler);

        $resolved = $manager->resolveHandler('.JSON ', 'APPLICATION/JSON');
        $this->assertSame($handler, $resolved);
    }

    public function testGetHandlersReturnsRegisteredHandlers(): void
    {
        $manager = new FileInteractionManager();
        $this->assertEmpty($manager->getHandlers());

        $handler = $this->createMock(FileHandlerInterface::class);
        $manager->registerHandler($handler);

        $this->assertCount(1, $manager->getHandlers());
        $this->assertSame([$handler], $manager->getHandlers());
    }

    public function testManagerResolvesTextAndJsonHandlers(): void
    {
        $manager = new FileInteractionManager();
        $textHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler();
        $jsonHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler();

        $manager->registerHandler($textHandler);
        $manager->registerHandler($jsonHandler);

        $this->assertSame($textHandler, $manager->resolveHandler('txt', 'text/plain'));
        $this->assertSame($textHandler, $manager->resolveHandler('md', 'text/markdown'));
        $this->assertSame($textHandler, $manager->resolveHandler('env', 'text/plain'));
        $this->assertSame($textHandler, $manager->resolveHandler('html', 'text/html'));
        $this->assertSame($jsonHandler, $manager->resolveHandler('json', 'application/json'));
        $this->assertNull($manager->resolveHandler('exe', 'application/octet-stream'));
    }

    public function testForcedFormatResolution(): void
    {
        $manager = new FileInteractionManager();
        $textHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler();
        $jsonHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler();

        $manager->registerHandler($textHandler);
        $manager->registerHandler($jsonHandler);

        // Force json to open as text
        $resolved = $manager->resolveHandler('json', 'application/json', 'text');
        $this->assertSame($textHandler, $resolved);
    }

    /**
     * Build the same registry the controller wires up by default.
     *
     * @return array{FileInteractionManager, \Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler, \Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler, \Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler}
     */
    private function buildDefaultRegistry(): array
    {
        $manager = new FileInteractionManager();
        $csvHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler();
        $jsonHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler();
        $textHandler = new \Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler();

        $manager->registerHandler($csvHandler);
        $manager->registerHandler($jsonHandler);
        $manager->registerHandler($textHandler);

        return [$manager, $csvHandler, $jsonHandler, $textHandler];
    }

    public function testManagerResolvesCsvHandlerForTabularExtensions(): void
    {
        [$manager, $csvHandler] = $this->buildDefaultRegistry();

        $this->assertSame($csvHandler, $manager->resolveHandler('csv', 'text/csv'));
        $this->assertSame($csvHandler, $manager->resolveHandler('tsv', 'text/tab-separated-values'));
        $this->assertSame($csvHandler, $manager->resolveHandler('.CSV ', 'APPLICATION/CSV'));
    }

    /**
     * TextPreviewHandler claims every text/* MIME type, so it must never win the
     * race for a .csv attachment that was labelled as plain text.
     */
    public function testCsvHandlerWinsOverGenericTextHandlerForPlainTextMime(): void
    {
        [$manager, $csvHandler] = $this->buildDefaultRegistry();

        $this->assertSame($csvHandler, $manager->resolveHandler('csv', 'text/plain'));
        $this->assertSame($csvHandler, $manager->resolveHandler('tsv', 'text/plain'));
    }

    public function testExistingHandlerRoutingIsUnaffectedByCsvRegistration(): void
    {
        [$manager, , $jsonHandler, $textHandler] = $this->buildDefaultRegistry();

        $this->assertSame($textHandler, $manager->resolveHandler('txt', 'text/plain'));
        $this->assertSame($textHandler, $manager->resolveHandler('md', 'text/markdown'));
        $this->assertSame($textHandler, $manager->resolveHandler('html', 'text/html'));
        $this->assertSame($jsonHandler, $manager->resolveHandler('json', 'application/json'));
        $this->assertNull($manager->resolveHandler('exe', 'application/octet-stream'));
    }

    public function testForcedTextFormatIgnoresRegistrationOrder(): void
    {
        [$manager, , , $textHandler] = $this->buildDefaultRegistry();

        // CsvPreviewHandler is registered first, but "text" must resolve by name
        $this->assertSame($textHandler, $manager->resolveHandler('csv', 'text/csv', 'text'));
        $this->assertSame($textHandler, $manager->resolveHandler('json', 'application/json', 'text'));
    }

    public function testForcedCsvFormatResolvesCsvHandler(): void
    {
        [$manager, $csvHandler] = $this->buildDefaultRegistry();

        $this->assertSame($csvHandler, $manager->resolveHandler('csv', 'text/plain', 'csv'));
    }

    /**
     * Mirrors the Milestone 3 registry wired up by FilePreviewController.
     */
    private function buildMilestone3Registry(): FileInteractionManager
    {
        $manager = new FileInteractionManager();
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\MarkdownPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\CodePreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler());

        return $manager;
    }

    /**
     * Full resolution matrix for the registered handler chain. Every supported
     * extension must land on exactly one handler, independent of MIME hints.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function handlerResolutionProvider(): array
    {
        return [
            'csv table'        => ['csv', 'text/csv', 'CsvPreviewHandler'],
            'tsv table'        => ['tsv', 'text/tab-separated-values', 'CsvPreviewHandler'],
            'markdown short'   => ['md', 'text/markdown', 'MarkdownPreviewHandler'],
            'markdown long'    => ['markdown', 'text/markdown', 'MarkdownPreviewHandler'],
            'json pretty'      => ['json', 'application/json', 'JsonPreviewHandler'],
            'yaml config'      => ['yml', 'text/plain', 'CodePreviewHandler'],
            'yaml long config' => ['yaml', 'text/plain', 'CodePreviewHandler'],
            'xml markup'       => ['xml', 'text/plain', 'CodePreviewHandler'],
            'html markup'      => ['html', 'text/html', 'CodePreviewHandler'],
            'shell script'     => ['sh', 'text/plain', 'CodePreviewHandler'],
            'python source'    => ['py', 'text/plain', 'CodePreviewHandler'],
            'php source'       => ['php', 'text/plain', 'CodePreviewHandler'],
            'javascript'       => ['js', 'text/plain', 'CodePreviewHandler'],
            'stylesheet'       => ['css', 'text/plain', 'CodePreviewHandler'],
            'sql script'       => ['sql', 'text/plain', 'CodePreviewHandler'],
            'plain text'       => ['txt', 'text/plain', 'TextPreviewHandler'],
            'dotenv'           => ['env', 'text/plain', 'TextPreviewHandler'],
            'log file'         => ['log', 'text/plain', 'TextPreviewHandler'],
            'ini config'       => ['ini', 'text/plain', 'TextPreviewHandler'],
        ];
    }

    /**
     * @dataProvider handlerResolutionProvider
     */
    public function testMilestone3RegistryResolutionMatrix(
        string $extension,
        string $mimeType,
        string $expectedHandler
    ): void {
        $handler = $this->buildMilestone3Registry()->resolveHandler($extension, $mimeType);

        $this->assertNotNull($handler, "No handler resolved for .{$extension}");
        $this->assertSame($expectedHandler, $handler->getHandlerName());
    }

    /**
     * CodePreviewHandler also claims .json, so it must stay registered behind
     * JsonPreviewHandler to preserve the pretty-printed JSON view.
     */
    public function testJsonKeepsPrettyPrintedViewDespiteCodeHandler(): void
    {
        $handler = $this->buildMilestone3Registry()->resolveHandler('json', 'application/json');

        $this->assertNotNull($handler);
        $this->assertSame('JsonPreviewHandler', $handler->getHandlerName());
    }

    public function testMarkdownWinsOverCatchAllTextHandler(): void
    {
        // TextPreviewHandler lists md/markdown in its own allowed extensions
        $handler = $this->buildMilestone3Registry()->resolveHandler('md', 'text/plain');

        $this->assertNotNull($handler);
        $this->assertSame('MarkdownPreviewHandler', $handler->getHandlerName());
    }

    public function testForcedTextFormatOverridesRichHandlers(): void
    {
        $manager = $this->buildMilestone3Registry();

        // "view raw" must escape out of the Markdown and Code renderers
        $forcedOnMarkdown = $manager->resolveHandler('md', 'text/markdown', 'text');
        $forcedOnSource = $manager->resolveHandler('py', 'text/plain', 'text');

        $this->assertNotNull($forcedOnMarkdown);
        $this->assertNotNull($forcedOnSource);
        $this->assertSame('TextPreviewHandler', $forcedOnMarkdown->getHandlerName());
        $this->assertSame('TextPreviewHandler', $forcedOnSource->getHandlerName());
    }

    /**
     * A named format that declines the file falls back to normal resolution
     * rather than rendering an attachment through an unsuitable handler.
     */
    public function testForcedFormatFallsBackWhenHandlerDeclinesExtension(): void
    {
        $handler = $this->buildMilestone3Registry()->resolveHandler('txt', 'text/plain', 'markdown');

        $this->assertNotNull($handler);
        $this->assertSame('TextPreviewHandler', $handler->getHandlerName());
    }

    public function testUnsupportedExtensionStillResolvesToNull(): void
    {
        $this->assertNull($this->buildMilestone3Registry()->resolveHandler('exe', 'application/octet-stream'));
        $this->assertNull($this->buildMilestone3Registry()->resolveHandler('svg', 'image/svg+xml'));
    }

    /**
     * Mirrors the Milestone 4 registry wired up by FilePreviewController, with
     * PdfPreviewHandler registered ahead of every text handler.
     */
    private function buildMilestone4Registry(): FileInteractionManager
    {
        $manager = new FileInteractionManager();
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\PdfPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\MarkdownPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\CodePreviewHandler());
        $manager->registerHandler(new \Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler());

        return $manager;
    }

    public function testMilestone4RegistryResolvesPdfHandler(): void
    {
        $manager = $this->buildMilestone4Registry();

        foreach (['application/pdf', 'application/x-pdf', 'application/octet-stream'] as $mimeType) {
            $handler = $manager->resolveHandler('pdf', $mimeType);
            $this->assertNotNull($handler, "No handler resolved for .pdf with {$mimeType}");
            $this->assertSame('PdfPreviewHandler', $handler->getHandlerName());
        }

        $normalized = $manager->resolveHandler('.PDF ', 'APPLICATION/PDF');
        $this->assertNotNull($normalized);
        $this->assertSame('PdfPreviewHandler', $normalized->getHandlerName());
    }

    /**
     * TextPreviewHandler claims every text/* MIME type, so a mislabelled PDF must
     * still land on the binary handler rather than being escaped as plain text.
     */
    public function testPdfHandlerTakesPrecedenceOverTextCatchAll(): void
    {
        $handler = $this->buildMilestone4Registry()->resolveHandler('pdf', 'text/plain');

        $this->assertNotNull($handler);
        $this->assertSame('PdfPreviewHandler', $handler->getHandlerName());
    }

    /**
     * @dataProvider handlerResolutionProvider
     */
    public function testExistingRoutingIsUnaffectedByPdfRegistration(
        string $extension,
        string $mimeType,
        string $expectedHandler
    ): void {
        $handler = $this->buildMilestone4Registry()->resolveHandler($extension, $mimeType);

        $this->assertNotNull($handler, "No handler resolved for .{$extension}");
        $this->assertSame($expectedHandler, $handler->getHandlerName());
    }

    public function testForcedPdfFormatResolvesPdfHandler(): void
    {
        $handler = $this->buildMilestone4Registry()->resolveHandler('pdf', 'application/pdf', 'pdf');

        $this->assertNotNull($handler);
        $this->assertSame('PdfPreviewHandler', $handler->getHandlerName());
    }

    public function testUnsupportedBinaryFormatsStillResolveToNullAfterPdfRegistration(): void
    {
        $manager = $this->buildMilestone4Registry();

        $this->assertNull($manager->resolveHandler('exe', 'application/x-msdownload'));
        $this->assertNull($manager->resolveHandler('zip', 'application/zip'));
        $this->assertNull($manager->resolveHandler('svg', 'image/svg+xml'));
    }
}
