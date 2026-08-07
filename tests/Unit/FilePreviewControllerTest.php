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
}
