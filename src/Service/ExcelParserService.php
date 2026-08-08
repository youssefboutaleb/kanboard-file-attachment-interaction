<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use ZipArchive;

/**
 * Lightweight OpenXML (.xlsx) memory-safe spreadsheet parser service.
 */
class ExcelParserService
{
    private int $maxRows;
    private int $maxColumns;

    public function __construct(int $maxRows = 100, int $maxColumns = 50)
    {
        $this->maxRows = $maxRows;
        $this->maxColumns = $maxColumns;
    }

    /**
     * Parse raw binary content of a .xlsx file.
     *
     * @return array{sheets: array<string, array{rows: list<list<string>>, rowCount: int, columnCount: int, truncated: bool}>, sheetNames: list<string>}
     */
    public function parseXlsxContent(string $zipContent): array
    {
        if (trim($zipContent) === '' || !class_exists('ZipArchive')) {
            return ['sheets' => [], 'sheetNames' => []];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmpFile === false) {
            return ['sheets' => [], 'sheetNames' => []];
        }

        file_put_contents($tmpFile, $zipContent);

        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            unlink($tmpFile);
            return ['sheets' => [], 'sheetNames' => []];
        }

        // 1. Extract shared strings
        $sharedStrings = $this->extractSharedStrings($zip);

        // 2. Extract sheet names from workbook.xml
        $sheetMap = $this->extractSheetMap($zip);

        $sheets = [];
        $sheetNames = [];

        foreach ($sheetMap as $sheetId => $sheetName) {
            $sheetPath = 'xl/worksheets/sheet' . $sheetId . '.xml';
            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                // Try fallback matching filename if numbered sheet path doesn't match ID
                $sheetXml = $zip->getFromName('xl/worksheets/' . strtolower($sheetName) . '.xml');
            }

            if ($sheetXml !== false) {
                $sheetData = $this->parseWorksheetXml($sheetXml, $sharedStrings);
                $sheets[$sheetName] = $sheetData;
                $sheetNames[] = $sheetName;
            }
        }

        $zip->close();
        unlink($tmpFile);

        // Fallback default sheet if no named sheets matched
        if (empty($sheets)) {
            $sheets['Sheet1'] = [
                'rows' => [],
                'rowCount' => 0,
                'columnCount' => 0,
                'truncated' => false,
            ];
            $sheetNames = ['Sheet1'];
        }

        return [
            'sheets' => $sheets,
            'sheetNames' => $sheetNames,
        ];
    }

    /**
     * Parse shared strings XML into string lookup array.
     *
     * @return list<string>
     */
    private function extractSharedStrings(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return [];
        }

        $strings = [];
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            return [];
        }

        foreach ($xml->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
            } elseif (isset($item->r)) {
                $text = '';
                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            } else {
                $strings[] = '';
            }
        }

        return $strings;
    }

    /**
     * Extract sheet ID => Sheet Name map from workbook.xml.
     *
     * @return array<int|string, string>
     */
    private function extractSheetMap(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/workbook.xml');
        if ($xmlContent === false) {
            return [1 => 'Sheet1'];
        }

        $map = [];
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false || !isset($xml->sheets->sheet)) {
            return [1 => 'Sheet1'];
        }

        $index = 1;
        foreach ($xml->sheets->sheet as $sheet) {
            $name = (string) $sheet['name'];
            $map[$index] = !empty($name) ? $name : ('Sheet' . $index);
            $index++;
        }

        return $map;
    }

    /**
     * Parse single worksheet XML.
     *
     * @param list<string> $sharedStrings
     * @return array{rows: list<list<string>>, rowCount: int, columnCount: int, truncated: bool}
     */
    private function parseWorksheetXml(string $xmlContent, array $sharedStrings): array
    {
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false || !isset($xml->sheetData->row)) {
            return [
                'rows' => [],
                'rowCount' => 0,
                'columnCount' => 0,
                'truncated' => false,
            ];
        }

        $rows = [];
        $rawRowCount = count($xml->sheetData->row);
        $maxColsFound = 0;
        $truncated = false;

        $rowIndex = 0;
        foreach ($xml->sheetData->row as $rowNode) {
            $rowIndex++;
            if ($rowIndex > $this->maxRows) {
                $truncated = true;
                break;
            }

            $rowCells = [];
            $colIndex = 0;

            foreach ($rowNode->c as $cell) {
                $colIndex++;
                if ($colIndex > $this->maxColumns) {
                    $truncated = true;
                    break;
                }

                $type = (string) $cell['t'];
                $val = isset($cell->v) ? (string) $cell->v : '';

                if ($type === 's' && is_numeric($val)) {
                    $stringIdx = (int) $val;
                    $cellVal = $sharedStrings[$stringIdx] ?? $val;
                } else {
                    $cellVal = $val;
                }

                $rowCells[] = $cellVal;
            }

            if (count($rowCells) > $maxColsFound) {
                $maxColsFound = count($rowCells);
            }

            $rows[] = $rowCells;
        }

        return [
            'rows' => $rows,
            'rowCount' => $rawRowCount,
            'columnCount' => $maxColsFound,
            'truncated' => $truncated,
        ];
    }
}
