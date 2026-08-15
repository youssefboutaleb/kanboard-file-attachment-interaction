<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe HTML preview handler rendering .html and .htm attachments in a sandboxed container.
 */
class HtmlPreviewHandler extends AbstractPreviewHandler
{
    /**
     * Supported HTML file extensions.
     */
    private const ALLOWED_EXTENSIONS = ['html', 'htm'];

    /**
     * HTML MIME types matched exactly.
     */
    private const ALLOWED_MIME_TYPES = ['text/html'];

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        $normalizedMimeType = $this->normalizeMimeType($mimeType);

        if (in_array($normalizedExtension, self::ALLOWED_EXTENSIONS, true)) {
            return true;
        }

        return in_array($normalizedMimeType, self::ALLOWED_MIME_TYPES, true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        [$truncatedContent, $isTruncated, $originalSize] = $this->truncateContent($content);

        $lineCount = $this->countLines($truncatedContent);
        $charCount = $this->countChars($truncatedContent);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'lineCount' => $lineCount,
            'charCount' => $charCount,
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($truncatedContent),
            'truncated' => $isTruncated,
            'maxSizeBytes' => $this->maxSizeBytes,
        ];

        return new PreviewResult($truncatedContent, true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'HtmlPreviewHandler';
    }
}
