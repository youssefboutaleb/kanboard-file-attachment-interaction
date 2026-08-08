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
}
