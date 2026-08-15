<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe plain text preview handler for .txt and .md files.
 */
class TextPreviewHandler extends AbstractPreviewHandler
{
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

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        $normalizedMimeType = $this->normalizeMimeType($mimeType);

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
        [$truncatedContent, $isTruncated, $originalSize] = $this->truncateContent($content);

        // Strict HTML entity escaping to eliminate XSS risks
        $safeContent = $this->escapeHtml($truncatedContent);

        $lineCount = $this->countLines($truncatedContent);
        $charCount = $this->countChars($truncatedContent);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($truncatedContent),
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
