<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\PermissionCheckerInterface;

/**
 * Mock/Fallback permission checker for standalone unit testing and isolated environments.
 */
class MockPermissionChecker implements PermissionCheckerInterface
{
    /**
     * @var array<int, bool>
     */
    private array $allowedProjects = [];

    /**
     * @var array<string, bool>
     */
    private array $allowedTasks = [];

    /**
     * @var array<string, bool>
     */
    private array $allowedFiles = [];

    private bool $defaultAllow;

    public function __construct(bool $defaultAllow = true)
    {
        $this->defaultAllow = $defaultAllow;
    }

    public function setProjectAccess(int $projectId, bool $allow): void
    {
        $this->allowedProjects[$projectId] = $allow;
    }

    public function setTaskAccess(int $projectId, int $taskId, bool $allow): void
    {
        $key = "{$projectId}:{$taskId}";
        $this->allowedTasks[$key] = $allow;
    }

    public function setFileAccess(int $projectId, int $taskId, int $fileId, bool $allow): void
    {
        $key = "{$projectId}:{$taskId}:{$fileId}";
        $this->allowedFiles[$key] = $allow;
    }

    public function canReadProject(int $projectId, ?int $userId = null): bool
    {
        return $this->allowedProjects[$projectId] ?? $this->defaultAllow;
    }

    public function canReadTask(int $projectId, int $taskId, ?int $userId = null): bool
    {
        $key = "{$projectId}:{$taskId}";
        if (isset($this->allowedTasks[$key])) {
            return $this->allowedTasks[$key];
        }

        return $this->canReadProject($projectId, $userId);
    }

    public function canReadFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): bool
    {
        $key = "{$projectId}:{$taskId}:{$fileId}";
        if (isset($this->allowedFiles[$key])) {
            return $this->allowedFiles[$key];
        }

        return $this->canReadTask($projectId, $taskId, $userId);
    }
}
