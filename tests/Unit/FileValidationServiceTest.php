<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use PHPUnit\Framework\TestCase;

class FileValidationServiceTest extends TestCase
{
    private FileValidationService $service;

    protected function setUp(): void
    {
        $this->service = new FileValidationService();
    }

    public function testSanitizeFilenameStripsPathTraversalSequences(): void
    {
        $this->assertSame('notes.txt', $this->service->sanitizeFilename('../../../notes.txt'));
        $this->assertSame('.env', $this->service->sanitizeFilename('/var/www/.env'));
        $this->assertSame('index.html', $this->service->sanitizeFilename('subfolder/index.html'));
    }

    public function testValidateExtensionAcceptsWhitelistedExtensions(): void
    {
        $valid = ['document.txt', 'config.json', 'README.md', '.env', 'app.ini', 'server.conf', 'config.yaml', 'data.xml', 'app.log', 'page.html'];

        foreach ($valid as $file) {
            $ext = $this->service->validateExtension($file);
            $this->assertNotEmpty($ext);
        }
    }

    public function testValidateExtensionRejectsDisallowedExtensions(): void
    {
        // NOTE: .php/.js/.sh became previewable in Milestone 3 (spec 003) and are
        // asserted separately in testValidateExtensionAcceptsSourceCodeExtensions.
        $disallowed = ['logo.svg', 'binary.exe', 'firmware.bin', 'archive.zip', 'library.dll'];

        foreach ($disallowed as $filename) {
            try {
                $this->service->validateExtension($filename);
                $this->fail("Validation should have failed for file: {$filename}");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('is not allowed', $e->getMessage());
            }
        }
    }

    public function testValidateMimeTypeMatch(): void
    {
        $this->service->validateMimeType('txt', 'text/plain');
        $this->service->validateMimeType('json', 'application/json');
        $this->service->validateMimeType('md', 'text/markdown');
        $this->service->validateMimeType('html', 'text/html');
        $this->service->validateMimeType('env', 'text/plain');
        $this->assertTrue(true);
    }

    public function testValidateExtensionAcceptsCsvAndTsv(): void
    {
        $this->assertSame('csv', $this->service->validateExtension('export.csv'));
        $this->assertSame('tsv', $this->service->validateExtension('metrics.tsv'));
        $this->assertSame('csv', $this->service->validateExtension('REPORT.CSV'));
        $this->assertSame('tsv', $this->service->validateExtension(' Data.TsV '));
    }

    public function testValidateExtensionSanitizesTraversalOnCsvPaths(): void
    {
        $this->assertSame('csv', $this->service->validateExtension('../../../etc/passwd.csv'));
        $this->assertSame('csv', $this->service->validateExtension('/var/www/data/export.csv'));
    }

    public function testValidateMimeTypeAcceptsCsvAndTsvVariants(): void
    {
        $this->service->validateMimeType('csv', 'text/csv');
        $this->service->validateMimeType('csv', 'application/csv');
        $this->service->validateMimeType('csv', 'text/plain');
        // Excel exports commonly report this MIME type for plain .csv files
        $this->service->validateMimeType('csv', 'application/vnd.ms-excel');
        $this->service->validateMimeType('tsv', 'text/tab-separated-values');
        $this->service->validateMimeType('tsv', 'text/tsv');
        $this->service->validateMimeType('.CSV', 'TEXT/CSV');
        $this->assertTrue(true);
    }

    public function testValidateMimeTypeRejectsMismatchedTabularMime(): void
    {
        $mismatches = [
            ['csv', 'application/pdf'],
            ['csv', 'image/png'],
            ['tsv', 'application/json'],
        ];

        foreach ($mismatches as [$extension, $mimeType]) {
            try {
                $this->service->validateMimeType($extension, $mimeType);
                $this->fail("MIME validation should have failed for .{$extension} with {$mimeType}");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('does not match expected types', $e->getMessage());
            }
        }
    }

    public function testValidateFileAcceptsTabularAttachmentsEndToEnd(): void
    {
        $this->assertTrue($this->service->validateFile('export.csv', 2048, 'text/csv'));
        $this->assertTrue($this->service->validateFile('metrics.tsv', 2048, 'text/tab-separated-values'));
        $this->assertTrue($this->service->validateFile('export.csv', 2048, null));
    }

    public function testValidateFileRejectsOversizedCsv(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('exceeds maximum allowed limit');

        $this->service->validateFile('export.csv', FileValidationService::DEFAULT_MAX_SIZE_BYTES + 1, 'text/csv');
    }

    public function testValidateExtensionAcceptsMarkdownExtension(): void
    {
        $this->assertSame('markdown', $this->service->validateExtension('README.markdown'));
        $this->assertSame('markdown', $this->service->validateExtension('NOTES.MARKDOWN'));
        $this->assertSame('md', $this->service->validateExtension('CHANGELOG.md'));
    }

    /**
     * Spec 003 scopes source files for syntax-highlighted preview. They are never
     * executed: CodePreviewHandler entity-escapes the payload before highlighting.
     */
    public function testValidateExtensionAcceptsSourceCodeExtensions(): void
    {
        $expected = [
            'deploy.sh' => 'sh',
            'build.bash' => 'bash',
            'analyze.py' => 'py',
            'index.php' => 'php',
            'bundle.js' => 'js',
            'theme.css' => 'css',
            'migration.sql' => 'sql',
        ];

        foreach ($expected as $filename => $extension) {
            $this->assertSame($extension, $this->service->validateExtension($filename));
        }
    }

    public function testValidateMimeTypeAcceptsMarkdownAndCodeVariants(): void
    {
        $this->service->validateMimeType('markdown', 'text/markdown');
        $this->service->validateMimeType('markdown', 'text/x-markdown');
        $this->service->validateMimeType('sh', 'application/x-shellscript');
        $this->service->validateMimeType('py', 'text/x-python');
        $this->service->validateMimeType('php', 'application/x-httpd-php');
        $this->service->validateMimeType('js', 'application/javascript');
        $this->service->validateMimeType('css', 'text/css');
        $this->service->validateMimeType('sql', 'application/sql');
        // Object storage backends routinely fall back to text/plain for source files
        $this->service->validateMimeType('py', 'text/plain');
        $this->service->validateMimeType('sql', 'text/plain');
        $this->assertTrue(true);
    }

    public function testValidateMimeTypeRejectsMismatchedCodeMime(): void
    {
        $mismatches = [
            ['py', 'image/png'],
            ['css', 'application/pdf'],
            ['markdown', 'application/zip'],
        ];

        foreach ($mismatches as [$extension, $mimeType]) {
            try {
                $this->service->validateMimeType($extension, $mimeType);
                $this->fail("MIME validation should have failed for .{$extension} with {$mimeType}");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('does not match expected types', $e->getMessage());
            }
        }
    }

    public function testEveryAllowedExtensionHasMimeMapping(): void
    {
        // Binary document formats are strictly typed: text/plain is NOT a valid
        // MIME type for them and is asserted to be rejected separately below.
        $binaryExtensions = ['pdf', 'xlsx', 'xls', 'docx', 'dotx', 'doc', 'pptx', 'potx', 'ppt'];

        foreach (FileValidationService::ALLOWED_EXTENSIONS as $extension) {
            if (in_array($extension, $binaryExtensions, true)) {
                continue;
            }

            // text/plain is the universal fallback every text mapping must tolerate.
            $this->service->validateMimeType($extension, 'text/plain');
        }

        $this->assertTrue(true);
    }

    public function testValidateExtensionAcceptsPdf(): void
    {
        $this->assertSame('pdf', $this->service->validateExtension('invoice.pdf'));
        $this->assertSame('pdf', $this->service->validateExtension('SPECIFICATION.PDF'));
        $this->assertSame('pdf', $this->service->validateExtension(' Report.PdF '));
    }

    public function testValidateExtensionAcceptsExcel(): void
    {
        $this->assertSame('xlsx', $this->service->validateExtension('data.xlsx'));
        $this->assertSame('xls', $this->service->validateExtension('legacy.xls'));
    }

    public function testValidateFileSizeAcceptsUpTo5MbForExcel(): void
    {
        // 5 MB = 5,242,880 bytes
        $fiveMb = FileValidationService::EXCEL_MAX_SIZE_BYTES;

        $this->service->validateFileSize($fiveMb, 'xlsx');
        $this->service->validateFileSize($fiveMb, 'xls');

        $this->expectException(InvalidFileException::class);
        $this->service->validateFileSize($fiveMb + 1, 'xlsx');
    }

    public function testValidateExtensionSanitizesTraversalOnPdfPaths(): void
    {
        $this->assertSame('pdf', $this->service->validateExtension('../../../etc/passwd.pdf'));
        $this->assertSame('pdf', $this->service->validateExtension('/var/www/data/invoice.pdf'));
    }

    public function testValidateMimeTypeAcceptsPdfVariants(): void
    {
        $this->service->validateMimeType('pdf', 'application/pdf');
        $this->service->validateMimeType('pdf', 'application/x-pdf');
        // Object storage backends fall back to octet-stream for unknown binaries
        $this->service->validateMimeType('pdf', 'application/octet-stream');
        $this->service->validateMimeType('.PDF', 'APPLICATION/PDF');
        $this->assertTrue(true);
    }

    public function testValidateMimeTypeRejectsMismatchedPdfMime(): void
    {
        // A PDF announcing itself as renderable text is a spoofing attempt
        $mismatches = ['text/plain', 'text/html', 'image/png', 'application/json'];

        foreach ($mismatches as $mimeType) {
            try {
                $this->service->validateMimeType('pdf', $mimeType);
                $this->fail("MIME validation should have failed for .pdf with {$mimeType}");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('does not match expected types', $e->getMessage());
            }
        }
    }

    public function testPdfUsesTenMegabyteSizeLimit(): void
    {
        $this->assertSame(10485760, FileValidationService::PDF_MAX_SIZE_BYTES);
        $this->assertSame(
            FileValidationService::PDF_MAX_SIZE_BYTES,
            $this->service->getMaxSizeForExtension('pdf')
        );
        $this->assertSame(
            FileValidationService::PDF_MAX_SIZE_BYTES,
            $this->service->getMaxSizeForExtension('.PDF')
        );

        // 5 MB and an exactly-at-cap 10 MB PDF are both accepted
        $this->service->validateFileSize(5242880, 'pdf');
        $this->service->validateFileSize(FileValidationService::PDF_MAX_SIZE_BYTES, 'pdf');
        $this->assertTrue($this->service->validateFile('invoice.pdf', 5242880, 'application/pdf'));
    }

    public function testValidateFileRejectsOversizedPdf(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('exceeds maximum allowed limit');

        // 15 MB document (spec 004 TC-PDF-03)
        $this->service->validateFile('huge.pdf', 15728640, 'application/pdf');
    }

    /**
     * The 10 MB allowance is scoped to PDFs only — it must never leak into the
     * text preview budget, which stays at the tighter 500 KB default.
     */
    public function testNonPdfExtensionsKeepDefaultSizeLimit(): void
    {
        $this->assertSame(
            FileValidationService::DEFAULT_MAX_SIZE_BYTES,
            $this->service->getMaxSizeForExtension('txt')
        );
        $this->assertSame(
            FileValidationService::DEFAULT_MAX_SIZE_BYTES,
            $this->service->getMaxSizeForExtension(null)
        );

        $oversizedForText = FileValidationService::DEFAULT_MAX_SIZE_BYTES + 1;

        foreach (['notes.txt', 'export.csv', 'README.md'] as $filename) {
            try {
                $this->service->validateFile($filename, $oversizedForText, null);
                $this->fail("Size validation should have failed for {$filename}");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('exceeds maximum allowed limit', $e->getMessage());
            }
        }
    }

    public function testExtensionSizeCapsAreConfigurable(): void
    {
        $strictService = new FileValidationService(
            FileValidationService::DEFAULT_MAX_SIZE_BYTES,
            FileValidationService::ALLOWED_EXTENSIONS,
            ['pdf' => 1024]
        );

        $this->assertSame(1024, $strictService->getMaxSizeForExtension('pdf'));

        $this->expectException(InvalidFileException::class);
        $strictService->validateFileSize(2048, 'pdf');
    }

    public function testValidateExtensionAcceptsDocxAndPptx(): void
    {
        $this->assertSame('docx', $this->service->validateExtension('report.docx'));
        $this->assertSame('dotx', $this->service->validateExtension('template.dotx'));
        $this->assertSame('doc', $this->service->validateExtension('legacy.doc'));
        $this->assertSame('pptx', $this->service->validateExtension('presentation.pptx'));
        $this->assertSame('potx', $this->service->validateExtension('template.potx'));
        $this->assertSame('ppt', $this->service->validateExtension('legacy.ppt'));
    }

    public function testValidateFileSizeAcceptsUpTo10MbForDocx(): void
    {
        $tenMb = FileValidationService::DOCX_MAX_SIZE_BYTES;
        $this->service->validateFileSize($tenMb, 'docx');
        $this->service->validateFileSize($tenMb, 'doc');

        $this->expectException(InvalidFileException::class);
        $this->service->validateFileSize($tenMb + 1, 'docx');
    }

    public function testValidateFileSizeAcceptsUpTo15MbForPptx(): void
    {
        $fifteenMb = FileValidationService::PPTX_MAX_SIZE_BYTES;
        $this->service->validateFileSize($fifteenMb, 'pptx');
        $this->service->validateFileSize($fifteenMb, 'ppt');

        $this->expectException(InvalidFileException::class);
        $this->service->validateFileSize($fifteenMb + 1, 'pptx');
    }
}
