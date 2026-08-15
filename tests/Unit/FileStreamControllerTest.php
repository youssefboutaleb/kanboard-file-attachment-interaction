<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Controller\FileStreamController;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\RecordingStreamEmitter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/FakeContainer.php';

/**
 * Task 35: inline binary streaming for the embedded PDF viewer.
 *
 * The defect these cover: core's FileViewerController::browser returns the right
 * Content-Type but every core response also carries `X-Frame-Options: DENY`,
 * which stops a browser rendering the PDF inside <object> and forces the
 * "Inline PDF viewing is not supported" fallback. FileStreamController owns the
 * framing policy instead, so the emitted header set is the contract under test.
 */
class FileStreamControllerTest extends TestCase
{
    private const PDF_BYTES = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $file
     */
    private function buildContainer(
        array $params,
        array $file,
        string $content,
        FakeResponse $response
    ): FakeContainer {
        return new FakeContainer([
            'request' => new FakeRequest($params),
            'response' => $response,
            'taskFileModel' => new FakeFileModel($file),
            'objectStorage' => new FakeObjectStorage($content),
        ]);
    }

    private function buildController(
        FakeContainer $container,
        RecordingStreamEmitter $emitter,
        bool $allowAccess = true
    ): FileStreamController {
        return new FileStreamController(
            $container,
            new PermissionService(new MockPermissionChecker($allowAccess)),
            new FileValidationService(),
            $emitter
        );
    }

    /**
     * The core regression: `X-Frame-Options` must not survive on the stream, or
     * the embedded viewer falls back to the "not supported" banner.
     */
    public function testStreamRemovesXFrameOptionsHeader(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 30, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'spec.pdf', 'path' => 'tasks/1/spec.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $this->buildController($container, $emitter)->inline();

        $this->assertContains(
            'X-Frame-Options',
            $emitter->removedHeaders,
            'X-Frame-Options: DENY must be stripped or <object> cannot render the PDF.'
        );
        $this->assertArrayNotHasKey('X-Frame-Options', $emitter->headers);
    }

    /**
     * Removing XFO must not leave the response embeddable by any origin: the
     * standardized frame-ancestors directive replaces it.
     */
    public function testStreamRestrictsFramingToSameOriginViaCsp(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 30, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'spec.pdf', 'path' => 'tasks/1/spec.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $this->buildController($container, $emitter)->inline();

        $this->assertArrayHasKey('Content-Security-Policy', $emitter->headers);
        $this->assertStringContainsString("frame-ancestors 'self'", $emitter->headers['Content-Security-Policy']);
        $this->assertStringContainsString("default-src 'none'", $emitter->headers['Content-Security-Policy']);
    }

    public function testStreamSendsInlinePdfContentTypeAndDisposition(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 30, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'spec.pdf', 'path' => 'tasks/1/spec.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertTrue($result['success']);
        $this->assertSame('application/pdf', $emitter->headers['Content-Type']);
        // `inline`, never `attachment` — attachment is what raises a save dialog.
        $this->assertStringStartsWith('inline;', $emitter->headers['Content-Disposition']);
        $this->assertStringNotContainsString('attachment', $emitter->headers['Content-Disposition']);
        $this->assertSame('nosniff', $emitter->headers['X-Content-Type-Options']);
        $this->assertSame((string) strlen(self::PDF_BYTES), $emitter->headers['Content-Length']);
    }

    public function testStreamEmitsAttachmentBytesUnaltered(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 30, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'spec.pdf', 'path' => 'tasks/1/spec.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $this->buildController($container, $emitter)->inline();

        $this->assertSame(self::PDF_BYTES, $emitter->body);
    }

    /**
     * ACL-protected content must never be stored by a shared cache.
     */
    public function testStreamMarksResponsePrivate(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 30, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'spec.pdf', 'path' => 'tasks/1/spec.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $this->buildController($container, $emitter)->inline();

        $this->assertStringContainsString('private', $emitter->headers['Cache-Control']);
        $this->assertStringNotContainsString('public', $emitter->headers['Cache-Control']);
    }

    public function testStreamRejectsRequestWithoutReadPermission(): void
    {
        $emitter = new RecordingStreamEmitter();
        $response = new FakeResponse();
        $container = $this->buildContainer(
            ['file_id' => 30, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'secret.pdf', 'path' => 'tasks/1/secret.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            $response
        );

        $result = $this->buildController($container, $emitter, false)->inline();

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('access_denied', $result['reason']);
        $this->assertSame(403, $response->statusCode);
        $this->assertStringNotContainsString('%PDF', $emitter->body, 'No attachment bytes may be emitted.');
    }

    /**
     * Active content must never be streamed from our own origin, whatever the
     * preview whitelist allows: an inline .html or .svg attachment would be
     * stored XSS. Only INLINE_MIME_TYPES formats are streamable.
     *
     * @dataProvider nonStreamableExtensionProvider
     */
    public function testStreamRefusesNonStreamableFormats(string $filename, string $content): void
    {
        $emitter = new RecordingStreamEmitter();
        $response = new FakeResponse();
        $container = $this->buildContainer(
            ['file_id' => 31, 'task_id' => 1, 'project_id' => 1],
            ['name' => $filename, 'path' => 'tasks/1/' . $filename, 'task_id' => 1],
            $content,
            $response
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertFalse($result['success'], $filename . ' must not be streamable inline.');
        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_file', $result['reason']);
        $this->assertArrayNotHasKey('Content-Disposition', $emitter->headers);
        $this->assertStringNotContainsString($content, $emitter->body);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonStreamableExtensionProvider(): array
    {
        return [
            'html is active content' => ['page.html', '<script>alert(1)</script>'],
            'svg is active content' => ['logo.svg', '<svg onload="alert(1)"></svg>'],
            'xlsx is binary but not streamable' => ['book.xlsx', 'PK payload'],
            'csv is tabular, rendered as a table' => ['data.csv', "a,b\n1,2"],
            'txt falls to the escaped text view' => ['notes.txt', 'plain'],
        ];
    }

    /**
     * A payload whose bytes are not really a PDF is refused rather than being
     * announced to the browser as application/pdf.
     */
    public function testStreamRejectsPayloadWithoutPdfSignature(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 32, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'fake.pdf', 'path' => 'tasks/1/fake.pdf', 'task_id' => 1],
            '<html><script>alert(1)</script></html>',
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_file', $result['reason']);
        $this->assertStringNotContainsString('<script>', $emitter->body, 'Mislabelled bytes must not reach the browser.');
    }

    /**
     * Real-world PDFs sometimes carry junk ahead of the %PDF header, so the
     * signature is scanned for within a window rather than anchored at offset 0.
     */
    public function testStreamAcceptsPdfWithLeadingJunkBeforeSignature(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 33, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'scanned.pdf', 'path' => 'tasks/1/scanned.pdf', 'task_id' => 1],
            "\r\n\r\n" . self::PDF_BYTES,
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertTrue($result['success']);
        $this->assertSame('application/pdf', $emitter->headers['Content-Type']);
    }

    public function testStreamEnforcesPdfSizeCap(): void
    {
        $emitter = new RecordingStreamEmitter();
        $oversized = self::PDF_BYTES . str_repeat('A', FileValidationService::PDF_MAX_SIZE_BYTES);

        $container = $this->buildContainer(
            ['file_id' => 34, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'huge.pdf', 'path' => 'tasks/1/huge.pdf', 'task_id' => 1],
            $oversized,
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_file', $result['reason']);
        $this->assertStringContainsString('exceeds maximum allowed limit', (string) $result['message']);
    }

    public function testStreamRejectsEmptyAttachment(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 35, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'empty.pdf', 'path' => 'tasks/1/empty.pdf', 'task_id' => 1],
            '',
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_file', $result['reason']);
    }

    /**
     * A traversal attempt in the stored path must not be followed by the
     * filesystem fallback that runs when objectStorage is unavailable.
     */
    public function testStreamRefusesTraversalPathInFilesystemFallback(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 36, 'task_id' => 1, 'project_id' => 1]),
            'response' => new FakeResponse(),
            'taskFileModel' => new FakeFileModel([
                'name' => 'escape.pdf',
                'path' => 'tasks/1/../../../../etc/passwd',
                'task_id' => 1,
            ]),
            // objectStorage deliberately absent to force the fallback read
        ]);

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_file', $result['reason']);
        $this->assertStringNotContainsString('root:', $emitter->body);
    }

    /**
     * A quoted filename must not be able to break out of the header value.
     */
    public function testStreamNeutralisesQuotesAndNewlinesInFilename(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 37, 'task_id' => 1, 'project_id' => 1],
            ['name' => "ev\"il\r\nX-Injected: 1.pdf", 'path' => 'tasks/1/evil.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertTrue($result['success']);
        $disposition = $emitter->headers['Content-Disposition'];
        $this->assertStringNotContainsString('"il', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertArrayNotHasKey('X-Injected', $emitter->headers);
    }

    /**
     * Path traversal in the attachment name must not escape the file id scope.
     */
    public function testStreamSanitisesFilenameToBasename(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 38, 'task_id' => 1, 'project_id' => 1],
            ['name' => '../../../../etc/passwd.pdf', 'path' => 'tasks/1/trav.pdf', 'task_id' => 1],
            self::PDF_BYTES,
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertSame('passwd.pdf', $result['filename']);
        $this->assertStringNotContainsString('..', $emitter->headers['Content-Disposition']);
    }

    public function testBuildStreamHeadersIsDeterministicForPdf(): void
    {
        $controller = new FileStreamController(null, new PermissionService(new MockPermissionChecker(true)));

        $headers = $controller->buildStreamHeaders('report.pdf', 'pdf', 2048);

        $this->assertSame('application/pdf', $headers['Content-Type']);
        $this->assertSame('inline; filename="report.pdf"', $headers['Content-Disposition']);
        $this->assertSame('2048', $headers['Content-Length']);
        $this->assertSame("default-src 'none'; frame-ancestors 'self'", $headers['Content-Security-Policy']);
    }

    public function testStreamableFormatsAreAllowListed(): void
    {
        $expected = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
        ];
        $this->assertSame($expected, FileStreamController::INLINE_MIME_TYPES);

        foreach (['html', 'htm', 'svg', 'js', 'php', 'xlsx', 'csv', 'txt'] as $unsafe) {
            $this->assertArrayNotHasKey(
                $unsafe,
                FileStreamController::INLINE_MIME_TYPES,
                sprintf('.%s must never be streamable inline.', $unsafe)
            );
        }
    }

    public function testStreamDocxWithPkSignature(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 40, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'document.docx', 'path' => 'tasks/1/document.docx', 'task_id' => 1],
            "PK\x03\x04\x00\x00word/document.xml",
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertTrue($result['success']);
        $this->assertSame('docx', $result['extension']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $emitter->headers['Content-Type']);
    }

    public function testStreamPptxWithPkSignature(): void
    {
        $emitter = new RecordingStreamEmitter();
        $container = $this->buildContainer(
            ['file_id' => 41, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'slides.pptx', 'path' => 'tasks/1/slides.pptx', 'task_id' => 1],
            "PK\x03\x04\x00\x00ppt/presentation.xml",
            new FakeResponse()
        );

        $result = $this->buildController($container, $emitter)->inline();

        $this->assertTrue($result['success']);
        $this->assertSame('pptx', $result['extension']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $emitter->headers['Content-Type']);
    }

    /**
     * Standalone mode (no Kanboard container) still resolves through the same
     * gates, mirroring FilePreviewController::show().
     */
    public function testStandaloneModeStreamsWithoutContainer(): void
    {
        $emitter = new RecordingStreamEmitter();
        $controller = new FileStreamController(
            null,
            new PermissionService(new MockPermissionChecker(true)),
            new FileValidationService(),
            $emitter
        );

        $result = $controller->inline(1, 10, 100, 'manual.pdf', self::PDF_BYTES);

        $this->assertTrue($result['success']);
        $this->assertSame('pdf', $result['extension']);
        $this->assertSame(self::PDF_BYTES, $emitter->body);
    }
}
