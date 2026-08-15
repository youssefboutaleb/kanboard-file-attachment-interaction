<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;

/**
 * Abstract base class providing common utilities for preview handlers.
 */
abstract class AbstractPreviewHandler implements FileHandlerInterface
{
    /**
     * Default maximum preview size limit in bytes (500 KB).
     */
    public const DEFAULT_MAX_SIZE_BYTES = 524288;

    protected int $maxSizeBytes;

    public function __construct(int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES)
    {
        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Get the configured maximum preview size in bytes.
     */
    public function getMaxSizeBytes(): int
    {
        return $this->maxSizeBytes;
    }

    /**
     * Normalize file extension (trimmed, lowercased, leading dot removed).
     */
    protected function normalizeExtension(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }

    /**
     * Normalize MIME type (trimmed, lowercased).
     */
    protected function normalizeMimeType(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }

    /**
     * Enforce maximum preview size by truncating content if needed.
     *
     * @return array{0: string, 1: bool, 2: int} [truncatedContent, isTruncated, originalSize]
     */
    protected function truncateContent(string $content, ?int $maxBytes = null): array
    {
        $limit = $maxBytes ?? $this->maxSizeBytes;
        $originalSize = strlen($content);

        if ($originalSize > $limit) {
            return [substr($content, 0, $limit), true, $originalSize];
        }

        return [$content, false, $originalSize];
    }

    /**
     * Count lines in string content.
     */
    protected function countLines(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        return substr_count($content, "\n") + 1;
    }

    /**
     * Count characters in UTF-8 string content.
     */
    protected function countChars(string $content): int
    {
        return mb_strlen($content, 'UTF-8');
    }

    /**
     * Safely escape plain text into HTML entities to eliminate XSS risks.
     */
    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
