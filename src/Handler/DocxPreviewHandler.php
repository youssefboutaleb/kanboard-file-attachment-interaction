<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\DocxParserService;

/**
 * Safe read-only Word document preview handler supporting .docx, .dotx, and .doc files.
 *
 * Parsing is delegated to DocxParserService (OpenXML/ZIP). Macros and active content
 * are never executed; all text runs and table contents are strictly HTML-escaped.
 */
class DocxPreviewHandler implements FileHandlerInterface
{
    private const DOCX_EXTENSIONS = ['docx', 'dotx', 'doc'];

    private const LEGACY_EXTENSIONS = ['doc'];

    private const FOREIGN_EXTENSIONS = ['txt', 'csv', 'tsv', 'json', 'pdf', 'xlsx', 'pptx'];

    private const DOCX_MIME_FRAGMENTS = [
        'wordprocessingml.document',
        'wordprocessingml.template',
        'application/msword',
        'application/vnd.ms-word',
        'application/x-msword',
    ];

    private DocxParserService $parserService;

    public function __construct(?DocxParserService $parserService = null)
    {
        $this->parserService = $parserService ?? new DocxParserService();
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = strtolower(ltrim(trim($extension), '.'));
        $normalizedMime = strtolower(trim($mimeType));

        if (in_array($normalizedExt, self::DOCX_EXTENSIONS, true)) {
            return true;
        }

        if (in_array($normalizedExt, self::FOREIGN_EXTENSIONS, true)) {
            return false;
        }

        foreach (self::DOCX_MIME_FRAGMENTS as $fragment) {
            if ($normalizedMime !== '' && str_contains($normalizedMime, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the escaped document view and metadata.
     *
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $extension = strtolower(ltrim(trim((string) ($options['extension'] ?? '')), '.'));
        $isLegacyFormat = in_array($extension, self::LEGACY_EXTENSIONS, true);

        if ($isLegacyFormat) {
            $metadata = [
                'handler' => $this->getHandlerName(),
                'paragraphCount' => 0,
                'headingCount' => 0,
                'tableCount' => 0,
                'wordCount' => 0,
                'isLegacyFormat' => true,
                'parsed' => false,
            ];

            return new PreviewResult('', true, $metadata);
        }

        $parseResult = $this->parserService->parseDocxContent($content);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'paragraphCount' => $parseResult['paragraphCount'],
            'headingCount' => $parseResult['headingCount'],
            'tableCount' => $parseResult['tableCount'],
            'wordCount' => $parseResult['wordCount'],
            'isLegacyFormat' => false,
            'parsed' => $parseResult['parsed'],
        ];

        return new PreviewResult($parseResult['html'], true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'DocxPreviewHandler';
    }
}
