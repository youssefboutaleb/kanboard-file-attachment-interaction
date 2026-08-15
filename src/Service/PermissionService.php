<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\PermissionCheckerInterface;
use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;

/**
 * Service abstraction enforcing project, task, and file attachment ACL permissions.
 */
class PermissionService
{
    private PermissionCheckerInterface $checker;

    public function __construct(?PermissionCheckerInterface $checker = null)
    {
        $this->checker = $checker ?? new MockPermissionChecker(true);
    }

    public function canUserReadProject(int $projectId, ?int $userId = null): bool
    {
        return $this->checker->canReadProject($projectId, $userId);
    }

    public function canUserReadTask(int $projectId, int $taskId, ?int $userId = null): bool
    {
        return $this->checker->canReadTask($projectId, $taskId, $userId);
    }

    public function canUserReadFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): bool
    {
        return $this->checker->canReadFile($projectId, $taskId, $fileId, $userId);
    }

    public function canUserWriteFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): bool
    {
        return $this->canUserReadFile($projectId, $taskId, $fileId, $userId);
    }

    /**
     * Assert that user can read task attachment, throwing AccessDeniedException if forbidden.
     *
     * @throws AccessDeniedException
     */
    public function assertUserCanReadFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): void
    {
        if (!$this->canUserReadFile($projectId, $taskId, $fileId, $userId)) {
            throw new AccessDeniedException(sprintf(
                'Access Denied: User %s does not have read permissions for project #%d, task #%d, file #%d.',
                $userId !== null ? (string)$userId : '(current session user)',
                $projectId,
                $taskId,
                $fileId
            ));
        }
    }
}

