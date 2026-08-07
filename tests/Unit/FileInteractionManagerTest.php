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
}
