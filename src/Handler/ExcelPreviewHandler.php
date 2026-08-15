<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\ExcelParserService;

/**
 * Safe read-only spreadsheet preview handler supporting .xlsx and .xls files.
 *
 * Parsing is delegated to ExcelParserService (OpenXML/ZIP). Formulas are never
 * evaluated and macros are never executed: only cached cell values and shared
 * strings are read, then entity-escaped before reaching the template.
 */
class ExcelPreviewHandler extends AbstractPreviewHandler
{
    /**
     * Extensions claimed by this handler.
     *
     * `.xlsm` is deliberately absent: macro-enabled workbooks are outside the
     * spec 006 scope and must not be offered for preview.
     */
    private const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls'];

    /**
     * Legacy binary (BIFF) formats. These are NOT OpenXML packages, so the
     * ZIP-based parser cannot read them; they are reported as unparsed rather
     * than rendered as a silently empty grid.
     */
    private const LEGACY_EXTENSIONS = ['xls'];

    /**
     * Extensions owned by other handlers. Spreadsheet exports routinely label
     * plain .csv files as application/vnd.ms-excel, so MIME matching alone
     * would let this handler steal them depending on registration order.
     */
    private const FOREIGN_EXTENSIONS = ['csv', 'tsv', 'txt'];

    private const SPREADSHEET_MIME_FRAGMENTS = [
        'spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml',
    ];

    private ExcelParserService $parserService;

    public function __construct(
        ?ExcelParserService $parserService = null,
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES
    ) {
        parent::__construct($maxSizeBytes);
        $this->parserService = $parserService ?? new ExcelParserService();
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = $this->normalizeExtension($extension);
        $normalizedMime = $this->normalizeMimeType($mimeType);

        if (in_array($normalizedExt, self::SPREADSHEET_EXTENSIONS, true)) {
            return true;
        }

        // Never claim a file that belongs to another handler, whatever its MIME
        if (in_array($normalizedExt, self::FOREIGN_EXTENSIONS, true)) {
            return false;
        }

        foreach (self::SPREADSHEET_MIME_FRAGMENTS as $fragment) {
            if ($normalizedMime !== '' && str_contains($normalizedMime, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the escaped spreadsheet matrix and workbook metadata.
     *
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $extension = $this->normalizeExtension((string) ($options['extension'] ?? ''));
        $isLegacyFormat = in_array($extension, self::LEGACY_EXTENSIONS, true);

        // ExcelParserService guarantees the sheet shape, including an empty
        // sheets map for unparsable input, so no defensive unwrapping is needed.
        $parseResult = $this->parserService->parseXlsxContent($content);

        $sheets = [];
        $sheetNames = [];
        $anyTruncated = false;
        $activeSheet = '';

        foreach ($parseResult['sheets'] as $rawName => $sheet) {
            $safeName = $this->escapeHtml((string) $rawName);

            $truncated = $sheet['truncated'];
            $anyTruncated = $anyTruncated || $truncated;

            $sheets[$safeName] = [
                'rows' => $this->escapeMatrix($sheet['rows']),
                'rowCount' => $sheet['rowCount'],
                'columnCount' => $sheet['columnCount'],
                'truncated' => $truncated,
            ];

            $sheetNames[] = $safeName;

            // A caller-supplied sheet is matched against the RAW workbook name,
            // then exposed under its escaped form so template lookups line up.
            if (isset($options['activeSheet']) && (string) $options['activeSheet'] === (string) $rawName) {
                $activeSheet = $safeName;
            }
        }

        if ($activeSheet === '' && $sheetNames !== []) {
            $activeSheet = $sheetNames[0];
        }

        $metadata = [
            'handler' => $this->getHandlerName(),
            'sheets' => $sheets,
            'sheetCount' => count($sheetNames),
            'sheetNames' => $sheetNames,
            'activeSheet' => $activeSheet,
            'truncated' => $anyTruncated,
            'isLegacyFormat' => $isLegacyFormat,
            'parsed' => $sheetNames !== [],
        ];

        return new PreviewResult('', true, $metadata);
    }

    public function getHandlerName(): string
    {
        return 'ExcelPreviewHandler';
    }

    /**
     * Entity-escape every cell of a sheet matrix.
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function escapeMatrix(array $rows): array
    {
        return array_map(function (array $row): array {
            return array_map(function (string $cell): string {
                return $this->escapeHtml($cell);
            }, array_values($row));
        }, array_values($rows));
    }
}

