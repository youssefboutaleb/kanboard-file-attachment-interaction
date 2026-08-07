<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core\Contract;

/**
 * Interface contract for all file format handlers.
 */
interface FileHandlerInterface
{
    /**
     * Check if this handler supports the given file extension and MIME type.
     */
    public function supports(string $extension, string $mimeType): bool;

    /**
     * Generate a safe preview result from raw file content.
     *
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult;

    /**
     * Get the human-readable handler name.
     */
    public function getHandlerName(): string;
}
