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

    public const PDF_MAX_SIZE_BYTES = 10485760; // 10 MB (spec 004)

    public const EXCEL_MAX_SIZE_BYTES = 5242880; // 5 MB (spec 006)

    /**
     * Allowed file extensions (Milestone 1 text/JSON, Milestone 2 tabular, Milestone 3 markdown/code,
     * Milestone 4 PDF, Milestone 6 Excel).
     *
     * NOTE: script-typed source extensions (php, js, sh, bash, py) are previewable
     * but NEVER executed: CodePreviewHandler entity-escapes the whole payload with
     * htmlspecialchars() before applying syntax highlighting spans.
     *
     * NOTE: pdf is a binary document format. It is never parsed or executed by this
     * plugin; the payload is streamed into a sandboxed viewer container so any
     * embedded JavaScript or macro stays inert.
     */
    public const ALLOWED_EXTENSIONS = [
        'txt', 'json', 'md', 'env', 'ini', 'conf', 'yaml', 'yml', 'xml', 'log', 'html', 'htm',
        'csv', 'tsv',
        'markdown', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql',
        'pdf', 'xlsx', 'xls'
    ];

    /**
     * Per-extension size caps overriding DEFAULT_MAX_SIZE_BYTES.
     *
     * Text previews stay on the tight 500 KB budget; binary document formats need a
     * larger allowance because the whole payload is embedded in the viewer.
     *
     * @var array<string, int>
     */
    public const EXTENSION_MAX_SIZE_BYTES = [
        'pdf' => self::PDF_MAX_SIZE_BYTES,
        'xlsx' => self::EXCEL_MAX_SIZE_BYTES,
        'xls' => self::EXCEL_MAX_SIZE_BYTES,
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
        'sh'   => ['text/plain', 'text/x-sh', 'application/x-sh', 'application/x-shellscript', 'text/x-shellscript'],
        'bash' => ['text/plain', 'text/x-sh', 'application/x-sh', 'application/x-shellscript', 'text/x-shellscript'],
        'py'   => ['text/x-python', 'text/x-script.python', 'text/plain'],
        'php'  => ['text/x-php', 'application/x-httpd-php', 'text/plain'],
        'js'   => ['application/javascript', 'text/javascript', 'text/plain'],
        'css'  => ['text/css', 'text/plain'],
        'sql'  => ['text/x-sql', 'application/sql', 'text/plain'],
        // Binary document format: text/* is intentionally absent, a PDF announcing
        // itself as plain text is a mismatch worth rejecting. Object storage
        // backends fall back to application/octet-stream for unknown binaries.
        'pdf'  => ['application/pdf', 'application/x-pdf', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-ms-excel', 'application/x-excel', 'application/x-dos_ms_excel', 'application/xls', 'application/x-xls', 'application/octet-stream'],
    ];

    private int $maxSizeBytes;

    /**
     * @var list<string>
     */
    private array $allowedExtensions;

    /**
     * @var array<string, int>
     */
    private array $extensionMaxSizeBytes;

    /**
     * @param int $maxSizeBytes
     * @param list<string> $allowedExtensions
     * @param array<string, int> $extensionMaxSizeBytes
     */
    public function __construct(
        int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
        array $allowedExtensions = self::ALLOWED_EXTENSIONS,
        array $extensionMaxSizeBytes = self::EXTENSION_MAX_SIZE_BYTES
    ) {
        $this->maxSizeBytes = $maxSizeBytes;
        $this->allowedExtensions = array_map('strtolower', $allowedExtensions);

        $normalizedCaps = [];
        foreach ($extensionMaxSizeBytes as $extension => $cap) {
            $normalizedCaps[strtolower(ltrim(trim((string) $extension), '.'))] = $cap;
        }
        $this->extensionMaxSizeBytes = $normalizedCaps;
    }

    /**
     * Resolve the size cap applying to a given extension (null falls back to the global default).
     */
    public function getMaxSizeForExtension(?string $extension = null): int
    {
        if ($extension === null) {
            return $this->maxSizeBytes;
        }

        $normalizedExt = strtolower(ltrim(trim($extension), '.'));

        return $this->extensionMaxSizeBytes[$normalizedExt] ?? $this->maxSizeBytes;
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

    public function isAllowedExtension(string $extension): bool
    {
        $normalizedExt = strtolower(ltrim(trim($extension), '.'));
        return in_array($normalizedExt, $this->allowedExtensions, true);
    }

    /**
     * Validate file size against the configured maximum boundary (and > 0 bytes).
     *
     * When an extension is supplied, its per-format cap (if any) applies instead of
     * the global default — e.g. PDFs are allowed up to 10 MB while text previews
     * remain capped at 500 KB.
     */
    public function validateFileSize(int $sizeInBytes, ?string $extension = null): void
    {
        if ($sizeInBytes < 0) {
            throw new InvalidFileException('File size cannot be negative.');
        }

        $maxSizeBytes = $this->getMaxSizeForExtension($extension);

        if ($sizeInBytes > $maxSizeBytes) {
            throw new InvalidFileException(sprintf(
                'File size (%d bytes) exceeds maximum allowed limit of %d bytes.',
                $sizeInBytes,
                $maxSizeBytes
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
        $this->validateFileSize($fileSizeBytes, $extension);

        if ($mimeType !== null) {
            $this->validateMimeType($extension, $mimeType);
        }

        return true;
    }
}
