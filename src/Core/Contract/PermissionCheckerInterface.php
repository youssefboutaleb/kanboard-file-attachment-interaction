<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core\Contract;

/**
 * Interface contract for ACL permission checkers.
 */
interface PermissionCheckerInterface
{
    /**
     * Check if a user has read access to a project.
     */
    public function canReadProject(int $projectId, ?int $userId = null): bool;

    /**
     * Check if a user has read access to a specific task.
     */
    public function canReadTask(int $projectId, int $taskId, ?int $userId = null): bool;

    /**
     * Check if a user has read access to a specific file attachment.
     */
    public function canReadFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): bool;
}
