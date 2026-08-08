<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;

/**
 * Registry and manager that resolves file handlers by extension and MIME type.
 */
class FileInteractionManager
{
    /**
     * @var FileHandlerInterface[]
     */
    private array $handlers = [];

    /**
     * Register a file handler.
     */
    public function registerHandler(FileHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Resolve the appropriate handler by extension, MIME type, or optional forced format.
     */
    public function resolveHandler(
        string $extension,
        string $mimeType,
        ?string $forcedFormat = null
    ): ?FileHandlerInterface {
        $normalizedExtension = strtolower(ltrim(trim($extension), '.'));
        $normalizedMimeType = strtolower(trim($mimeType));
        $normalizedFormat = $forcedFormat !== null ? strtolower(trim($forcedFormat)) : null;

        if ($normalizedFormat !== null) {
            // "text" and "raw" mean "ignore the file format and render escaped plain
            // text", so the named handler is used even when supports() would decline.
            $isPlainTextFormat = $normalizedFormat === 'text' || $normalizedFormat === 'raw';

            // Match the forced format against the handler NAME first. Matching by
            // registration order instead would hand `format=text` to whichever handler
            // happens to be registered first (e.g. CsvPreviewHandler).
            foreach ($this->handlers as $handler) {
                $name = strtolower($handler->getHandlerName());

                if (!str_contains($name, $normalizedFormat)) {
                    continue;
                }

                if ($isPlainTextFormat || $handler->supports($normalizedExtension, $normalizedMimeType)) {
                    return $handler;
                }
            }
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($normalizedExtension, $normalizedMimeType)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Get all currently registered handlers.
     *
     * @return FileHandlerInterface[]
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
}
