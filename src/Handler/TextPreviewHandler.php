<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe plain text preview handler for .txt and .md files.
 */
class TextPreviewHandler implements FileHandlerInterface
{
    /**
     * Default maximum preview size limit in bytes (500 KB).
     */
    public const DEFAULT_MAX_SIZE_BYTES = 524288;

    /**
     * Allowed file extensions for safe plain text preview.
     */
    private const ALLOWED_EXTENSIONS = [
        'txt', 'text', 'md', 'markdown', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm'
    ];

    /**
     * Explicitly forbidden executable/dangerous extensions.
     */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
        'js', 'mjs', 'svg', 'exe', 'sh', 'bash', 'bat', 'cmd', 'py', 'pl', 'cgi', 'dll', 'so'
    ];

    private int $maxSizeBytes;

    public function __construct(int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES)
    {
        $this->maxSizeBytes = $maxSizeBytes;
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExtension = strtolower(ltrim(trim($extension), '.'));
        $normalizedMimeType = strtolower(trim($mimeType));

        // Rejection rule: If extension is explicitly forbidden, reject immediately
        if (in_array($normalizedExtension, self::FORBIDDEN_EXTENSIONS, true)) {
            return false;
        }

        // Support rule: Match allowed extensions or text/* MIME types (excluding html/svg)
        if (in_array($normalizedExtension, self::ALLOWED_EXTENSIONS, true)) {
            return true;
        }

        return str_starts_with($normalizedMimeType, 'text/')
            && !str_contains($normalizedMimeType, 'svg');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $isTruncated = false;
        $originalSize = strlen($content);

        // Enforce max preview size limit
        if ($originalSize > $this->maxSizeBytes) {
            $content = substr($content, 0, $this->maxSizeBytes);
            $isTruncated = true;
        }

        // Strict HTML entity escaping to eliminate XSS risks
        $safeContent = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lineCount = empty($content) ? 0 : substr_count($content, "\n") + 1;
        $charCount = mb_strlen($content, 'UTF-8');

        $metadata = [
            'handler' => $this->getHandlerName(),
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($content),
            'lineCount' => $lineCount,
            'charCount' => $charCount,
            'truncated' => $isTruncated,
            'maxSizeBytes' => $this->maxSizeBytes,
        ];

        return new PreviewResult($safeContent, false, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'TextPreviewHandler';
    }
}
