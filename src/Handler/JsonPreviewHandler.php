<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe JSON preview handler with validation, pretty printing, and XSS escaping.
 */
class JsonPreviewHandler implements FileHandlerInterface
{
    /**
     * Default maximum preview size limit in bytes (500 KB).
     */
    public const DEFAULT_MAX_SIZE_BYTES = 524288;

    /**
     * Maximum JSON parsing depth to prevent stack exhaustion DoS.
     */
    private const MAX_PARSING_DEPTH = 512;

    private int $maxSizeBytes;

    public function __construct(int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES)
    {
        $this->maxSizeBytes = $maxSizeBytes;
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExtension = strtolower(ltrim(trim($extension), '.'));
        $normalizedMimeType = strtolower(trim($mimeType));

        if ($normalizedExtension === 'json') {
            return true;
        }

        return $normalizedMimeType === 'application/json' || $normalizedMimeType === 'text/json';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $isTruncated = false;
        $originalSize = strlen($content);

        // Enforce maximum size limit before processing
        if ($originalSize > $this->maxSizeBytes) {
            $content = substr($content, 0, $this->maxSizeBytes);
            $isTruncated = true;
        }

        $isValidJson = false;
        $errorMessage = null;
        $formattedText = '';

        // Attempt JSON validation and pretty printing
        try {
            $decoded = json_decode($content, true, self::MAX_PARSING_DEPTH, JSON_THROW_ON_ERROR);
            $isValidJson = true;
            $formattedText = json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($formattedText === false) {
                $isValidJson = false;
                $errorMessage = 'Failed to format JSON payload.';
                $formattedText = $content;
            }
        } catch (\JsonException $e) {
            $isValidJson = false;
            $errorMessage = 'Invalid JSON: ' . $e->getMessage();
            $formattedText = "[JSON Validation Error: " . $e->getMessage() . "]\n\n" . $content;
        }

        // Strict HTML entity escaping to neutralize XSS payloads embedded inside JSON values
        $safeContent = htmlspecialchars($formattedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lineCount = empty($formattedText) ? 0 : substr_count($formattedText, "\n") + 1;
        $charCount = mb_strlen($formattedText, 'UTF-8');

        $metadata = [
            'handler' => $this->getHandlerName(),
            'validJson' => $isValidJson,
            'errorMessage' => $errorMessage,
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($formattedText),
            'lineCount' => $lineCount,
            'charCount' => $charCount,
            'truncated' => $isTruncated,
            'maxSizeBytes' => $this->maxSizeBytes,
        ];

        return new PreviewResult($safeContent, $isValidJson, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'JsonPreviewHandler';
    }
}
