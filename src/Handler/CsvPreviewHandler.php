<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\CsvDelimiterRegistry;
use Kanboard\Plugin\FileInteractionCore\Service\CsvParserService;

/**
 * Safe CSV read-only table preview handler supporting .csv and .tsv files.
 */
class CsvPreviewHandler implements FileHandlerInterface
{
    private CsvParserService $parserService;
    private CsvDelimiterRegistry $delimiterRegistry;

    public function __construct(
        ?CsvParserService $parserService = null,
        ?CsvDelimiterRegistry $delimiterRegistry = null
    ) {
        $this->parserService = $parserService ?? new CsvParserService();
        $this->delimiterRegistry = $delimiterRegistry ?? new CsvDelimiterRegistry();
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
     * @param array<string, mixed> $options Accepts `delimiterToken` — a token from
     *                                      CsvDelimiterRegistry naming the
     *                                      delimiter the user picked. Absent or
     *                                      `auto` keeps the sniffer in charge.
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $requestedToken = $this->delimiterRegistry->normalizeToken(
            isset($options['delimiterToken']) ? (string) $options['delimiterToken'] : null
        );

        // null hands the decision back to CsvParserService::parse(), which sniffs.
        $parseResult = $this->parserService->parse(
            $content,
            $this->delimiterRegistry->resolveDelimiter($requestedToken)
        );

        // Escape every cell to guarantee XSS safety
        $escapedRows = array_map(function (array $row): array {
            return array_map(function (string $cell): string {
                return htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }, $row);
        }, $parseResult['rows']);

        $metadata = [
            'handler' => $this->getHandlerName(),
            'delimiter' => $parseResult['delimiter'],
            // The token actually in effect: the user's choice, or the one the
            // sniffer settled on, so the picker can show the real selection.
            'delimiterToken' => $requestedToken === CsvDelimiterRegistry::AUTO
                ? $this->delimiterRegistry->getTokenForDelimiter($parseResult['delimiter'])
                : $requestedToken,
            'delimiterMode' => $requestedToken,
            'delimiterLabel' => $this->delimiterRegistry->getDelimiterLabel($parseResult['delimiter']),
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
