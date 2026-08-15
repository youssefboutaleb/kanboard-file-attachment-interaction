<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Service for converting between CSV/tabular text and OpenXML (.xlsx) spreadsheet binaries.
 */
class ExcelWriterService
{
    /**
     * Convert CSV / tabular text into OpenXML (.xlsx) binary bytes.
     */
    public function csvToXlsx(string $csvContent): string
    {
        if (trim($csvContent) === '') {
            return $this->buildXlsxFromRows([['']]);
        }

        $rows = [];
        $lines = explode("\n", $csvContent);

        foreach ($lines as $line) {
            if ($line === '' && count($rows) > 0 && end($lines) === $line) {
                continue;
            }
            $csvRow = str_getcsv($line);
            $sanitizedRow = [];
            foreach ($csvRow as $cell) {
                $sanitizedRow[] = (string) $cell;
            }
            $rows[] = $sanitizedRow;
        }

        return $this->buildXlsxFromRows($rows);
    }

    /**
     * Convert OpenXML (.xlsx) binary bytes into CSV text.
     */
    public function xlsxToCsv(string $xlsxContent): string
    {
        if (trim($xlsxContent) === '') {
            return '';
        }

        $parser = new ExcelParserService(1000, 100);
        $parsed = $parser->parseXlsxContent($xlsxContent);

        $sheets = $parsed['sheets'];
        if (empty($sheets)) {
            return '';
        }

        // Use first sheet
        $firstSheet = reset($sheets);
        $rows = $firstSheet['rows'];

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv !== false ? $csv : '';
    }

    /**
     * Build standard OpenXML (.xlsx) package from multiple sheets.
     *
     * @param array<string, list<list<string>>>|list<array{name: string, rows: list<list<string>>}> $sheetsData
     */
    public function buildXlsxFromMultiSheet(array $sheetsData): string
    {
        $normalizedSheets = [];
        foreach ($sheetsData as $key => $val) {
            $sheetName = is_string($key) && !is_numeric($key) ? $key : 'Sheet1';
            $rowsList = [];

            if (is_array($val)) {
                if (isset($val['name']) && is_string($val['name'])) {
                    $sheetName = $val['name'];
                }
                if (isset($val['rows']) && is_array($val['rows'])) {
                    $rowsList = $val['rows'];
                } else {
                    $rowsList = $val;
                }
            }

            $cleanedRows = [];
            foreach ($rowsList as $r) {
                if (is_array($r)) {
                    $cleanedRow = [];
                    foreach ($r as $cell) {
                        $cleanedRow[] = is_scalar($cell) ? (string) $cell : '';
                    }
                    $cleanedRows[] = $cleanedRow;
                }
            }

            $normalizedSheets[$sheetName] = $cleanedRows;
        }

        if (empty($normalizedSheets)) {
            $normalizedSheets = ['Sheet1' => [['']]];
        }

        $sharedStrings = [];
        $stringIndexMap = [];
        $files = [];

        $sheetId = 1;
        $typesOverrides = '';
        $workbookSheets = '';
        $workbookRels = '';

        foreach ($normalizedSheets as $sheetName => $rows) {
            $rId = 'rId' . $sheetId;
            $sheetPath = 'worksheets/sheet' . $sheetId . '.xml';
            $fullSheetPath = '/xl/' . $sheetPath;

            $typesOverrides .= '  <Override PartName="' . $fullSheetPath . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n";
            $workbookSheets .= '    <sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES, 'UTF-8') . '" sheetId="' . $sheetId . '" r:id="' . $rId . '"/>' . "\n";
            $workbookRels .= '  <Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $sheetPath . '"/>' . "\n";

            // Build worksheet XML
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
            $sheetXml .= '  <sheetData>' . "\n";

            $rowIndex = 1;
            foreach ($rows as $row) {
                $sheetXml .= '    <row r="' . $rowIndex . '">' . "\n";
                $colIndex = 0;

                foreach ($row as $cellValue) {
                    $cellValueStr = (string) $cellValue;
                    $cellRef = $this->getColumnLetter($colIndex) . $rowIndex;

                    if (is_numeric($cellValueStr) && (!str_starts_with($cellValueStr, '0') || $cellValueStr === '0')) {
                        $sheetXml .= '      <c r="' . $cellRef . '"><v>' . $cellValueStr . '</v></c>' . "\n";
                    } else {
                        if (!isset($stringIndexMap[$cellValueStr])) {
                            $idx = count($sharedStrings);
                            $sharedStrings[] = $cellValueStr;
                            $stringIndexMap[$cellValueStr] = $idx;
                        } else {
                            $idx = $stringIndexMap[$cellValueStr];
                        }

                        $sheetXml .= '      <c r="' . $cellRef . '" t="s"><v>' . $idx . '</v></c>' . "\n";
                    }

                    $colIndex++;
                }

                $sheetXml .= '    </row>' . "\n";
                $rowIndex++;
            }

            $sheetXml .= '  </sheetData>' . "\n";
            $sheetXml .= '</worksheet>';

            $files['xl/' . $sheetPath] = $sheetXml;
            $sheetId++;
        }

        // Shared strings relationship
        $sstRId = 'rId' . $sheetId;
        $workbookRels .= '  <Relationship Id="' . $sstRId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' . "\n";

        // Build shared strings XML
        $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $sstXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">' . "\n";
        foreach ($sharedStrings as $str) {
            $escaped = htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sstXml .= '  <si><t>' . $escaped . '</t></si>' . "\n";
        }
        $sstXml .= '</sst>';
        $files['xl/sharedStrings.xml'] = $sstXml;

        // [Content_Types].xml
        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n"
            . '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n"
            . '  <Default Extension="xml" ContentType="application/xml"/>' . "\n"
            . '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n"
            . $typesOverrides
            . '  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStringTable+xml"/>' . "\n"
            . '</Types>';
        $files['[Content_Types].xml'] = $contentTypesXml;

        // _rels/.rels
        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
            . '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n"
            . '</Relationships>';
        $files['_rels/.rels'] = $rootRelsXml;

        // xl/workbook.xml
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n"
            . '  <sheets>' . "\n"
            . $workbookSheets
            . '  </sheets>' . "\n"
            . '</workbook>';
        $files['xl/workbook.xml'] = $workbookXml;

        // xl/_rels/workbook.xml.rels
        $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
            . $workbookRels
            . '</Relationships>';
        $files['xl/_rels/workbook.xml.rels'] = $wbRelsXml;

        return $this->packZip($files);
    }

    /**
     * Build standard OpenXML (.xlsx) package from a list of rows.
     *
     * @param list<list<string>> $rows
     */
    public function buildXlsxFromRows(array $rows, string $sheetName = 'Sheet1'): string
    {
        return $this->buildXlsxFromMultiSheet([$sheetName => $rows]);
    }

    /**
     * Map 0-based column index to spreadsheet column letter (0 => A, 25 => Z, 26 => AA).
     */
    public function getColumnLetter(int $colIndex): string
    {
        $letter = '';
        $colIndex++;

        while ($colIndex > 0) {
            $mod = ($colIndex - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $colIndex = intdiv($colIndex - $mod, 26);
        }

        return $letter;
    }

    /**
     * Pack files into a standard PKZIP binary string.
     *
     * @param array<string, string> $files
     */
    public function packZip(array $files): string
    {
        if (class_exists('\ZipArchive')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_pack_');
            if ($tmpFile !== false) {
                $zip = new \ZipArchive();
                if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                    foreach ($files as $name => $content) {
                        $zip->addFromString($name, $content);
                    }
                    $zip->close();
                    $output = file_get_contents($tmpFile);
                    @unlink($tmpFile);
                    if ($output !== false) {
                        return $output;
                    }
                }
            }
        }

        // Pure PHP PKZIP packager fallback
        $localHeaders = '';
        $centralDirectory = '';
        $offset = 0;

        foreach ($files as $filename => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $nameLen = strlen($filename);

            // Local file header
            $localHeader = "\x50\x4b\x03\x04"
                . "\x14\x00" // version needed: 2.0
                . "\x00\x00" // flags
                . "\x00\x00" // compression method 0 (stored)
                . "\x00\x00\x00\x00" // mod time/date
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', $nameLen)
                . "\x00\x00" // extra field length
                . $filename
                . $content;

            $localHeaders .= $localHeader;

            // Central directory entry
            $cdEntry = "\x50\x4b\x01\x02"
                . "\x14\x00" // version made by
                . "\x14\x00" // version needed
                . "\x00\x00" // flags
                . "\x00\x00" // method (stored)
                . "\x00\x00\x00\x00" // mod time/date
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', $nameLen)
                . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" // extra/comment/disk/attr
                . pack('V', $offset) // relative offset
                . $filename;

            $centralDirectory .= $cdEntry;
            $offset += strlen($localHeader);
        }

        $count = count($files);
        $cdSize = strlen($centralDirectory);
        $cdOffset = strlen($localHeaders);

        // End of central directory record
        $eocd = "\x50\x4b\x05\x06"
            . "\x00\x00\x00\x00" // disk numbers
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', $cdSize)
            . pack('V', $cdOffset)
            . "\x00\x00";

        return $localHeaders . $centralDirectory . $eocd;
    }
}
