<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

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
        if (trim($zipContent) === "") {
            return ['sheets' => [], 'sheetNames' => []];
        }

        $files = $this->extractZipFiles($zipContent);
        if (empty($files)) {
            return ['sheets' => [], 'sheetNames' => []];
        }

        // 1. Extract shared strings
        $sharedStrings = $this->extractSharedStringsFromXml($files['xl/sharedStrings.xml'] ?? null);

        // 2. Extract sheet names from workbook.xml
        $sheetMap = $this->extractSheetMapFromXml($files['xl/workbook.xml'] ?? null);

        $sheets = [];
        $sheetNames = [];

        foreach ($sheetMap as $sheetId => $sheetName) {
            $sheetPath = 'xl/worksheets/sheet' . $sheetId . '.xml';
            $sheetXml = $files[$sheetPath] ?? null;

            if ($sheetXml === null) {
                // Try fallback matching filename if numbered sheet path doesn't match ID
                $sheetXml = $files['xl/worksheets/' . strtolower((string) $sheetName) . '.xml'] ?? null;
            }

            if ($sheetXml !== null) {
                $sheetData = $this->parseWorksheetXml($sheetXml, $sharedStrings);
                $sheets[$sheetName] = $sheetData;
                $sheetNames[] = $sheetName;
            }
        }

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
     * Extract files from ZIP archive content using ZipArchive or pure-PHP unpacker.
     *
     * @return array<string, string>
     */
    public function extractZipFiles(string $zipContent): array
    {
        $files = [];

        if (class_exists('\ZipArchive')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_read_');
            if ($tmpFile !== false) {
                file_put_contents($tmpFile, $zipContent);
                $zip = new \ZipArchive();
                if ($zip->open($tmpFile) === true) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if ($name !== false) {
                            $content = $zip->getFromIndex($i);
                            if ($content !== false) {
                                $files[$name] = $content;
                            }
                        }
                    }
                    $zip->close();
                }
                @unlink($tmpFile);
                if (!empty($files)) {
                    return $files;
                }
            }
        }

        // Pure PHP ZIP unpacker
        $len = strlen($zipContent);
        $p = 0;

        while ($p + 30 <= $len) {
            if (substr($zipContent, $p, 4) !== "\x50\x4b\x03\x04") {
                break;
            }

            $compUnpack = unpack('v', substr($zipContent, $p + 8, 2));
            $compSizeUnpack = unpack('V', substr($zipContent, $p + 18, 4));
            $nameLenUnpack = unpack('v', substr($zipContent, $p + 26, 2));
            $extraLenUnpack = unpack('v', substr($zipContent, $p + 28, 2));

            if (!$compUnpack || !$compSizeUnpack || !$nameLenUnpack || !$extraLenUnpack) {
                break;
            }

            $compression = $compUnpack[1];
            $compSize = $compSizeUnpack[1];
            $nameLen = $nameLenUnpack[1];
            $extraLen = $extraLenUnpack[1];

            $name = substr($zipContent, $p + 30, $nameLen);
            $dataOffset = $p + 30 + $nameLen + $extraLen;

            if ($dataOffset + $compSize > $len) {
                break;
            }

            $raw = substr($zipContent, $dataOffset, $compSize);

            if ($compression === 0) {
                $files[$name] = $raw;
            } elseif ($compression === 8 && function_exists('gzinflate')) {
                $inflated = @gzinflate($raw);
                if ($inflated !== false) {
                    $files[$name] = $inflated;
                }
            }

            $p = $dataOffset + $compSize;
        }

        return $files;
    }

    /**
     * Parse shared strings XML into string lookup array.
     *
     * @return list<string>
     */
    private function extractSharedStringsFromXml(?string $xmlContent): array
    {
        if ($xmlContent === null || trim($xmlContent) === "") {
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
                $text = "";
                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            } else {
                $strings[] = "";
            }
        }

        return $strings;
    }

    /**
     * Extract sheet ID => Sheet Name map from workbook.xml.
     *
     * @return array<int|string, string>
     */
    private function extractSheetMapFromXml(?string $xmlContent): array
    {
        if ($xmlContent === null || trim($xmlContent) === "") {
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
                $val = isset($cell->v) ? (string) $cell->v : "";

                if ($type === 's' && is_numeric($val)) {
                    $stringIdx = (int) $val;
                    $cellVal = $sharedStrings[$stringIdx] ?? $val;
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $cellVal = (string) $cell->is->t;
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
