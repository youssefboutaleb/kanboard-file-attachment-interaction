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
}
