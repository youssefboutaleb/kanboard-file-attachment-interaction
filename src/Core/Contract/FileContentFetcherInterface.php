<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core\Contract;

/**
 * Interface contract for fetching raw attachment file contents.
 */
interface FileContentFetcherInterface
{
    /**
     * Retrieve raw file content by file attachment ID.
     */
    public function getFileContent(int $fileId): string;
}
