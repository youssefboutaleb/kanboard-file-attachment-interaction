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

        $this->controller->show(1, 10, 100, 'script.php', '<?php echo "evil";');
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
}
