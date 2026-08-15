<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe HTML preview handler rendering .html and .htm attachments in a sandboxed container.
 */
class HtmlPreviewHandler implements FileHandlerInterface
{
    /**
     * Default maximum preview size limit in bytes (500 KB).
     */
    public const DEFAULT_MAX_SIZE_BYTES = 524288;

    /**
     * Supported HTML file extensions.
     */
    private const ALLOWED_EXTENSIONS = ['html', 'htm'];

    /**
     * HTML MIME types matched exactly.
     */
    private const ALLOWED_MIME_TYPES = ['text/html'];

    private int $maxSizeBytes;

    public function __construct(int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES)
    {
        $this->maxSizeBytes = $maxSizeBytes;
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExtension = strtolower(ltrim(trim($extension), '.'));
        $normalizedMimeType = strtolower(trim($mimeType));

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
        $isTruncated = false;
        $originalSize = strlen($content);

        if ($originalSize > $this->maxSizeBytes) {
            $content = substr($content, 0, $this->maxSizeBytes);
            $isTruncated = true;
        }

        $lineCount = substr_count($content, "\n") + (strlen($content) > 0 ? 1 : 0);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'lineCount' => $lineCount,
            'charCount' => mb_strlen($content, 'UTF-8'),
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($content),
            'truncated' => $isTruncated,
            'maxSizeBytes' => $this->maxSizeBytes,
        ];

        return new PreviewResult($content, true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'HtmlPreviewHandler';
    }
}
