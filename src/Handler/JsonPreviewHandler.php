<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe JSON preview handler with validation, pretty printing, and XSS escaping.
 */
class JsonPreviewHandler extends AbstractPreviewHandler
{
    /**
     * Maximum JSON parsing depth to prevent stack exhaustion DoS.
     */
    private const MAX_PARSING_DEPTH = 512;

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExtension = $this->normalizeExtension($extension);
        $normalizedMimeType = $this->normalizeMimeType($mimeType);

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
        // Enforce maximum size limit before processing
        [$truncatedContent, $isTruncated, $originalSize] = $this->truncateContent($content);

        $isValidJson = false;
        $errorMessage = null;
        $formattedText = '';

        // Attempt JSON validation and pretty printing
        try {
            $decoded = json_decode($truncatedContent, true, self::MAX_PARSING_DEPTH, JSON_THROW_ON_ERROR);
            $isValidJson = true;
            $formattedText = json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($formattedText === false) {
                $isValidJson = false;
                $errorMessage = 'Failed to format JSON payload.';
                $formattedText = $truncatedContent;
            }
        } catch (\JsonException $e) {
            $isValidJson = false;
            $errorMessage = 'Invalid JSON: ' . $e->getMessage();
            $formattedText = "[JSON Validation Error: " . $e->getMessage() . "]\n\n" . $truncatedContent;
        }

        // Strict HTML entity escaping to neutralize XSS payloads embedded inside JSON values
        $safeContent = $this->escapeHtml($formattedText);

        $lineCount = $this->countLines($formattedText);
        $charCount = $this->countChars($formattedText);

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
