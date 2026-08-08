<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

require_once __DIR__ . '/../stubs/BaseController.php';

use Kanboard\Plugin\FileInteractionCore\Controller\FileEditController;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use PHPUnit\Framework\TestCase;

class FileEditControllerTest extends TestCase
{
    public function testEditActionRendersModalForAuthorizedUser(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $result = $controller->edit(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertIsArray($result);
        $this->assertSame(1, $result['fileId']);
        $this->assertSame('config.json', $result['filename']);
        $this->assertSame('json', $result['extension']);
        $this->assertSame('{"status":"ok"}', $result['content']);
    }

    public function testEditActionReturns403ForUnauthorizedUser(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(false)));

        $result = $controller->edit(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['statusCode']);
        $this->assertSame('access_denied', $result['reason']);
    }

    public function testUpdateActionSavesValidPayload(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $result = $controller->update(1, 10, 100, 'notes.txt', 'Updated content', 'overwrite');

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('overwrite', $result['mode']);
    }

    public function testUpdateActionRejectsInvalidJson(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $invalidJson = "{\n  \"status\": active\n}";
        $result = $controller->update(1, 10, 100, 'config.json', $invalidJson, 'overwrite');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('validation_error', $result['reason']);
        $this->assertNotNull($result['errorLine']);
    }
}
