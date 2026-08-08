<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\FileVersionService;
use Kanboard\Plugin\FileInteractionCore\Service\MockFileRevisionWriter;
use PHPUnit\Framework\TestCase;

class FileVersionServiceTest extends TestCase
{
    private MockFileRevisionWriter $writer;
    private FileVersionService $service;

    protected function setUp(): void
    {
        $this->writer = new MockFileRevisionWriter();
        $this->service = new FileVersionService(new FileValidationService(), $this->writer);
    }

    // ---------------------------------------------------------------------
    // Revision filename generation
    // ---------------------------------------------------------------------

    public function testGeneratesVersionedFilenameForSimpleAttachment(): void
    {
        $this->assertSame('document_v2.txt', $this->service->generateVersionedFilename('document.txt', 2));
        $this->assertSame('config_v7.json', $this->service->generateVersionedFilename('config.json', 7));
        $this->assertSame('README_v3.md', $this->service->generateVersionedFilename('README.md', 3));
    }

    public function testGeneratedFilenamePreservesMultiDotBaseName(): void
    {
        $this->assertSame('archive.tar_v2.gz', $this->service->generateVersionedFilename('archive.tar.gz', 2));
        $this->assertSame('app.config_v4.yml', $this->service->generateVersionedFilename('app.config.yml', 4));
    }

    /**
     * Re-versioning must REPLACE the marker. Stacking would produce
     * "report_v2_v3.txt" and break every subsequent version lookup.
     */
    public function testGeneratedFilenameReplacesExistingVersionMarkerInsteadOfStacking(): void
    {
        $this->assertSame('report_v3.txt', $this->service->generateVersionedFilename('report_v2.txt', 3));
        $this->assertSame('report_v10.txt', $this->service->generateVersionedFilename('report_v9.txt', 10));
        $this->assertStringNotContainsString(
            '_v2_v',
            $this->service->generateVersionedFilename('report_v2.txt', 3)
        );
    }

    public function testGeneratedFilenamePreservesOriginalCasing(): void
    {
        $this->assertSame('REPORT_v2.TXT', $this->service->generateVersionedFilename('REPORT.TXT', 2));
        // An uppercase marker is still recognised and replaced
        $this->assertSame('Notes_v5.md', $this->service->generateVersionedFilename('Notes_V4.md', 5));
    }

    /**
     * Leading-dot files carry no extension segment, so the marker is appended
     * to the whole name. Such names fall outside the editable format list.
     */
    public function testGeneratedFilenameHandlesDotfilesAndExtensionlessNames(): void
    {
        $this->assertSame('.env_v2', $this->service->generateVersionedFilename('.env', 2));
        $this->assertSame('Makefile_v3', $this->service->generateVersionedFilename('Makefile', 3));
    }

    public function testGenerateVersionedFilenameRejectsNonPositiveVersions(): void
    {
        foreach ([0, -1, -42] as $version) {
            try {
                $this->service->generateVersionedFilename('document.txt', $version);
                $this->fail("Revision {$version} should have been rejected.");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('must be 1 or greater', $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parsing & version resolution
    // ---------------------------------------------------------------------

    public function testParseFilenameSplitsBaseVersionAndExtension(): void
    {
        $this->assertSame(
            ['base' => 'report', 'version' => 3, 'extension' => 'txt'],
            $this->service->parseFilename('report_v3.txt')
        );
    }

    public function testParseFilenameReportsUnversionedFilesAsFirstVersion(): void
    {
        $parsed = $this->service->parseFilename('document.txt');

        $this->assertSame('document', $parsed['base']);
        $this->assertSame(FileVersionService::FIRST_VERSION, $parsed['version']);
        $this->assertSame('txt', $parsed['extension']);
    }

    /**
     * "_v0" is not a valid revision marker, so it stays part of the base name
     * rather than silently resolving to revision 0.
     */
    public function testParseFilenameKeepsZeroMarkerAsPartOfBaseName(): void
    {
        $parsed = $this->service->parseFilename('draft_v0.txt');

        $this->assertSame('draft_v0', $parsed['base']);
        $this->assertSame(1, $parsed['version']);
    }

    public function testResolveNextVersionStartsAtTwoForFreshAttachment(): void
    {
        $this->assertSame(2, $this->service->resolveNextVersion('document.txt'));
        $this->assertSame(2, $this->service->resolveNextVersion('document.txt', []));
    }

    public function testResolveNextVersionPicksHighestExistingSibling(): void
    {
        $existing = ['document.txt', 'document_v2.txt', 'document_v5.txt', 'document_v3.txt'];

        $this->assertSame(6, $this->service->resolveNextVersion('document.txt', $existing));
        // Resolving from an older revision must still land past the newest one
        $this->assertSame(6, $this->service->resolveNextVersion('document_v2.txt', $existing));
    }

    public function testResolveNextVersionIgnoresUnrelatedSiblings(): void
    {
        $existing = [
            'notes.txt',
            'notes_v9.md',      // same base, different extension
            'other_v7.txt',     // different base
            'notes_v2.txt',
        ];

        $this->assertSame(3, $this->service->resolveNextVersion('notes.txt', $existing));
    }

    public function testResolveNextVersionSanitizesSiblingPathsBeforeComparing(): void
    {
        $existing = ['../../../tmp/document_v4.txt', '/var/www/document_v2.txt'];

        $this->assertSame(5, $this->service->resolveNextVersion('document.txt', $existing));
    }

    public function testResolveNextVersionSkipsUnusableSiblingNames(): void
    {
        $existing = ['document_v3.txt', '..', '.', '   '];

        $this->assertSame(4, $this->service->resolveNextVersion('document.txt', $existing));
    }

    // ---------------------------------------------------------------------
    // Path traversal protection
    // ---------------------------------------------------------------------

    public function testGeneratedFilenameStripsPathTraversalSequences(): void
    {
        $this->assertSame('passwd_v2.txt', $this->service->generateVersionedFilename('../../../etc/passwd.txt', 2));
        $this->assertSame('notes_v2.md', $this->service->generateVersionedFilename('/var/www/app/notes.md', 2));
        $this->assertSame('config_v2.json', $this->service->generateVersionedFilename('subfolder/config.json', 2));
    }

    public function testGeneratedFilenameNeverContainsPathSeparators(): void
    {
        $generated = $this->service->generateVersionedFilename('../../evil/payload.txt', 9);

        $this->assertStringNotContainsString('/', $generated);
        $this->assertStringNotContainsString('..', $generated);
        $this->assertSame('payload_v9.txt', $generated);
    }

    public function testRejectsNullByteInjectedFilenames(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('null byte');

        $this->service->generateVersionedFilename("document.txt\0.php", 2);
    }

    public function testRejectsFilenamesResolvingToDirectoryReferences(): void
    {
        foreach (['..', '.', '   ', '/'] as $filename) {
            try {
                $this->service->generateVersionedFilename($filename, 2);
                $this->fail("Filename '{$filename}' should have been rejected.");
            } catch (InvalidFileException $e) {
                $this->assertStringContainsString('invalid or empty path', $e->getMessage());
            }
        }
    }

    public function testCreateRevisionSanitizesTraversalBeforePersisting(): void
    {
        $result = $this->service->createRevision('../../../etc/notes.txt', 'payload');

        $this->assertSame('notes_v2.txt', $result['filename']);
        $this->assertSame('notes.txt', $result['previousFilename']);
        $this->assertSame('notes_v2.txt', $this->writer->getLastWrite()['filename']);
    }

    // ---------------------------------------------------------------------
    // Content update & revision creation
    // ---------------------------------------------------------------------

    public function testUpdateContentOverwritesInPlace(): void
    {
        $result = $this->service->updateContent('document.txt', 'updated body');

        $this->assertSame('document.txt', $result['filename']);
        $this->assertFalse($result['isNewRevision']);
        $this->assertTrue($result['persisted']);
        $this->assertSame(strlen('updated body'), $result['sizeBytes']);
        $this->assertSame(1, $result['version']);

        $write = $this->writer->getLastWrite();
        $this->assertNotNull($write);
        $this->assertSame('overwrite', $write['mode']);
        $this->assertSame('updated body', $write['content']);
    }

    public function testUpdateContentKeepsRevisionNumberOfVersionedAttachment(): void
    {
        $result = $this->service->updateContent('document_v4.txt', 'body');

        $this->assertSame('document_v4.txt', $result['filename']);
        $this->assertSame(4, $result['version']);
        $this->assertFalse($result['isNewRevision']);
    }

    public function testCreateRevisionWritesNewVersionedAttachment(): void
    {
        $result = $this->service->createRevision('document.txt', '{"a":1}', ['document.txt', 'document_v2.txt']);

        $this->assertSame('document_v3.txt', $result['filename']);
        $this->assertSame('document.txt', $result['previousFilename']);
        $this->assertSame(3, $result['version']);
        $this->assertTrue($result['isNewRevision']);
        $this->assertTrue($result['persisted']);

        $write = $this->writer->getLastWrite();
        $this->assertNotNull($write);
        $this->assertSame('revision', $write['mode']);
        $this->assertSame('{"a":1}', $this->writer->getContent('document_v3.txt'));
    }

    /**
     * Creating a revision must never touch the original attachment.
     */
    public function testCreateRevisionLeavesOriginalAttachmentUntouched(): void
    {
        $this->service->createRevision('document.txt', 'new body');

        $this->assertNull($this->writer->getContent('document.txt'));
        $this->assertSame('new body', $this->writer->getContent('document_v2.txt'));
        $this->assertCount(1, $this->writer->getWrites());
    }

    public function testSaveDispatchesBetweenOverwriteAndRevision(): void
    {
        $overwrite = $this->service->save('notes.md', '# body', false);
        $revision = $this->service->save('notes.md', '# body', true, ['notes.md']);

        $this->assertSame('notes.md', $overwrite['filename']);
        $this->assertFalse($overwrite['isNewRevision']);

        $this->assertSame('notes_v2.md', $revision['filename']);
        $this->assertTrue($revision['isNewRevision']);
    }

    public function testWriterFailureIsReportedWithoutThrowing(): void
    {
        $this->writer->setSucceed(false);

        $result = $this->service->createRevision('document.txt', 'body');

        $this->assertSame('document_v2.txt', $result['filename']);
        $this->assertFalse($result['persisted']);
        $this->assertSame([], $this->writer->getWrites());
    }

    /**
     * Without a writer the service still computes revision metadata, which lets
     * callers preview the outcome of a save before committing to it.
     */
    public function testServiceOperatesWithoutWriterAsDryRun(): void
    {
        $dryRun = new FileVersionService();

        $result = $dryRun->createRevision('document.txt', 'body', ['document_v6.txt']);

        $this->assertSame('document_v7.txt', $result['filename']);
        $this->assertFalse($result['persisted']);
    }

    // ---------------------------------------------------------------------
    // Validation bounds
    // ---------------------------------------------------------------------

    public function testRejectsContentExceedingSizeLimit(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('exceeds maximum allowed limit');

        $oversized = str_repeat('a', FileValidationService::DEFAULT_MAX_SIZE_BYTES + 1);
        $this->service->updateContent('document.txt', $oversized);
    }

    public function testRejectsRevisionForDisallowedExtension(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('is not allowed');

        $this->service->createRevision('payload.exe', 'binary');
    }

    /**
     * A rejected payload must never reach the storage backend.
     */
    public function testRejectedPayloadIsNeverPersisted(): void
    {
        try {
            $this->service->updateContent('payload.exe', 'binary');
        } catch (InvalidFileException $e) {
            // expected
        }

        $this->assertSame([], $this->writer->getWrites());
    }
}
