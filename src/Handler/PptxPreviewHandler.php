<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\PptxParserService;

/**
 * Safe read-only PowerPoint presentation preview handler supporting .pptx, .potx, and .ppt files.
 *
 * Parsing is delegated to PptxParserService (OpenXML/ZIP). Macros and active content
 * are never executed; all slide titles, bullet points, and tables are strictly HTML-escaped.
 */
class PptxPreviewHandler extends AbstractPreviewHandler
{
    private const PPTX_EXTENSIONS = ['pptx', 'potx', 'ppt'];

    private const LEGACY_EXTENSIONS = ['ppt'];

    private const FOREIGN_EXTENSIONS = ['txt', 'csv', 'tsv', 'json', 'pdf', 'xlsx', 'docx'];

    private const PPTX_MIME_FRAGMENTS = [
        'presentationml.presentation',
        'presentationml.template',
        'application/vnd.ms-powerpoint',
        'application/mspowerpoint',
        'application/powerpoint',
    ];

    private PptxParserService $parserService;

    public function __construct(
        ?PptxParserService $parserService = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES
    ) {
        parent::__construct($maxSizeBytes);
        $this->parserService = $parserService ?? new PptxParserService();
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = $this->normalizeExtension($extension);
        $normalizedMime = $this->normalizeMimeType($mimeType);

        if (in_array($normalizedExt, self::PPTX_EXTENSIONS, true)) {
            return true;
        }

        if (in_array($normalizedExt, self::FOREIGN_EXTENSIONS, true)) {
            return false;
        }

        foreach (self::PPTX_MIME_FRAGMENTS as $fragment) {
            if ($normalizedMime !== '' && str_contains($normalizedMime, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the structured presentation slide deck and metadata.
     *
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $extension = $this->normalizeExtension((string) ($options['extension'] ?? ''));
        $isLegacyFormat = in_array($extension, self::LEGACY_EXTENSIONS, true);

        if ($isLegacyFormat) {
            $metadata = [
                'handler' => $this->getHandlerName(),
                'slides' => [],
                'slideCount' => 0,
                'title' => '',
                'isLegacyFormat' => true,
                'parsed' => false,
            ];

            return new PreviewResult('', true, $metadata);
        }

        $parseResult = $this->parserService->parsePptxContent($content);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'slides' => $parseResult['slides'],
            'slideCount' => $parseResult['slideCount'],
            'title' => $parseResult['title'],
            'isLegacyFormat' => false,
            'parsed' => $parseResult['parsed'],
        ];

        return new PreviewResult('', true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'PptxPreviewHandler';
    }
}
