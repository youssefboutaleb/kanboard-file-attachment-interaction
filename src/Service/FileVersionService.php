<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileRevisionWriterInterface;
use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;

/**
 * Attachment versioning service: generates revision filenames (document_v2.txt)
 * and persists edited content either in place or as a new versioned revision.
 *
 * Path safety is delegated to FileValidationService::sanitizeFilename(), so
 * traversal sequences and null bytes are rejected before any name is built.
 */
class FileVersionService
{
    /**
     * Revision suffix marker: "report" + SEPARATOR + "2" => "report_v2".
     */
    public const VERSION_SEPARATOR = '_v';

    public const FIRST_VERSION = 1;

    private FileValidationService $validationService;

    private ?FileRevisionWriterInterface $writer;

    public function __construct(
        ?FileValidationService $validationService = null,
        ?FileRevisionWriterInterface $writer = null
    ) {
        $this->validationService = $validationService ?? new FileValidationService();
        $this->writer = $writer;
    }

    /**
     * Split a filename into its version-aware components.
     *
     * "report_v3.txt" => base "report", version 3, extension "txt".
     * An unversioned name is reported as version 1.
     *
     * @return array{base: string, version: int, extension: string}
     */
    public function parseFilename(string $filename): array
    {
        $safeName = $this->validationService->sanitizeFilename($filename);

        $extension = '';
        $stem = $safeName;

        // Leading dot files (.env) have no extension: the dot starts the name.
        $dotPosition = strrpos($safeName, '.');
        if ($dotPosition !== false && $dotPosition > 0) {
            $stem = substr($safeName, 0, $dotPosition);
            $extension = substr($safeName, $dotPosition + 1);
        }

        $version = self::FIRST_VERSION;
        $base = $stem;

        // Match a trailing _v<digits> marker, case-insensitively (_V2 counts too)
        if (preg_match('/^(.*)' . preg_quote(self::VERSION_SEPARATOR, '/') . '(\d+)$/i', $stem, $matches) === 1) {
            $parsedVersion = (int) $matches[2];

            // "_v0" is not a real revision marker; keep it as part of the base name
            if ($parsedVersion >= self::FIRST_VERSION) {
                $base = $matches[1];
                $version = $parsedVersion;
            }
        }

        return [
            'base' => $base,
            'version' => $version,
            'extension' => $extension,
        ];
    }

    /**
     * Build the filename for a specific revision number.
     *
     * An existing revision marker is REPLACED rather than stacked:
     * generateVersionedFilename('report_v2.txt', 5) === 'report_v5.txt'.
     */
    public function generateVersionedFilename(string $filename, int $version): string
    {
        if ($version < self::FIRST_VERSION) {
            throw new InvalidFileException(sprintf(
                'Revision number must be %d or greater, got %d.',
                self::FIRST_VERSION,
                $version
            ));
        }

        $parts = $this->parseFilename($filename);
        $versionedStem = $parts['base'] . self::VERSION_SEPARATOR . $version;

        return $parts['extension'] === ''
            ? $versionedStem
            : $versionedStem . '.' . $parts['extension'];
    }

    /**
     * Determine the next free revision number for a file.
     *
     * The unversioned original counts as revision 1, so the first revision
     * created for a fresh attachment is 2.
     *
     * @param list<string> $existingFilenames Sibling attachment names on the same task.
     */
    public function resolveNextVersion(string $filename, array $existingFilenames = []): int
    {
        $target = $this->parseFilename($filename);
        $highest = $target['version'];

        foreach ($existingFilenames as $candidate) {
            try {
                $parsed = $this->parseFilename($candidate);
            } catch (InvalidFileException $e) {
                // Unusable sibling names cannot influence the revision counter
                continue;
            }

            if (strcasecmp($parsed['base'], $target['base']) !== 0) {
                continue;
            }

            if (strcasecmp($parsed['extension'], $target['extension']) !== 0) {
                continue;
            }

            $highest = max($highest, $parsed['version']);
        }

        return $highest + 1;
    }

    /**
     * Create a new versioned revision of an attachment.
     *
     * @param list<string> $existingFilenames
     * @return array{filename: string, previousFilename: string, version: int, sizeBytes: int, isNewRevision: bool, persisted: bool}
     */
    public function createRevision(string $filename, string $content, array $existingFilenames = []): array
    {
        $previousFilename = $this->validationService->sanitizeFilename($filename);
        $this->assertContentWithinBounds($previousFilename, $content);

        $version = $this->resolveNextVersion($previousFilename, $existingFilenames);
        $revisionFilename = $this->generateVersionedFilename($previousFilename, $version);

        $persisted = $this->writer !== null
            ? $this->writer->writeRevision($revisionFilename, $content)
            : false;

        return [
            'filename' => $revisionFilename,
            'previousFilename' => $previousFilename,
            'version' => $version,
            'sizeBytes' => strlen($content),
            'isNewRevision' => true,
            'persisted' => $persisted,
        ];
    }

    /**
     * Overwrite an existing attachment in place, keeping its filename and revision.
     *
     * @return array{filename: string, previousFilename: string, version: int, sizeBytes: int, isNewRevision: bool, persisted: bool}
     */
    public function updateContent(string $filename, string $content): array
    {
        $safeName = $this->validationService->sanitizeFilename($filename);
        $this->assertContentWithinBounds($safeName, $content);

        $persisted = $this->writer !== null
            ? $this->writer->overwriteContent($safeName, $content)
            : false;

        return [
            'filename' => $safeName,
            'previousFilename' => $safeName,
            'version' => $this->parseFilename($safeName)['version'],
            'sizeBytes' => strlen($content),
            'isNewRevision' => false,
            'persisted' => $persisted,
        ];
    }

    /**
     * Single entry point for the editor: overwrite or branch a new revision.
     *
     * @param list<string> $existingFilenames
     * @return array{filename: string, previousFilename: string, version: int, sizeBytes: int, isNewRevision: bool, persisted: bool}
     */
    public function save(
        string $filename,
        string $content,
        bool $asNewRevision = false,
        array $existingFilenames = []
    ): array {
        return $asNewRevision
            ? $this->createRevision($filename, $content, $existingFilenames)
            : $this->updateContent($filename, $content);
    }

    /**
     * Enforce the extension whitelist and the per-extension size ceiling.
     */
    private function assertContentWithinBounds(string $safeName, string $content): void
    {
        $extension = $this->validationService->validateExtension($safeName);
        $this->validationService->validateFileSize(strlen($content), $extension);
    }
}
