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

    /**
     * Write access to an attachment.
     *
     * Read access is necessary but NOT sufficient: a project viewer can see a task's
     * attachments and must not be able to overwrite them. When the installed checker
     * can answer the stronger question it is asked; `PermissionCheckerInterface` does
     * not declare the method, so checkers that predate it (and the test mock) keep
     * their previous read-equivalent behaviour rather than breaking.
     */
    public function canUserWriteFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): bool
    {
        if (!$this->canUserReadFile($projectId, $taskId, $fileId, $userId)) {
            return false;
        }

        if (method_exists($this->checker, 'canWriteProject')) {
            return (bool) $this->checker->canWriteProject($projectId, $userId);
        }

        return true;
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

