<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\CsvParserService;

/**
 * Safe CSV read-only table preview handler supporting .csv and .tsv files.
 */
class CsvPreviewHandler implements FileHandlerInterface
{
    private CsvParserService $parserService;

    public function __construct(?CsvParserService $parserService = null)
    {
        $this->parserService = $parserService ?? new CsvParserService();
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = strtolower(ltrim(trim($extension), '.'));
        $normalizedMime = strtolower(trim($mimeType));

        if (in_array($normalizedExt, ['csv', 'tsv'], true)) {
            return true;
        }

        return str_contains($normalizedMime, 'csv') || str_contains($normalizedMime, 'tab-separated-values');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $parseResult = $this->parserService->parse($content);

        // Escape every cell to guarantee XSS safety
        $escapedRows = array_map(function (array $row): array {
            return array_map(function (string $cell): string {
                return htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }, $row);
        }, $parseResult['rows']);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'delimiter' => $parseResult['delimiter'],
            'totalRows' => $parseResult['totalRows'],
            'totalColumns' => $parseResult['totalColumns'],
            'truncatedRows' => $parseResult['truncatedRows'],
            'truncatedColumns' => $parseResult['truncatedColumns'],
            'rows' => $escapedRows,
        ];

        return new PreviewResult('', true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'CsvPreviewHandler';
    }
}
