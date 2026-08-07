<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase
{
    private MockPermissionChecker $mockChecker;
    private PermissionService $service;

    protected function setUp(): void
    {
        $this->mockChecker = new MockPermissionChecker(true);
        $this->service = new PermissionService($this->mockChecker);
    }

    public function testCanUserReadProjectReturnsTrueByDefault(): void
    {
        $this->assertTrue($this->service->canUserReadProject(10, 1));
        $this->assertTrue($this->service->canUserReadTask(10, 100, 1));
        $this->assertTrue($this->service->canUserReadFile(10, 100, 1000, 1));
    }

    public function testCanUserReadProjectReturnsFalseForDeniedProject(): void
    {
        $this->mockChecker->setProjectAccess(10, false);

        $this->assertFalse($this->service->canUserReadProject(10, 1));
        $this->assertFalse($this->service->canUserReadTask(10, 100, 1));
        $this->assertFalse($this->service->canUserReadFile(10, 100, 1000, 1));
    }

    public function testCanUserReadTaskHonorsSpecificTaskRule(): void
    {
        $this->mockChecker->setProjectAccess(10, true);
        $this->mockChecker->setTaskAccess(10, 100, false);

        $this->assertTrue($this->service->canUserReadProject(10, 1));
        $this->assertFalse($this->service->canUserReadTask(10, 100, 1));
        $this->assertFalse($this->service->canUserReadFile(10, 100, 1000, 1));
    }

    public function testAssertUserCanReadFileThrowsAccessDeniedExceptionWhenUnauthorized(): void
    {
        $this->mockChecker->setFileAccess(10, 100, 1000, false);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access Denied: User 1 does not have read permissions for project #10, task #100, file #1000');

        $this->service->assertUserCanReadFile(10, 100, 1000, 1);
    }

    public function testAssertUserCanReadFileSucceedsWhenAuthorized(): void
    {
        $this->service->assertUserCanReadFile(10, 100, 1000, 1);
        $this->assertTrue(true);
    }
}
