<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\DocxParserService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class DocxParserServiceTest extends TestCase
{
    private DocxParserService $service;

    protected function setUp(): void
    {
        $this->service = new DocxParserService(100, 20);
    }

    public function testHandlesEmptyContentGracefully(): void
    {
        $result = $this->service->parseDocxContent('');

        $this->assertFalse($result['parsed']);
        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['paragraphCount']);
        $this->assertSame(0, $result['wordCount']);
    }

    public function testHandlesInvalidZipFile(): void
    {
        $result = $this->service->parseDocxContent('NOT_A_VALID_ZIP_PACKAGE');

        $this->assertFalse($result['parsed']);
        $this->assertSame('', $result['html']);
    }

    public function testParsesSyntheticDocxWithHeadingsParagraphsListsAndTables(): void
    {
        $docXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Heading1"/>
            </w:pPr>
            <w:r>
                <w:t>Project Architecture Overview</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:r>
                <w:rPr>
                    <w:b/>
                </w:rPr>
                <w:t>Security Note:</w:t>
            </w:r>
            <w:r>
                <w:t> All inputs must be strictly sanitized.</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:pPr>
                <w:numPr>
                    <w:ilvl w:val="0"/>
                    <w:numId w:val="1"/>
                </w:numPr>
            </w:pPr>
            <w:r>
                <w:t>Item 1: Sandboxing</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:pPr>
                <w:numPr>
                    <w:ilvl w:val="0"/>
                    <w:numId w:val="1"/>
                </w:numPr>
            </w:pPr>
            <w:r>
                <w:t>Item 2: Path Traversal Defense</w:t>
            </w:r>
        </w:p>
        <w:tbl>
            <w:tr>
                <w:trPr>
                    <w:tblHeader/>
                </w:trPr>
                <w:tc>
                    <w:p><w:r><w:t>Component</w:t></w:r></w:p>
                </w:tc>
                <w:tc>
                    <w:p><w:r><w:t>Status</w:t></w:r></w:p>
                </w:tc>
            </w:tr>
            <w:tr>
                <w:tc>
                    <w:p><w:r><w:t>Parser</w:t></w:r></w:p>
                </w:tc>
                <w:tc>
                    <w:p><w:r><w:t>Active</w:t></w:r></w:p>
                </w:tc>
            </w:tr>
        </w:tbl>
    </w:body>
</w:document>
XML;

        $zipData = $this->createDocxZip(['word/document.xml' => $docXml]);
        $result = $this->service->parseDocxContent($zipData);

        $this->assertTrue($result['parsed']);
        $this->assertSame(1, $result['headingCount']);
        $this->assertSame(1, $result['paragraphCount']);
        $this->assertSame(1, $result['tableCount']);
        $this->assertGreaterThan(5, $result['wordCount']);

        $this->assertStringContainsString('<h1 class="docx-heading"', $result['html']);
        $this->assertStringContainsString('Project Architecture Overview', $result['html']);
        $this->assertStringContainsString('<strong>Security Note:</strong>', $result['html']);
        $this->assertStringContainsString('<ul class="docx-list"', $result['html']);
        $this->assertStringContainsString('Item 1: Sandboxing', $result['html']);
        $this->assertStringContainsString('<table class="table-bordered docx-table"', $result['html']);
        $this->assertStringContainsString('Component', $result['html']);
    }

    public function testStrictOutputEscapingOfMaliciousPayloads(): void
    {
        $maliciousXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:r>
                <w:t><![CDATA[<script>alert("XSS")</script>&"'\><img src=x onerror=alert(1)>]]></w:t>
            </w:r>
        </w:p>
    </w:body>
</w:document>
XML;

        $zipData = $this->createDocxZip(['word/document.xml' => $maliciousXml]);
        $result = $this->service->parseDocxContent($zipData);

        $this->assertTrue($result['parsed']);
        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringNotContainsString('<img', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $result['html']);
    }

    /**
     * Helper to create a binary ZIP string for tests.
     *
     * @param array<string, string> $files
     */
    private function createDocxZip(array $files): string
    {
        $writer = new \Kanboard\Plugin\FileInteractionCore\Service\ExcelWriterService();
        return $writer->packZip($files);
    }
}
