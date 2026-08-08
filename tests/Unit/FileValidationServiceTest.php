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
        $disallowed = ['index.php', 'app.js', 'logo.svg', 'binary.exe', 'setup.sh'];

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
}
