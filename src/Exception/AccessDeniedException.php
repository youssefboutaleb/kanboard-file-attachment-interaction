<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Exception;

use Exception;

/**
 * Exception thrown when a user fails ACL permission checks.
 */
class AccessDeniedException extends Exception
{
}
