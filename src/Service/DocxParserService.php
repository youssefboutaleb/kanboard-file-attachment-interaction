<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * Memory-safe OpenXML (.docx) Word document parser service.
 *
 * Extracts structured headings, styled paragraphs, lists, and tables into
 * safe, pre-escaped HTML suitable for rendering in the reading pane.
 */
class DocxParserService
{
    private int $maxParagraphs;
    private int $maxTables;

    public function __construct(int $maxParagraphs = 500, int $maxTables = 50)
    {
        $this->maxParagraphs = $maxParagraphs;
        $this->maxTables = $maxTables;
    }

    /**
     * Parse raw binary content of a .docx file.
     *
     * @return array{
     *     html: string,
     *     paragraphCount: int,
     *     headingCount: int,
     *     tableCount: int,
     *     wordCount: int,
     *     parsed: bool
     * }
     */
    public function parseDocxContent(string $zipContent): array
    {
        if (trim($zipContent) === '') {
            return [
                'html' => '',
                'paragraphCount' => 0,
                'headingCount' => 0,
                'tableCount' => 0,
                'wordCount' => 0,
                'parsed' => false,
            ];
        }

        $files = $this->extractZipFiles($zipContent);
        if (empty($files) || !isset($files['word/document.xml'])) {
            return [
                'html' => '',
                'paragraphCount' => 0,
                'headingCount' => 0,
                'tableCount' => 0,
                'wordCount' => 0,
                'parsed' => false,
            ];
        }

        return $this->parseDocumentXml($files['word/document.xml']);
    }

    /**
     * Parse word/document.xml into semantic HTML.
     *
     * @return array{
     *     html: string,
     *     paragraphCount: int,
     *     headingCount: int,
     *     tableCount: int,
     *     wordCount: int,
     *     parsed: bool
     * }
     */
    private function parseDocumentXml(string $xml): array
    {
        $dom = new DOMDocument();
        // Secure XML parsing: disable entity resolution and external network access
        $previousEntityLoader = false;
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = @libxml_disable_entity_loader(true);
        }

        $options = \LIBXML_NONET | \LIBXML_NOBLANKS;
        if (\defined('LIBXML_NOENT')) {
            $options |= \LIBXML_NOENT;
        }

        $loaded = @$dom->loadXML($xml, $options);

        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            @libxml_disable_entity_loader($previousEntityLoader);
        }

        if (!$loaded) {
            return [
                'html' => '',
                'paragraphCount' => 0,
                'headingCount' => 0,
                'tableCount' => 0,
                'wordCount' => 0,
                'parsed' => false,
            ];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $bodyNodes = $xpath->query('//w:body/*');
        if ($bodyNodes === false || $bodyNodes->length === 0) {
            return [
                'html' => '',
                'paragraphCount' => 0,
                'headingCount' => 0,
                'tableCount' => 0,
                'wordCount' => 0,
                'parsed' => true,
            ];
        }

        $html = '';
        $paragraphCount = 0;
        $headingCount = 0;
        $tableCount = 0;
        $inList = false;

        foreach ($bodyNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            if ($node->localName === 'p') {
                if ($paragraphCount >= $this->maxParagraphs) {
                    continue;
                }

                $paragraphData = $this->parseParagraph($node, $xpath);
                if ($paragraphData['isEmpty']) {
                    continue;
                }

                if ($paragraphData['isList']) {
                    if (!$inList) {
                        $html .= '<ul class="docx-list" style="margin: 8px 0 12px 24px; padding: 0;">' . "\n";
                        $inList = true;
                    }
                    $html .= '  <li style="margin-bottom: 4px; line-height: 1.5;">' . $paragraphData['innerHtml'] . '</li>' . "\n";
                } else {
                    if ($inList) {
                        $html .= "</ul>\n";
                        $inList = false;
                    }

                    if ($paragraphData['headingLevel'] > 0) {
                        $headingCount++;
                        $lvl = $paragraphData['headingLevel'];
                        $fontSize = match ($lvl) {
                            1 => '1.5em',
                            2 => '1.3em',
                            3 => '1.15em',
                            default => '1.05em',
                        };
                        $html .= sprintf(
                            '<h%d class="docx-heading" style="font-size: %s; color: #1f2328; margin-top: 18px; margin-bottom: 8px; font-weight: 600; border-bottom: %s;">%s</h%d>' . "\n",
                            $lvl,
                            $fontSize,
                            $lvl <= 2 ? '1px solid #d0d7de; padding-bottom: 4px;' : 'none',
                            $paragraphData['innerHtml'],
                            $lvl
                        );
                    } else {
                        $paragraphCount++;
                        $html .= '<p class="docx-paragraph" style="margin: 0 0 10px; line-height: 1.6; color: #24292f;">' . $paragraphData['innerHtml'] . "</p>\n";
                    }
                }
            } elseif ($node->localName === 'tbl') {
                if ($tableCount >= $this->maxTables) {
                    continue;
                }

                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }

                $tableHtml = $this->parseTable($node, $xpath);
                if ($tableHtml !== '') {
                    $tableCount++;
                    $html .= $tableHtml . "\n";
                }
            }
        }

        if ($inList) {
            $html .= "</ul>\n";
        }

        $plainText = strip_tags($html);
        $wordCount = str_word_count($plainText);

        return [
            'html' => $html,
            'paragraphCount' => $paragraphCount,
            'headingCount' => $headingCount,
            'tableCount' => $tableCount,
            'wordCount' => $wordCount,
            'parsed' => true,
        ];
    }

    /**
     * Parse a single <w:p> element.
     *
     * @return array{innerHtml: string, isList: bool, headingLevel: int, isEmpty: bool}
     */
    private function parseParagraph(DOMElement $p, DOMXPath $xpath): array
    {
        $headingLevel = 0;
        $isList = false;

        // Check paragraph style
        $pStyle = $xpath->query('w:pPr/w:pStyle/@w:val', $p);
        if ($pStyle !== false && $pStyle->length > 0) {
            $styleVal = (string) $pStyle->item(0)?->nodeValue;
            if (preg_match('/^(?:Heading|Titre|Header|Titel)\s*([1-6])/i', $styleVal, $m)) {
                $headingLevel = (int) $m[1];
            }
        }

        // Check outline level if heading not found
        if ($headingLevel === 0) {
            $outline = $xpath->query('w:pPr/w:outlineLvl/@w:val', $p);
            if ($outline !== false && $outline->length > 0) {
                $lvlVal = (int) $outline->item(0)?->nodeValue;
                if ($lvlVal >= 0 && $lvlVal <= 5) {
                    $headingLevel = $lvlVal + 1;
                }
            }
        }

        // Check list properties
        $numPr = $xpath->query('w:pPr/w:numPr', $p);
        if ($numPr !== false && $numPr->length > 0) {
            $isList = true;
        }

        // Extract runs <w:r>
        $runs = $xpath->query('w:r | w:hyperlink/w:r', $p);
        $innerHtml = '';
        $hasText = false;

        if ($runs !== false) {
            foreach ($runs as $r) {
                if (!($r instanceof DOMElement)) {
                    continue;
                }

                $runHtml = $this->parseRun($r, $xpath);
                if ($runHtml !== '') {
                    $innerHtml .= $runHtml;
                    $hasText = true;
                }
            }
        }

        return [
            'innerHtml' => $innerHtml,
            'isList' => $isList,
            'headingLevel' => $headingLevel,
            'isEmpty' => !$hasText && trim($innerHtml) === '',
        ];
    }

    /**
     * Parse a single <w:r> run element.
     */
    private function parseRun(DOMElement $r, DOMXPath $xpath): string
    {
        $isBold = false;
        $isItalic = false;
        $isUnderline = false;
        $isStrike = false;
        $isCode = false;

        // Check font styling
        $boldNodes = $xpath->query('w:rPr/w:b', $r);
        if ($boldNodes instanceof \DOMNodeList && $boldNodes->length > 0) {
            $isBold = true;
        }
        $italicNodes = $xpath->query('w:rPr/w:i', $r);
        if ($italicNodes instanceof \DOMNodeList && $italicNodes->length > 0) {
            $isItalic = true;
        }
        $uNodes = $xpath->query('w:rPr/w:u', $r);
        if ($uNodes instanceof \DOMNodeList && $uNodes->length > 0) {
            $isUnderline = true;
        }
        $strikeNodes = $xpath->query('w:rPr/w:strike', $r);
        if ($strikeNodes instanceof \DOMNodeList && $strikeNodes->length > 0) {
            $isStrike = true;
        }
        $fontNodes = $xpath->query('w:rPr/w:rFonts/@w:ascii', $r);
        if ($fontNodes instanceof \DOMNodeList && $fontNodes->length > 0) {
            $fontName = (string) ($fontNodes->item(0)->nodeValue ?? '');
            if (stripos($fontName, 'courier') !== false || stripos($fontName, 'consolas') !== false) {
                $isCode = true;
            }
        }

        // Extract text & line breaks
        $nodes = $xpath->query('w:t | w:br', $r);
        $runText = '';

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node->localName === 't') {
                    $raw = (string) $node->nodeValue;
                    $runText .= htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } elseif ($node->localName === 'br') {
                    $runText .= '<br>';
                }
            }
        }

        if ($runText === '') {
            return '';
        }

        if ($isCode) {
            $runText = '<code style="background: #f6f8fa; padding: 2px 5px; border-radius: 3px; font-family: monospace; font-size: 0.9em;">' . $runText . '</code>';
        }
        if ($isBold) {
            $runText = '<strong>' . $runText . '</strong>';
        }
        if ($isItalic) {
            $runText = '<em>' . $runText . '</em>';
        }
        if ($isUnderline) {
            $runText = '<u>' . $runText . '</u>';
        }
        if ($isStrike) {
            $runText = '<del>' . $runText . '</del>';
        }

        return $runText;
    }

    /**
     * Parse a <w:tbl> table element.
     */
    private function parseTable(DOMElement $tbl, DOMXPath $xpath): string
    {
        $rows = $xpath->query('w:tr', $tbl);
        if ($rows === false || $rows->length === 0) {
            return '';
        }

        $tableHtml = '<div class="docx-table-wrapper" style="max-width: 100%; overflow-x: auto; margin: 12px 0 16px;">' . "\n";
        $tableHtml .= '<table class="table-bordered docx-table" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; border: 1px solid #d0d7de;">' . "\n";

        $rowIndex = 0;
        foreach ($rows as $tr) {
            if (!($tr instanceof DOMElement)) {
                continue;
            }

            $headerNodes = $xpath->query('w:trPr/w:tblHeader', $tr);
            $isHeader = ($rowIndex === 0) || ($headerNodes instanceof \DOMNodeList && $headerNodes->length > 0);
            $cells = $xpath->query('w:tc', $tr);

            if ($cells === false || $cells->length === 0) {
                continue;
            }

            $tableHtml .= '  <tr style="' . ($isHeader ? 'background: #f6f8fa; font-weight: 600;' : ($rowIndex % 2 === 1 ? 'background: #fafbfc;' : 'background: #fff;')) . '">' . "\n";

            foreach ($cells as $tc) {
                if (!($tc instanceof DOMElement)) {
                    continue;
                }

                $cellText = '';
                $cellParagraphs = $xpath->query('w:p', $tc);
                if ($cellParagraphs !== false) {
                    foreach ($cellParagraphs as $cp) {
                        if ($cp instanceof DOMElement) {
                            $pData = $this->parseParagraph($cp, $xpath);
                            if ($pData['innerHtml'] !== '') {
                                $cellText .= ($cellText !== '' ? '<br>' : '') . $pData['innerHtml'];
                            }
                        }
                    }
                }

                $tag = $isHeader ? 'th' : 'td';
                $tableHtml .= sprintf(
                    '    <%s style="padding: 6px 10px; border: 1px solid #d0d7de; vertical-align: top;">%s</%s>' . "\n",
                    $tag,
                    $cellText !== '' ? $cellText : '&nbsp;',
                    $tag
                );
            }

            $tableHtml .= "  </tr>\n";
            $rowIndex++;
        }

        $tableHtml .= "</table>\n</div>";

        return $tableHtml;
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
            $tmpFile = tempnam(sys_get_temp_dir(), 'docx_read_');
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
}
