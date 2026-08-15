<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Memory-safe OpenXML (.pptx) PowerPoint presentation parser service.
 *
 * Extracts slides in sequential order with titles, bullet points, text blocks,
 * and tables into structured, pre-escaped data.
 */
class PptxParserService
{
    private int $maxSlides;

    public function __construct(int $maxSlides = 100)
    {
        $this->maxSlides = $maxSlides;
    }

    /**
     * Parse raw binary content of a .pptx file.
     *
     * @return array{
     *     slides: list<array{
     *         index: int,
     *         title: string,
     *         paragraphs: list<string>,
     *         bulletPoints: list<string>,
     *         tables: list<list<list<string>>>
     *     }>,
     *     slideCount: int,
     *     title: string,
     *     parsed: bool
     * }
     */
    public function parsePptxContent(string $zipContent): array
    {
        if (trim($zipContent) === '') {
            return [
                'slides' => [],
                'slideCount' => 0,
                'title' => '',
                'parsed' => false,
            ];
        }

        $files = $this->extractZipFiles($zipContent);
        if (empty($files)) {
            return [
                'slides' => [],
                'slideCount' => 0,
                'title' => '',
                'parsed' => false,
            ];
        }

        // 1. Resolve slide paths in order
        $slidePaths = $this->resolveSlidePaths($files);
        if (empty($slidePaths)) {
            return [
                'slides' => [],
                'slideCount' => 0,
                'title' => '',
                'parsed' => false,
            ];
        }

        $slides = [];
        $presentationTitle = '';

        foreach ($slidePaths as $idx => $path) {
            if ($idx >= $this->maxSlides) {
                break;
            }

            $slideXml = $files[$path] ?? null;
            if ($slideXml === null) {
                continue;
            }

            $slideData = $this->parseSlideXml($slideXml, $idx + 1);
            if ($idx === 0 && $slideData['title'] !== '') {
                $presentationTitle = $slideData['title'];
            }
            $slides[] = $slideData;
        }

        if (empty($slides)) {
            return [
                'slides' => [],
                'slideCount' => 0,
                'title' => '',
                'parsed' => false,
            ];
        }

        if ($presentationTitle === '') {
            $presentationTitle = $slides[0]['title'] !== '' ? $slides[0]['title'] : 'Presentation';
        }

        return [
            'slides' => $slides,
            'slideCount' => count($slides),
            'title' => $presentationTitle,
            'parsed' => true,
        ];
    }

    /**
     * Resolve ordered list of slide XML paths inside the presentation ZIP.
     *
     * @param array<string, string> $files
     * @return list<string>
     */
    private function resolveSlidePaths(array $files): array
    {
        $slidePaths = [];

        // Try resolving via ppt/presentation.xml and ppt/_rels/presentation.xml.rels
        if (isset($files['ppt/presentation.xml']) && isset($files['ppt/_rels/presentation.xml.rels'])) {
            $relDom = new DOMDocument();
            if (@$relDom->loadXML($files['ppt/_rels/presentation.xml.rels'], \LIBXML_NONET | \LIBXML_NOBLANKS)) {
                $relXPath = new DOMXPath($relDom);
                $relXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

                $rNodes = $relXPath->query('//r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide"]');
                $relMap = [];
                if ($rNodes !== false) {
                    foreach ($rNodes as $rn) {
                        if ($rn instanceof DOMElement) {
                            $rId = (string) $rn->getAttribute('Id');
                            $target = (string) $rn->getAttribute('Target');
                            if (!str_starts_with($target, 'ppt/')) {
                                $target = 'ppt/' . ltrim($target, '/');
                            }
                            $relMap[$rId] = $target;
                        }
                    }
                }

                $presDom = new DOMDocument();
                if (@$presDom->loadXML($files['ppt/presentation.xml'], \LIBXML_NONET | \LIBXML_NOBLANKS)) {
                    $presXPath = new DOMXPath($presDom);
                    $presXPath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
                    $presXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

                    $sldNodes = $presXPath->query('//p:sldIdLst/p:sldId/@r:id');
                    if ($sldNodes !== false) {
                        foreach ($sldNodes as $sn) {
                            $rId = (string) $sn->nodeValue;
                            if (isset($relMap[$rId]) && isset($files[$relMap[$rId]])) {
                                $slidePaths[] = $relMap[$rId];
                            }
                        }
                    }
                }
            }
        }

        // Fallback: discover all ppt/slides/slide*.xml sorted naturally
        if (empty($slidePaths)) {
            $discovered = [];
            foreach (array_keys($files) as $k) {
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $k, $m)) {
                    $discovered[(int) $m[1]] = $k;
                }
            }
            ksort($discovered, \SORT_NUMERIC);
            $slidePaths = array_values($discovered);
        }

        return $slidePaths;
    }

    /**
     * Parse a single slide XML file.
     *
     * @return array{
     *     index: int,
     *     title: string,
     *     paragraphs: list<string>,
     *     bulletPoints: list<string>,
     *     tables: list<list<list<string>>>
     * }
     */
    private function parseSlideXml(string $xml, int $slideIndex): array
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, \LIBXML_NONET | \LIBXML_NOBLANKS)) {
            return [
                'index' => $slideIndex,
                'title' => sprintf('Slide %d', $slideIndex),
                'paragraphs' => [],
                'bulletPoints' => [],
                'tables' => [],
            ];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $title = '';
        $paragraphs = [];
        $bulletPoints = [];
        $tables = [];

        // 1. Extract slide title
        $titleNodes = $xpath->query('//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]]//a:p');
        if ($titleNodes !== false && $titleNodes->length > 0) {
            $titleParts = [];
            foreach ($titleNodes as $tp) {
                $tText = $this->extractParagraphText($tp, $xpath);
                if ($tText !== '') {
                    $titleParts[] = $tText;
                }
            }
            if (!empty($titleParts)) {
                $title = implode(' ', $titleParts);
            }
        }

        // 2. Extract shape content (non-title text boxes)
        $shapes = $xpath->query('//p:sp[not(p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"])]');
        if ($shapes !== false) {
            foreach ($shapes as $sp) {
                if (!($sp instanceof DOMElement)) {
                    continue;
                }

                $pNodes = $xpath->query('.//a:p', $sp);
                if ($pNodes === false) {
                    continue;
                }

                foreach ($pNodes as $pn) {
                    if (!($pn instanceof DOMElement)) {
                        continue;
                    }

                    $pText = $this->extractParagraphText($pn, $xpath);
                    if ($pText === '') {
                        continue;
                    }

                    // Check if bullet point (has lvl attribute or bullet character)
                    $lvlAttr = $xpath->query('a:pPr/@lvl', $pn);
                    $buNodes = $xpath->query('a:pPr/a:buChar | a:pPr/a:buAutoNum', $pn);
                    $hasBullet = ($lvlAttr instanceof \DOMNodeList && $lvlAttr->length > 0)
                        || ($buNodes instanceof \DOMNodeList && $buNodes->length > 0);

                    if ($hasBullet) {
                        $bulletPoints[] = $pText;
                    } else {
                        $paragraphs[] = $pText;
                    }
                }
            }
        }

        // Fallback title if empty: use first short paragraph if available
        if ($title === '') {
            if (!empty($paragraphs) && mb_strlen($paragraphs[0]) < 80) {
                $title = (string) array_shift($paragraphs);
            } else {
                $title = sprintf('Slide %d', $slideIndex);
            }
        }

        // 3. Extract tables
        $tableNodes = $xpath->query('//a:tbl');
        if ($tableNodes !== false) {
            foreach ($tableNodes as $tbl) {
                if (!($tbl instanceof DOMElement)) {
                    continue;
                }

                $tblData = [];
                $trNodes = $xpath->query('.//a:tr', $tbl);
                if ($trNodes !== false) {
                    foreach ($trNodes as $tr) {
                        if (!($tr instanceof DOMElement)) {
                            continue;
                        }

                        $rowData = [];
                        $tcNodes = $xpath->query('.//a:tc', $tr);
                        if ($tcNodes !== false) {
                            foreach ($tcNodes as $tc) {
                                if (!($tc instanceof DOMElement)) {
                                    continue;
                                }

                                $cellParts = [];
                                $cellPs = $xpath->query('.//a:p', $tc);
                                if ($cellPs !== false) {
                                    foreach ($cellPs as $cp) {
                                        $cpText = $this->extractParagraphText($cp, $xpath);
                                        if ($cpText !== '') {
                                            $cellParts[] = $cpText;
                                        }
                                    }
                                }
                                $rowData[] = implode(' ', $cellParts);
                            }
                        }
                        if (!empty($rowData)) {
                            $tblData[] = $rowData;
                        }
                    }
                }
                if (!empty($tblData)) {
                    $tables[] = $tblData;
                }
            }
        }

        return [
            'index' => $slideIndex,
            'title' => $title,
            'paragraphs' => $paragraphs,
            'bulletPoints' => $bulletPoints,
            'tables' => $tables,
        ];
    }

    /**
     * Extract text runs from <a:p> element and return HTML-escaped string.
     */
    private function extractParagraphText(\DOMNode $p, DOMXPath $xpath): string
    {
        $textRuns = $xpath->query('.//a:r/a:t | .//a:fld/a:t', $p);
        if ($textRuns === false || $textRuns->length === 0) {
            return '';
        }

        $out = '';
        foreach ($textRuns as $t) {
            $raw = (string) $t->nodeValue;
            $out .= htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return trim($out);
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
            $tmpFile = tempnam(sys_get_temp_dir(), 'pptx_read_');
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
