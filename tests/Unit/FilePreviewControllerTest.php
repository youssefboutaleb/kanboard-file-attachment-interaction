<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileContentFetcherInterface;
use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use PHPUnit\Framework\TestCase;

class FilePreviewControllerTest extends TestCase
{
    private MockPermissionChecker $mockChecker;
    private PermissionService $permissionService;
    private FilePreviewController $controller;

    protected function setUp(): void
    {
        $this->mockChecker = new MockPermissionChecker(true);
        $this->permissionService = new PermissionService($this->mockChecker);

        $fetcher = new class implements FileContentFetcherInterface {
            public function getFileContent(int $fileId): string
            {
                return '{"status":"ok"}';
            }
        };

        $this->controller = new FilePreviewController(
            null,
            $this->permissionService,
            null,
            null,
            $fetcher
        );
    }

    public function testShowReturnsPreviewResultForAuthorizedUser(): void
    {
        $response = $this->controller->show(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['projectId']);
        $this->assertSame(10, $response['taskId']);
        $this->assertSame(100, $response['fileId']);
        $this->assertSame('config.json', $response['filename']);
        $this->assertSame('JsonPreviewHandler', $response['handler']);
        $this->assertStringContainsString('&quot;status&quot;: &quot;ok&quot;', $response['content']);
    }

    public function testShowThrowsAccessDeniedExceptionWhenUnauthorized(): void
    {
        $this->mockChecker->setFileAccess(1, 10, 100, false);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access Denied');

        $this->controller->show(1, 10, 100, 'config.json', '{"status":"ok"}');
    }

    public function testShowThrowsInvalidFileExceptionForDisallowedFile(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('is not allowed');

        // .php became previewable in Milestone 3; .svg stays rejected because
        // SVG is an active-content image format, not inert source text.
        $this->controller->show(1, 10, 100, 'logo.svg', '<svg onload="alert(1)"></svg>');
    }

    public function testShowHonorsForcedFormat(): void
    {
        $response = $this->controller->show(1, 10, 100, 'config.json', '{"status":"ok"}', 'text');

        $this->assertSame('TextPreviewHandler', $response['handler']);
        $this->assertSame('{&quot;status&quot;:&quot;ok&quot;}', $response['content']);
    }

    public function testShowRoutesCsvAttachmentToCsvHandler(): void
    {
        $csv = "id,name,role\n1,Alice,Admin\n2,Bob,User";
        $response = $this->controller->show(1, 10, 100, 'people.csv', $csv);

        $this->assertTrue($response['success']);
        $this->assertSame('csv', $response['extension']);
        $this->assertSame('CsvPreviewHandler', $response['handler']);
        $this->assertSame(',', $response['metadata']['delimiter']);
        $this->assertSame(3, $response['metadata']['totalRows']);
        $this->assertSame(['id', 'name', 'role'], $response['metadata']['rows'][0]);
    }

    public function testShowRoutesTsvAttachmentToCsvHandler(): void
    {
        $tsv = "id\tname\n1\tAlice\n2\tBob";
        $response = $this->controller->show(1, 10, 100, 'people.tsv', $tsv);

        $this->assertSame('tsv', $response['extension']);
        $this->assertSame('CsvPreviewHandler', $response['handler']);
        $this->assertSame("\t", $response['metadata']['delimiter']);
    }

    public function testShowEscapesMaliciousCsvCellsEndToEnd(): void
    {
        $csv = "name,payload\nEvil,\"<script>alert(1)</script>\"";
        $response = $this->controller->show(1, 10, 100, 'attack.csv', $csv);

        $rendered = json_encode($response['metadata']['rows']);

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $response['metadata']['rows'][1][1]);
        $this->assertStringNotContainsString('<script>', (string) $rendered);
    }

    public function testShowStillRoutesPlainTextAttachmentsToTextHandler(): void
    {
        $response = $this->controller->show(1, 10, 100, 'notes.txt', "line one\nline two");

        $this->assertSame('TextPreviewHandler', $response['handler']);
    }

    public function testShowRoutesMarkdownAttachmentToMarkdownHandler(): void
    {
        $markdown = "# Release Notes\n\n- First\n- Second";
        $response = $this->controller->show(1, 10, 100, 'NOTES.md', $markdown);

        $this->assertTrue($response['success']);
        $this->assertSame('MarkdownPreviewHandler', $response['handler']);
        $this->assertStringContainsString('<h1>Release Notes</h1>', $response['content']);
        $this->assertSame(1, $response['metadata']['headingCount']);
    }

    public function testShowRoutesLongMarkdownExtensionToMarkdownHandler(): void
    {
        $response = $this->controller->show(1, 10, 100, 'README.markdown', '## Setup');

        $this->assertSame('markdown', $response['extension']);
        $this->assertSame('MarkdownPreviewHandler', $response['handler']);
        $this->assertStringContainsString('<h2>Setup</h2>', $response['content']);
    }

    public function testShowRoutesSourceCodeAttachmentToCodeHandler(): void
    {
        $response = $this->controller->show(1, 10, 100, 'analyze.py', "def run():\n    return 1");

        $this->assertSame('CodePreviewHandler', $response['handler']);
        $this->assertSame('py', $response['metadata']['language']);
        $this->assertStringContainsString('tok-keyword', $response['content']);
    }

    /**
     * The controller must forward the extension so the code handler can label the
     * language; without it every source file would fall back to "txt".
     */
    public function testShowForwardsExtensionAsCodeLanguage(): void
    {
        $sql = $this->controller->show(1, 10, 100, 'migration.sql', 'select 1;');
        $shell = $this->controller->show(1, 10, 100, 'deploy.sh', 'echo hi');

        $this->assertSame('sql', $sql['metadata']['language']);
        $this->assertSame('sh', $shell['metadata']['language']);
    }

    public function testShowRoutesConfigMarkupToCodeHandler(): void
    {
        $yaml = $this->controller->show(1, 10, 100, 'deploy.yml', "key: value");
        $xml = $this->controller->show(1, 10, 100, 'feed.xml', '<root></root>');

        $this->assertSame('CodePreviewHandler', $yaml['handler']);
        $this->assertSame('CodePreviewHandler', $xml['handler']);
    }

    public function testShowKeepsJsonOnPrettyPrintingHandler(): void
    {
        $response = $this->controller->show(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertSame('JsonPreviewHandler', $response['handler']);
        $this->assertTrue($response['metadata']['validJson']);
    }

    public function testShowEscapesScriptPayloadInSourceAttachment(): void
    {
        $response = $this->controller->show(1, 10, 100, 'evil.php', "<?php echo '<script>alert(1)</script>';");

        $this->assertSame('CodePreviewHandler', $response['handler']);
        $this->assertStringNotContainsString('<script>', $response['content']);
        $this->assertStringContainsString('&lt;script&gt;', $response['content']);
    }

    public function testShowSanitizesMaliciousMarkdownLinks(): void
    {
        $response = $this->controller->show(1, 10, 100, 'doc.md', '[Trap](javascript:alert(1))');

        $this->assertStringNotContainsString('href="javascript:', $response['content']);
        $this->assertStringContainsString('href="#"', $response['content']);
    }

    /**
     * Spec 004 TC-PDF-01: .pdf attachments must resolve to the PDF handler and
     * never fall through to the TextPreviewHandler catch-all.
     */
    public function testShowRoutesPdfAttachmentToPdfHandler(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $response = $this->controller->show(1, 10, 100, 'invoice.pdf', $pdf);

        $this->assertTrue($response['success']);
        $this->assertSame('pdf', $response['extension']);
        $this->assertSame('PdfPreviewHandler', $response['handler']);
        $this->assertTrue($response['metadata']['isBinary']);
        $this->assertSame(strlen($pdf), $response['metadata']['sizeBytes']);
    }

    public function testShowRoutesUppercasePdfExtensionToPdfHandler(): void
    {
        $response = $this->controller->show(1, 10, 100, 'SPEC.PDF', '%PDF-1.7');

        $this->assertSame('pdf', $response['extension']);
        $this->assertSame('PdfPreviewHandler', $response['handler']);
    }

    /**
     * A 600 KB PDF exceeds the 500 KB text budget but sits well inside the 10 MB
     * PDF cap — proof the per-extension override is wired through the controller.
     */
    public function testShowAcceptsPdfLargerThanDefaultTextLimit(): void
    {
        $largePdf = '%PDF-1.4' . str_repeat('A', 614400);
        $response = $this->controller->show(1, 10, 100, 'large.pdf', $largePdf);

        $this->assertSame('PdfPreviewHandler', $response['handler']);
        $this->assertSame(strlen($largePdf), $response['metadata']['sizeBytes']);
    }

    /**
     * Spec 004 TC-PDF-02: widening the whitelist for .pdf must not admit other
     * binary container formats.
     */
    public function testShowStillRejectsDisallowedBinaryFormats(): void
    {
        foreach (['archive.zip', 'installer.exe', 'report.docx'] as $filename) {
            try {
                $this->controller->show(1, 10, 100, $filename, 'PK binary payload');
                $this->fail("Preview should have been rejected for {$filename}");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('is not allowed', $e->getMessage());
            }
        }
    }

    /**
     * Spec 004 TC-PDF-04: ACL enforcement runs before any PDF payload is touched.
     */
    public function testShowDeniesPdfPreviewForUnauthorizedUser(): void
    {
        $this->mockChecker->setFileAccess(1, 10, 100, false);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access Denied');

        $this->controller->show(1, 10, 100, 'invoice.pdf', '%PDF-1.4');
    }
}
