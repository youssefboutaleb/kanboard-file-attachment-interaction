<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/FakeContainer.php';

/**
 * Task 36: unknown and missing extensions are classified by content.
 *
 * Two safe outcomes only — an escaped text preview, or a download notice that
 * renders none of the attachment.
 */
class UnknownExtensionPreviewTest extends TestCase
{
    /**
     * @param array<string, mixed> $file
     */
    private function render(array $file, string $content, array $params = []): FakeTemplate
    {
        $template = new FakeTemplate();

        $container = new FakeContainer([
            'request' => new FakeRequest($params + ['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => new FakeResponse(),
            'template' => $template,
            'taskFileModel' => new FakeFileModel($file),
            'objectStorage' => new FakeObjectStorage($content),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        return $template;
    }

    // ------------------------------------------------------------------
    // Text content -> escaped preview with the picker
    // ------------------------------------------------------------------

    /**
     * @dataProvider unclassifiedTextProvider
     */
    public function testTextContentIsPreviewedForUnclassifiedExtensions(string $filename, string $content): void
    {
        $template = $this->render(
            ['name' => $filename, 'path' => 'tasks/1/f', 'task_id' => 1],
            $content
        );

        $this->assertSame('FileInteractionCore:file/preview', $template->renderedTemplate);
        $this->assertSame('TextPreviewHandler', $template->renderedVars['handler']);
        $this->assertTrue($template->renderedVars['metadata']['detectedAsText']);
        $this->assertTrue($template->renderedVars['languageSelectorEnabled'], 'The picker must be offered.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unclassifiedTextProvider(): array
    {
        return [
            'no extension' => ['LICENSE', "MIT License\n\nPermission is hereby granted...\n"],
            'Makefile' => ['Makefile', "build:\n\tgo build ./...\n"],
            'unrecognised extension' => ['dump.bak', "id,name\n1,alice\n"],
            'dotfile' => ['.gitignore', "vendor/\nnode_modules/\n"],
            'toml' => ['pyproject.toml', "[tool.poetry]\nname = \"demo\"\n"],
        ];
    }

    /**
     * Content from an unclassified attachment is still entity-escaped — this is
     * the whole reason it routes through TextPreviewHandler.
     */
    public function testUnclassifiedTextContentIsEscaped(): void
    {
        $template = $this->render(
            ['name' => 'payload.unknown', 'path' => 'tasks/1/f', 'task_id' => 1],
            '<script>alert("xss")</script>'
        );

        $content = (string) $template->renderedVars['content'];

        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    /**
     * The picker works on an unclassified file too, so a `.bak` holding SQL can
     * be highlighted as SQL.
     */
    public function testLanguageCanBePickedForAnUnclassifiedAttachment(): void
    {
        $template = $this->render(
            ['name' => 'dump.bak', 'path' => 'tasks/1/f', 'task_id' => 1],
            "-- dump\nSELECT id FROM users\n",
            ['lang' => 'sql']
        );

        $this->assertSame('CodePreviewHandler', $template->renderedVars['handler']);
        $this->assertSame('sql', $template->renderedVars['selectedLanguage']);
        $this->assertStringContainsString('tok-comment', (string) $template->renderedVars['content']);
    }

    public function testUnclassifiedTextDefaultsToPlainTextLanguage(): void
    {
        $template = $this->render(
            ['name' => 'LICENSE', 'path' => 'tasks/1/f', 'task_id' => 1],
            "MIT License\n"
        );

        $this->assertSame('text', $template->renderedVars['selectedLanguage']);
    }

    // ------------------------------------------------------------------
    // Binary content -> notice, nothing rendered
    // ------------------------------------------------------------------

    /**
     * @dataProvider unclassifiedBinaryProvider
     */
    public function testBinaryContentRendersTheDownloadNotice(string $filename, string $content, string $reason): void
    {
        $template = $this->render(
            ['name' => $filename, 'path' => 'tasks/1/f', 'task_id' => 1],
            $content
        );

        $this->assertSame('FileInteractionCore:file/binary_notice', $template->renderedTemplate);
        $this->assertSame('BinaryNotice', $template->renderedVars['handler']);
        $this->assertTrue($template->renderedVars['metadata']['isBinary']);
        $this->assertSame($reason, $template->renderedVars['metadata']['reason']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unclassifiedBinaryProvider(): array
    {
        return [
            'zip archive' => ['bundle.zip', "PK\x03\x04\x14\x00\x00\x00\x08\x00payload", 'null_byte'],
            'binary firmware' => ['firmware.bin', "PK\x03\x04\x00\x00firmware/data.raw", 'null_byte'],
            'windows executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00", 'null_byte'],
            'no extension binary' => ['coredump', "\x7fELF\x02\x01\x01\x00\x00\x00", 'null_byte'],
            'dense control bytes' => ['blob.dat', str_repeat("\x01\x02\x03\x04\x05\x06\x07\x08", 40), 'control_characters'],
        ];
    }

    /**
     * THE security property of the binary path: not a single byte of the payload
     * reaches the response.
     */
    public function testNoAttachmentBytesAreRenderedInTheBinaryNotice(): void
    {
        $payload = "PK\x03\x04\x00<script>alert('pwned')</script>\x00secret-marker";

        $template = $this->render(
            ['name' => 'evil.zip', 'path' => 'tasks/1/f', 'task_id' => 1],
            $payload
        );

        $this->assertSame('', $template->renderedVars['content']);
        $this->assertStringNotContainsString('secret-marker', (string) $template->renderedVars['content']);
        $this->assertStringNotContainsString('<script>', (string) $template->renderedVars['content']);
    }

    public function testBinaryNoticeCarriesDownloadIdentifiers(): void
    {
        $template = $this->render(
            ['name' => 'bundle.zip', 'path' => 'tasks/1/f', 'task_id' => 1],
            "PK\x03\x04\x00\x00"
        );

        $this->assertSame(5, $template->renderedVars['fileId']);
        $this->assertSame(1, $template->renderedVars['taskId']);
        $this->assertSame(1, $template->renderedVars['projectId']);
        $this->assertSame('bundle.zip', $template->renderedVars['filename']);
    }

    /**
     * The picker is meaningless on a notice that renders no content.
     */
    public function testBinaryNoticeDoesNotOfferTheLanguagePicker(): void
    {
        $template = $this->render(
            ['name' => 'bundle.zip', 'path' => 'tasks/1/f', 'task_id' => 1],
            "PK\x03\x04\x00\x00"
        );

        $this->assertArrayNotHasKey('languageSelectorEnabled', $template->renderedVars);
    }

    /**
     * Picking a language must not be a way to force binary bytes into a text view.
     */
    public function testLanguageChoiceCannotForceBinaryContentIntoATextView(): void
    {
        $template = $this->render(
            ['name' => 'bundle.zip', 'path' => 'tasks/1/f', 'task_id' => 1],
            "PK\x03\x04\x00\x00binary\x00payload",
            ['lang' => 'python']
        );

        $this->assertSame('FileInteractionCore:file/binary_notice', $template->renderedTemplate);
        $this->assertSame('', $template->renderedVars['content']);
    }

    // ------------------------------------------------------------------
    // Memory bound
    // ------------------------------------------------------------------

    /**
     * Allowing arbitrary extensions through means an arbitrary upload can be the
     * target, so an oversized attachment must never be buffered. The declared row
     * size short-circuits the read entirely.
     */
    public function testOversizedAttachmentIsNeverReadIntoMemory(): void
    {
        $template = new FakeTemplate();

        // The storage stub would return content, but it must not be consulted.
        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => new FakeResponse(),
            'template' => $template,
            'taskFileModel' => new FakeFileModel([
                'name' => 'huge.bin',
                'path' => 'tasks/1/huge',
                'task_id' => 1,
                'size' => FilePreviewController::CONTENT_READ_CEILING_BYTES + 1,
            ]),
            'objectStorage' => new FakeObjectStorage('should never be read'),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/binary_notice', $template->renderedTemplate);
        $this->assertSame('too_large', $template->renderedVars['metadata']['reason']);
        $this->assertSame('', $template->renderedVars['content']);
        $this->assertStringNotContainsString('should never be read', (string) $template->renderedVars['content']);
    }

    /**
     * A known extension whose declared size exceeds its cap still reports the cap
     * violation rather than silently previewing an empty buffer.
     */
    public function testOversizedKnownExtensionStillReportsTheSizeCap(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => $response,
            'template' => $template,
            'taskFileModel' => new FakeFileModel([
                'name' => 'huge.txt',
                'path' => 'tasks/1/huge',
                'task_id' => 1,
                'size' => FilePreviewController::CONTENT_READ_CEILING_BYTES + 1,
            ]),
            'objectStorage' => new FakeObjectStorage('should never be read'),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame(400, $response->statusCode);
        $this->assertStringContainsString('exceeds maximum allowed limit', (string) $template->renderedVars['message']);
    }

    /**
     * An unclassified text file above the 500 KB text cap is refused, not
     * truncated silently into a preview.
     */
    public function testUnclassifiedTextAboveTheTextCapIsRejected(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $oversizedText = str_repeat('a', FileValidationService::DEFAULT_MAX_SIZE_BYTES + 1);

        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => $response,
            'template' => $template,
            'taskFileModel' => new FakeFileModel(['name' => 'big.bak', 'path' => 'tasks/1/f', 'task_id' => 1]),
            'objectStorage' => new FakeObjectStorage($oversizedText),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame(400, $response->statusCode);
    }

    // ------------------------------------------------------------------
    // ACL and media exclusions
    // ------------------------------------------------------------------

    /**
     * Content inspection must never run ahead of the permission check.
     */
    public function testAclIsEnforcedBeforeContentIsInspected(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $checker = new MockPermissionChecker(true);
        $checker->setFileAccess(1, 1, 5, false);

        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => $response,
            'template' => $template,
            'taskFileModel' => new FakeFileModel(['name' => 'secret.bak', 'path' => 'tasks/1/f', 'task_id' => 1]),
            'objectStorage' => new FakeObjectStorage('confidential-marker'),
        ]);

        $controller = new FilePreviewController($container, new PermissionService($checker));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame(403, $response->statusCode);
        $this->assertSame('access_denied', $template->renderedVars['reason']);
    }

    /**
     * Core-owned media stays out of the inspection path entirely, so no URL can
     * route active content such as SVG into a preview.
     *
     * @dataProvider coreMediaProvider
     */
    public function testCoreOwnedMediaIsNotContentInspected(string $filename): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => $response,
            'template' => $template,
            'taskFileModel' => new FakeFileModel(['name' => $filename, 'path' => 'tasks/1/f', 'task_id' => 1]),
            'objectStorage' => new FakeObjectStorage('<svg onload="alert(1)"></svg>'),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame(400, $response->statusCode);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coreMediaProvider(): array
    {
        return [
            'svg is active content' => ['logo.svg'],
            'png' => ['diagram.png'],
            'jpeg' => ['photo.jpg'],
            'mp4' => ['clip.mp4'],
            'mp3' => ['audio.mp3'],
        ];
    }

    /**
     * An empty unclassified file previews as empty text rather than being called
     * binary — a download prompt for a zero-byte file would be useless.
     */
    public function testEmptyUnclassifiedFileIsPreviewedAsText(): void
    {
        $template = new FakeTemplate();

        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 5, 'task_id' => 1, 'project_id' => 1]),
            'response' => new FakeResponse(),
            'template' => $template,
            'taskFileModel' => new FakeFileModel(['name' => 'empty.bak', 'path' => 'tasks/1/f', 'task_id' => 1]),
            'objectStorage' => new FakeObjectStorage(''),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview', $template->renderedTemplate);
        $this->assertSame('empty', $template->renderedVars['metadata']['detectionReason']);
    }
}
