<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\BinaryContentDetector;
use PHPUnit\Framework\TestCase;

/**
 * Task 36: content inspection deciding whether an unclassified attachment can be
 * shown as text or must fall back to the download notice.
 */
class BinaryContentDetectorTest extends TestCase
{
    private BinaryContentDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new BinaryContentDetector();
    }

    /**
     * @dataProvider textPayloadProvider
     */
    public function testRecognisesTextPayloads(string $label, string $content): void
    {
        $this->assertFalse($this->detector->isBinary($content), $label . ' must be previewable as text.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function textPayloadProvider(): array
    {
        return [
            'plain ascii' => ['plain ascii', "hello world\nsecond line\n"],
            'json' => ['json', '{"status":"ok","items":[1,2,3]}'],
            'yaml' => ['yaml', "key: value\nlist:\n  - one\n  - two\n"],
            'shell script' => ['shell script', "#!/bin/sh\nset -eu\necho done\n"],
            'tabs and crlf' => ['tabs and crlf', "col1\tcol2\r\nval1\tval2\r\n"],
            'utf-8 accents' => ['utf-8 accents', "Bons de livraison — référence\n"],
            'utf-8 cjk' => ['utf-8 cjk', "日本語のテキストファイル\n"],
            'utf-8 emoji' => ['utf-8 emoji', "status: ✅ shipped 🚚\n"],
            'ansi coloured log' => ['ansi coloured log', "\x1b[32mINFO\x1b[0m started\n"],
            'form feed' => ['form feed', "page one\x0cpage two\n"],
            'empty file' => ['empty file', ''],
        ];
    }

    /**
     * @dataProvider binaryPayloadProvider
     */
    public function testRecognisesBinaryPayloads(string $label, string $content, string $expectedReason): void
    {
        $verdict = $this->detector->inspect($content);

        $this->assertTrue($verdict['binary'], $label . ' must not be rendered as text.');
        $this->assertSame($expectedReason, $verdict['reason']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function binaryPayloadProvider(): array
    {
        return [
            'zip / docx / xlsx header' => [
                'zip header',
                "PK\x03\x04\x14\x00\x00\x00\x08\x00",
                'null_byte',
            ],
            'png header' => [
                'png header',
                "\x89PNG\x0d\x0a\x1a\x0a\x00\x00\x00\x0dIHDR",
                'null_byte',
            ],
            'legacy office compound file' => [
                'ole2 header',
                "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1\x00\x00\x00\x00",
                'null_byte',
            ],
            'elf executable' => [
                'elf header',
                "\x7fELF\x02\x01\x01\x00\x00\x00\x00\x00",
                'null_byte',
            ],
            'utf-16 text is not previewable as utf-8' => [
                'utf-16',
                "\xff\xfeh\x00e\x00l\x00l\x00o\x00",
                'null_byte',
            ],
            'dense control bytes without a null' => [
                'control bytes',
                str_repeat("\x01\x02\x03\x04\x05\x06\x07\x08", 40),
                'control_characters',
            ],
            'invalid utf-8 sequences' => [
                'invalid utf-8',
                "valid start \xC3\x28\xA0\xA1 broken tail",
                'invalid_encoding',
            ],
        ];
    }

    /**
     * A NUL byte anywhere in the sampled window is decisive, even behind a
     * plausible text preamble.
     */
    public function testNullByteAfterTextPreambleIsStillBinary(): void
    {
        $content = str_repeat('readable text ', 20) . "\x00" . str_repeat('more', 10);

        $verdict = $this->detector->inspect($content);

        $this->assertTrue($verdict['binary']);
        $this->assertSame('null_byte', $verdict['reason']);
    }

    /**
     * Detection is bounded so a huge attachment cannot make inspection cost
     * proportional to its size.
     */
    public function testInspectionIsBoundedToTheSniffWindow(): void
    {
        // Clean text for the whole window, binary only far beyond it.
        $content = str_repeat('a', BinaryContentDetector::SNIFF_BYTES) . str_repeat("\x00", 4096);

        $verdict = $this->detector->inspect($content);

        $this->assertFalse($verdict['binary'], 'Only the leading window is inspected.');
        $this->assertSame(BinaryContentDetector::SNIFF_BYTES, $verdict['sniffedBytes']);
    }

    /**
     * Slicing at the sniff boundary can cut a multi-byte character in half; that
     * must not be mistaken for a broken encoding.
     */
    public function testMultiByteCharacterClippedByTheWindowIsNotBinary(): void
    {
        // Pad so a 3-byte character straddles the SNIFF_BYTES boundary.
        $padding = str_repeat('a', BinaryContentDetector::SNIFF_BYTES - 1);
        $content = $padding . '日本語' . str_repeat('b', 100);

        $this->assertFalse(
            $this->detector->isBinary($content),
            'A character clipped by the sample boundary is a slicing artefact, not binary content.'
        );
    }

    /**
     * A complete file that really is invalid UTF-8 must still be caught — the
     * truncation tolerance only applies to sampled prefixes.
     */
    public function testCompleteFileWithInvalidEncodingIsBinary(): void
    {
        $verdict = $this->detector->inspect("\xC3\x28");

        $this->assertTrue($verdict['binary']);
        $this->assertSame('invalid_encoding', $verdict['reason']);
    }

    public function testControlRatioThresholdBoundary(): void
    {
        // 4 control bytes in 100 => 4%, comfortably under the 10% threshold.
        $mostlyText = str_repeat('a', 96) . str_repeat("\x01", 4);
        $this->assertFalse($this->detector->isBinary($mostlyText));

        // 20 control bytes in 100 => 20%, over the threshold.
        $mostlyControl = str_repeat('a', 80) . str_repeat("\x01", 20);
        $this->assertTrue($this->detector->isBinary($mostlyControl));
    }

    public function testInspectReportsSampleMetadata(): void
    {
        $verdict = $this->detector->inspect("plain text\n");

        $this->assertSame('text', $verdict['reason']);
        $this->assertSame(11, $verdict['sniffedBytes']);
        $this->assertSame(0.0, $verdict['controlRatio']);
    }

    public function testEmptyContentIsReportedAsEmptyRatherThanBinary(): void
    {
        $verdict = $this->detector->inspect('');

        $this->assertFalse($verdict['binary']);
        $this->assertSame('empty', $verdict['reason']);
    }
}
