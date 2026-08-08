<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core\Contract;

/**
 * Interface contract for persisting edited attachment content.
 *
 * Mirrors the read-side FileContentFetcherInterface. Inside the Kanboard
 * runtime these map onto verified core APIs:
 *   - writeRevision()   -> FileModel::uploadContent($taskId, $filename, $data, false)
 *   - overwriteContent() -> ObjectStorage::put($path, $data)
 */
interface FileRevisionWriterInterface
{
    /**
     * Persist content as a NEW attachment under the given (already versioned) filename.
     *
     * @return bool True when the revision was stored.
     */
    public function writeRevision(string $filename, string $content): bool;

    /**
     * Replace the content of an EXISTING attachment in place.
     *
     * @return bool True when the attachment was overwritten.
     */
    public function overwriteContent(string $filename, string $content): bool;
}
