<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;

/**
 * Gatekeeper service responsible for validating file extension, path safety, MIME type, and size caps.
 */
class FileValidationService
{
    public const DEFAULT_MAX_SIZE_BYTES = 524288; // 500 KB

    /**
     * Allowed file extensions (Milestone 1 text/JSON, Milestone 2 tabular, Milestone 3 markdown/code).
     *
     * NOTE: script-typed source extensions (php, js, sh, bash, py) are previewable
     * but NEVER executed: CodePreviewHandler entity-escapes the whole payload with
     * htmlspecialchars() before applying syntax highlighting spans.
     */
    public const ALLOWED_EXTENSIONS = [
        'txt', 'json', 'md', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm',
        'csv', 'tsv',
        'markdown', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql'
    ];

    /**
     * Expected MIME type mappings.
     *
     * @var array<string, list<string>>
     */
    private const MIME_MAP = [
        'txt'  => ['text/plain'],
        'json' => ['application/json', 'text/json', 'text/plain'],
        'md'   => ['text/markdown', 'text/x-markdown', 'text/plain'],
        'env'  => ['text/plain', 'text/x-config', 'application/octet-stream'],
        'ini'  => ['text/plain', 'text/x-ini', 'text/config'],
        'conf' => ['text/plain', 'text/x-config'],
        'yaml' => ['text/plain', 'text/yaml', 'text/x-yaml', 'application/x-yaml'],
        'yml'  => ['text/plain', 'text/yaml', 'text/x-yaml', 'application/x-yaml'],
        'xml'  => ['text/plain', 'text/xml', 'application/xml'],
        'log'  => ['text/plain', 'text/x-log'],
        'html' => ['text/html', 'text/plain', 'application/xhtml+xml'],
        'htm'  => ['text/html', 'text/plain'],
        // Spreadsheet exports frequently mislabel .csv as an Excel MIME type
        'csv'  => ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'],
        'tsv'  => ['text/tab-separated-values', 'text/tsv', 'text/plain'],
        'markdown' => ['text/markdown', 'text/x-markdown', 'text/plain'],
        // Source files are commonly served as text/plain by object storage backends
        'sh'   => ['text/plain', 'text/x-sh', 'application/x-sh', 'application/x-shellscript'],
        'bash' => ['text/plain', 'text/x-sh', 'application/x-sh', 'application/x-shellscript'],
        'py'   => ['text/plain', 'text/x-python', 'application/x-python-code'],
        'php'  => ['text/plain', 'text/x-php', 'application/x-httpd-php'],
        'js'   => ['text/plain', 'text/javascript', 'application/javascript', 'application/x-javascript'],
        'css'  => ['text/plain', 'text/css'],
        'sql'  => ['text/plain', 'text/x-sql', 'application/sql'],
    ];

    private int $maxSizeBytes;

    /**
     * @var list<string>
     */
    private array $allowedExtensions;

    /**
     * @param int $maxSizeBytes
     * @param list<string> $allowedExtensions
     */
    public function __construct(
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
        array $allowedExtensions = self::ALLOWED_EXTENSIONS
    ) {
        $this->maxSizeBytes = $maxSizeBytes;
        $this->allowedExtensions = array_map('strtolower', $allowedExtensions);
    }

    /**
     * Sanitize filename using basename and ensure path traversal sequences are removed.
     */
    public function sanitizeFilename(string $filename): string
    {
        if (str_contains($filename, "\0")) {
            throw new InvalidFileException('Filename contains illegal null byte characters.');
        }

        $safeName = basename(trim($filename));

        if (empty($safeName) || $safeName === '.' || $safeName === '..') {
            throw new InvalidFileException('Filename resolves to an invalid or empty path.');
        }

        return $safeName;
    }

    /**
     * Extract and validate file extension against the whitelist.
     */
    public function validateExtension(string $filename): string
    {
        $safeName = $this->sanitizeFilename($filename);
        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

        if (empty($extension)) {
            throw new InvalidFileException('File has no extension.');
        }

        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new InvalidFileException(sprintf(
                'File extension ".%s" is not allowed. Allowed extensions: %s.',
                $extension,
                implode(', ', array_map(fn($ext) => '.' . $ext, $this->allowedExtensions))
            ));
        }

        return $extension;
    }

    /**
     * Validate file size against configured maximum boundary (and > 0 bytes).
     */
    public function validateFileSize(int $sizeInBytes): void
    {
        if ($sizeInBytes < 0) {
            throw new InvalidFileException('File size cannot be negative.');
        }

        if ($sizeInBytes > $this->maxSizeBytes) {
            throw new InvalidFileException(sprintf(
                'File size (%d bytes) exceeds maximum allowed limit of %d bytes.',
                $sizeInBytes,
                $this->maxSizeBytes
            ));
        }
    }

    /**
     * Validate MIME type against expected mappings for the given extension.
     */
    public function validateMimeType(string $extension, string $mimeType): void
    {
        $normalizedExt = strtolower(ltrim(trim($extension), '.'));
        $normalizedMime = strtolower(trim($mimeType));

        if (empty($normalizedMime)) {
            return; // Allow empty MIME type if extension is valid
        }

        if (isset(self::MIME_MAP[$normalizedExt])) {
            $expectedMimes = self::MIME_MAP[$normalizedExt];
            if (!in_array($normalizedMime, $expectedMimes, true)) {
                throw new InvalidFileException(sprintf(
                    'MIME type "%s" does not match expected types for extension ".%s".',
                    $mimeType,
                    $normalizedExt
                ));
            }
        }
    }

    /**
     * Comprehensive validation check for a file.
     */
    public function validateFile(string $filename, int $fileSizeBytes, ?string $mimeType = null): bool
    {
        $extension = $this->validateExtension($filename);
        $this->validateFileSize($fileSizeBytes);

        if ($mimeType !== null) {
            $this->validateMimeType($extension, $mimeType);
        }

        return true;
    }
}
