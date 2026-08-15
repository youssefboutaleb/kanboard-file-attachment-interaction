<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\PptxParserService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PptxParserServiceTest extends TestCase
{
    private PptxParserService $service;

    protected function setUp(): void
    {
        $this->service = new PptxParserService(20);
    }

    public function testHandlesEmptyContentGracefully(): void
    {
        $result = $this->service->parsePptxContent('');

        $this->assertFalse($result['parsed']);
        $this->assertSame(0, $result['slideCount']);
        $this->assertSame([], $result['slides']);
    }

    public function testHandlesInvalidZipFile(): void
    {
        $result = $this->service->parsePptxContent('NOT_A_VALID_ZIP_PACKAGE');

        $this->assertFalse($result['parsed']);
        $this->assertSame(0, $result['slideCount']);
    }

    public function testParsesSyntheticPptxWithOrderedSlidesTitlesAndBullets(): void
    {
        $presXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <p:sldIdLst>
        <p:sldId id="256" r:id="rId1"/>
        <p:sldId id="257" r:id="rId2"/>
    </p:sldIdLst>
</p:presentation>
XML;

        $presRelsXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>
</Relationships>
XML;

        $slide1Xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
    <p:cSld>
        <p:spTree>
            <p:sp>
                <p:nvSpPr>
                    <p:nvPr>
                        <p:ph type="title"/>
                    </p:nvPr>
                </p:nvSpPr>
                <p:txBody>
                    <a:p>
                        <a:r>
                            <a:t>Quarterly Financial Results</a:t>
                        </a:r>
                    </a:p>
                </p:txBody>
            </p:sp>
            <p:sp>
                <p:txBody>
                    <a:p>
                        <a:pPr lvl="0"/>
                        <a:r>
                            <a:t>Revenue up 25% YoY</a:t>
                        </a:r>
                    </a:p>
                </p:txBody>
            </p:sp>
        </p:spTree>
    </p:cSld>
</p:sld>
XML;

        $slide2Xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
    <p:cSld>
        <p:spTree>
            <p:sp>
                <p:nvSpPr>
                    <p:nvPr>
                        <p:ph type="title"/>
                    </p:nvPr>
                </p:nvSpPr>
                <p:txBody>
                    <a:p>
                        <a:r>
                            <a:t>Next Steps &amp; Goals</a:t>
                        </a:r>
                    </a:p>
                </p:txBody>
            </p:sp>
            <p:graphicFrame>
                <a:graphic>
                    <a:graphicData>
                        <a:tbl>
                            <a:tr>
                                <a:tc><a:txBody><a:p><a:r><a:t>Milestone</a:t></a:r></a:p></a:txBody></a:tc>
                                <a:tc><a:txBody><a:p><a:r><a:t>ETA</a:t></a:r></a:p></a:txBody></a:tc>
                            </a:tr>
                            <a:tr>
                                <a:tc><a:txBody><a:p><a:r><a:t>Release v0.9.0</a:t></a:r></a:p></a:txBody></a:tc>
                                <a:tc><a:txBody><a:p><a:r><a:t>Today</a:t></a:r></a:p></a:txBody></a:tc>
                            </a:tr>
                        </a:tbl>
                    </a:graphicData>
                </a:graphic>
            </p:graphicFrame>
        </p:spTree>
    </p:cSld>
</p:sld>
XML;

        $zipData = $this->createPptxZip([
            'ppt/presentation.xml' => $presXml,
            'ppt/_rels/presentation.xml.rels' => $presRelsXml,
            'ppt/slides/slide1.xml' => $slide1Xml,
            'ppt/slides/slide2.xml' => $slide2Xml,
        ]);

        $result = $this->service->parsePptxContent($zipData);

        $this->assertTrue($result['parsed']);
        $this->assertSame(2, $result['slideCount']);
        $this->assertSame('Quarterly Financial Results', $result['title']);
        $this->assertCount(2, $result['slides']);

        // Slide 1 checks
        $this->assertSame('Quarterly Financial Results', $result['slides'][0]['title']);
        $this->assertContains('Revenue up 25% YoY', $result['slides'][0]['bulletPoints']);

        // Slide 2 checks
        $this->assertSame('Next Steps &amp; Goals', $result['slides'][1]['title']);
        $this->assertCount(1, $result['slides'][1]['tables']);
        $this->assertSame('Milestone', $result['slides'][1]['tables'][0][0][0]);
    }

    public function testStrictOutputEscapingOfMaliciousPayloads(): void
    {
        $slideXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
    <p:cSld>
        <p:spTree>
            <p:sp>
                <p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>
                <p:txBody>
                    <a:p>
                        <a:r>
                            <a:t><![CDATA[<script>alert("XSS")</script>&"'\><img src=x onerror=alert(1)>]]></a:t>
                        </a:r>
                    </a:p>
                </p:txBody>
            </p:sp>
        </p:spTree>
    </p:cSld>
</p:sld>
XML;

        $zipData = $this->createPptxZip([
            'ppt/slides/slide1.xml' => $slideXml,
        ]);

        $result = $this->service->parsePptxContent($zipData);

        $this->assertTrue($result['parsed']);
        $this->assertStringNotContainsString('<script>', $result['slides'][0]['title']);
        $this->assertStringNotContainsString('<img', $result['slides'][0]['title']);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $result['slides'][0]['title']);
    }

    /**
     * Helper to create a binary ZIP string for tests.
     *
     * @param array<string, string> $files
     */
    private function createPptxZip(array $files): string
    {
        $writer = new \Kanboard\Plugin\FileInteractionCore\Service\ExcelWriterService();
        return $writer->packZip($files);
    }
}
