<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Memory-safe CSV parser service supporting dynamic delimiter detection and row/column limits.
 */
class CsvParserService
{
    public const DEFAULT_MAX_ROWS = 100;
    public const DEFAULT_MAX_COLUMNS = 50;
    public const CANDIDATE_DELIMITERS = [',', ';', "\t", '|'];

    private int $maxRows;
    private int $maxColumns;

    public function __construct(
        int $maxRows = self::DEFAULT_MAX_ROWS,
        int $maxColumns = self::DEFAULT_MAX_COLUMNS
    ) {
        $this->maxRows = $maxRows;
        $this->maxColumns = $maxColumns;
    }

    /**
     * Dynamically detect the field delimiter by analyzing candidate frequency in sample head lines.
     */
    public function detectDelimiter(string $content): string
    {
        if (trim($content) === '') {
            return ',';
        }

        // Extract up to 5 non-empty lines for sampling
        $sampleLines = array_filter(
            array_slice(explode("\n", str_replace("\r\n", "\n", $content)), 0, 5),
            fn($line) => trim($line) !== ''
        );

        if (empty($sampleLines)) {
            return ',';
        }

        $scores = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];

        foreach (self::CANDIDATE_DELIMITERS as $delimiter) {
            $counts = [];
            foreach ($sampleLines as $line) {
                $counts[] = substr_count($line, $delimiter);
            }

            // Score higher if delimiter occurs regularly across sample lines
            $maxCount = max($counts);
            if ($maxCount > 0) {
                $variance = count(array_unique($counts)) === 1 ? 2 : 1;
                $scores[$delimiter] = $maxCount * $variance;
            }
        }

        arsort($scores);
        $bestDelimiter = key($scores);

        return ($scores[$bestDelimiter] > 0) ? (string)$bestDelimiter : ',';
    }

    /**
     * Parse CSV string into structured array with boundary caps.
     *
     * @return array{
     *     rows: list<list<string>>,
     *     delimiter: string,
     *     totalRows: int,
     *     totalColumns: int,
     *     truncatedRows: bool,
     *     truncatedColumns: bool
     * }
     */
    public function parse(string $content, ?string $delimiter = null): array
    {
        $normalizedContent = str_replace("\r\n", "\n", trim($content));

        if ($normalizedContent === '') {
            return [
                'rows' => [],
                'delimiter' => $delimiter ?? ',',
                'totalRows' => 0,
                'totalColumns' => 0,
                'truncatedRows' => false,
                'truncatedColumns' => false,
            ];
        }

        $detectedDelimiter = $delimiter ?? $this->detectDelimiter($normalizedContent);

        // Parse content line by line using native str_getcsv
        $rawLines = explode("\n", $normalizedContent);
        $totalRawLines = count($rawLines);

        $parsedRows = [];
        $maxObservedCols = 0;
        $truncatedColumns = false;

        $lineIndex = 0;
        while ($lineIndex < $totalRawLines) {
            $line = $rawLines[$lineIndex];
            
            // Native str_getcsv parsing
            $row = str_getcsv($line, $detectedDelimiter);
            
            // Handle multiline quoted fields
            while (count($row) === 1 && isset($row[0]) && str_starts_with($row[0], '"') && !str_ends_with(trim($row[0]), '"') && ($lineIndex + 1) < $totalRawLines) {
                $lineIndex++;
                $line .= "\n" . $rawLines[$lineIndex];
                $row = str_getcsv($line, $detectedDelimiter);
            }

            $colCount = count($row);
            if ($colCount > $maxObservedCols) {
                $maxObservedCols = $colCount;
            }

            if ($colCount > $this->maxColumns) {
                $row = array_slice($row, 0, $this->maxColumns);
                $truncatedColumns = true;
            }

            // Convert null values to empty strings
            $safeRow = array_map(fn($cell) => $cell !== null ? (string)$cell : '', $row);
            $parsedRows[] = $safeRow;

            if (count($parsedRows) >= $this->maxRows) {
                break;
            }

            $lineIndex++;
        }

        $totalRows = count($parsedRows);
        $truncatedRows = $totalRawLines > $this->maxRows;

        return [
            'rows' => $parsedRows,
            'delimiter' => $detectedDelimiter,
            'totalRows' => $totalRows,
            'totalColumns' => $maxObservedCols,
            'truncatedRows' => $truncatedRows,
            'truncatedColumns' => $truncatedColumns,
        ];
    }
}
