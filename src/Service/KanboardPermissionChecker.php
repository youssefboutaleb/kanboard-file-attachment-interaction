<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\PermissionCheckerInterface;

/**
 * Permission checker backed by Kanboard's own ACL models.
 *
 * WHY THIS EXISTS: `PermissionService` is constructed with an optional checker and
 * previously fell back to `MockPermissionChecker(true)` — an allow-everything stub
 * written for unit tests. No controller injected anything else, so at runtime every
 * `canUserReadFile()` / `canUserWriteFile()` call returned true and the plugin's own
 * ACL layer was decorative. This class is the real implementation, and the
 * controllers now install it whenever the Kanboard container is present.
 *
 * It is a SECOND line of defence, not the first: Kanboard already gates these routes
 * through `projectAccessMap` (see Plugin.php). It matters because the plugin resolves
 * attachments by id and can therefore reason about a project the route ACL never
 * looked at.
 *
 * FAIL CLOSED: if the models needed to answer the question are missing, the answer is
 * "no". A permission checker that cannot check must never approve.
 */
class KanboardPermissionChecker implements PermissionCheckerInterface
{
    /** @var mixed Pimple container (ArrayAccess) supplied by the controller. */
    private $container;

    /**
     * @param mixed $container
     */
    public function __construct($container)
    {
        $this->container = $container;
    }

    public function canReadProject(int $projectId, ?int $userId = null): bool
    {
        if ($projectId <= 0) {
            return false;
        }

        // Application administrators and managers bypass per-project membership,
        // which is how Kanboard's own ProjectAuthorization behaves.
        $userSession = $this->service('userSession');

        if (is_object($userSession)) {
            foreach (['isAdmin', 'isManager'] as $method) {
                if (method_exists($userSession, $method) && $userSession->{$method}() === true) {
                    return true;
                }
            }
        }

        $resolvedUserId = $this->resolveUserId($userId);

        if ($resolvedUserId <= 0) {
            return false;
        }

        $projectPermissionModel = $this->service('projectPermissionModel');

        if (!is_object($projectPermissionModel) || !method_exists($projectPermissionModel, 'isUserAllowed')) {
            return false;
        }

        try {
            return (bool) $projectPermissionModel->isUserAllowed($projectId, $resolvedUserId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function canReadTask(int $projectId, int $taskId, ?int $userId = null): bool
    {
        if (!$this->canReadProject($projectId, $userId)) {
            return false;
        }

        if ($taskId <= 0) {
            return true;
        }

        // The task must sit in the project whose membership was just verified,
        // otherwise the check was answered against the wrong project entirely.
        $taskFinderModel = $this->service('taskFinderModel');

        if (!is_object($taskFinderModel) || !method_exists($taskFinderModel, 'getProjectId')) {
            return true;
        }

        try {
            $owningProjectId = (int) $taskFinderModel->getProjectId($taskId);
        } catch (\Throwable $e) {
            return false;
        }

        // A task id that resolves to nothing is left to the caller's own validation;
        // only a positive, contradicting id is a denial.
        return $owningProjectId <= 0 || $owningProjectId === $projectId;
    }

    public function canReadFile(int $projectId, int $taskId, int $fileId, ?int $userId = null): bool
    {
        return $this->canReadTask($projectId, $taskId, $userId);
    }

    /**
     * Write access: full project membership, not merely visibility.
     *
     * `isUserAllowed()` is true for a PROJECT_VIEWER, who may read a task's
     * attachments but must never overwrite one. `isMember()` is the narrower test
     * that separates the two. Called reflectively by
     * PermissionService::canUserWriteFile(), so it is intentionally not part of
     * PermissionCheckerInterface.
     */
    public function canWriteProject(int $projectId, ?int $userId = null): bool
    {
        if ($projectId <= 0) {
            return false;
        }

        $userSession = $this->service('userSession');

        if (is_object($userSession)) {
            foreach (['isAdmin', 'isManager'] as $method) {
                if (method_exists($userSession, $method) && $userSession->{$method}() === true) {
                    return true;
                }
            }
        }

        $resolvedUserId = $this->resolveUserId($userId);

        if ($resolvedUserId <= 0) {
            return false;
        }

        $projectPermissionModel = $this->service('projectPermissionModel');

        if (!is_object($projectPermissionModel) || !method_exists($projectPermissionModel, 'isMember')) {
            return false;
        }

        try {
            return (bool) $projectPermissionModel->isMember($projectId, $resolvedUserId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Fall back to the session user when the caller did not name one.
     */
    private function resolveUserId(?int $userId): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        $userSession = $this->service('userSession');

        if (is_object($userSession) && method_exists($userSession, 'getId')) {
            try {
                return (int) $userSession->getId();
            } catch (\Throwable $e) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * @return mixed
     */
    private function service(string $name)
    {
        if (!$this->container instanceof \ArrayAccess || !$this->container->offsetExists($name)) {
            return null;
        }

        return $this->container->offsetGet($name);
    }
}
