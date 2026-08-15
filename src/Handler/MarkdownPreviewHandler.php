<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\MarkdownParserService;

/**
 * Safe Markdown preview handler rendering .md and .markdown attachments as sanitized HTML.
 */
class MarkdownPreviewHandler extends AbstractPreviewHandler
{
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

    public function __construct(
        ?MarkdownParserService $parserService = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES
    ) {
        parent::__construct($maxSizeBytes);
        $this->parserService = $parserService ?? new MarkdownParserService();
    }

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
        // Enforce max preview size limit before parsing. A cut inside an open code
        // fence is safe: MarkdownParserService closes dangling fences on its own.
        [$truncatedContent, $isTruncated, $originalSize] = $this->truncateContent($content);

        $parseResult = $this->parserService->parse($truncatedContent);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'headingCount' => $parseResult['headingCount'],
            'lineCount' => $parseResult['lineCount'],
            'charCount' => $this->countChars($truncatedContent),
            'codeBlockCount' => $parseResult['codeBlockCount'],
            'originalSizeBytes' => $originalSize,
            'previewSizeBytes' => strlen($truncatedContent),
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

