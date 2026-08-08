<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\MarkdownParserService;

/**
 * Safe Markdown preview handler rendering .md and .markdown attachments as sanitized HTML.
 */
class MarkdownPreviewHandler implements FileHandlerInterface
{
    /**
     * Default maximum preview size limit in bytes (500 KB).
     */
    public const DEFAULT_MAX_SIZE_BYTES = 524288;

    /**
     * Supported Markdown file extensions.
     */
    private const ALLOWED_EXTENSIONS = ['md', 'markdown'];

    /**
     * Markdown MIME types matched exactly.
     *
     * Generic text/* types are deliberately NOT matched here: TextPreviewHandler
     * already claims every text/* MIME type, so a loose match would make handler
     * resolution depend on registration order alone.
     */
    private const ALLOWED_MIME_TYPES = ['text/markdown', 'text/x-markdown'];

    private MarkdownParserService $parserService;

    private int $maxSizeBytes;

    public function __construct(
        ?MarkdownParserService $parserService = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES
    ) {
        $this->parserService = $parserService ?? new MarkdownParserService();
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

        // Enforce max preview size limit before parsing. A cut inside an open code
        // fence is safe: MarkdownParserService closes dangling fences on its own.
        if ($originalSize > $this->maxSizeBytes) {
            $content = substr($content, 0, $this->maxSizeBytes);
            $isTruncated = true;
        }

        $parseResult = $this->parserService->parse($content);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'headingCount' => $parseResult['headingCount'],
            'lineCount' => $parseResult['lineCount'],
            'charCount' => mb_strlen($content, 'UTF-8'),
            'codeBlockCount' => $parseResult['codeBlockCount'],
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($content),
            'truncated' => $isTruncated,
            'maxSizeBytes' => $this->maxSizeBytes,
        ];

        // The parser guarantees entity-escaped text nodes and sanitized link
        // schemes, so the resulting HTML is safe to render unescaped.
        return new PreviewResult($parseResult['html'], true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'MarkdownPreviewHandler';
    }
}
