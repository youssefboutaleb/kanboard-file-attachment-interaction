<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Pre-save validation service for file edit payloads (size & syntax validation).
 */
class FileEditValidationService
{
    public const DEFAULT_MAX_SIZE_BYTES = 524288; // 500 KB

    /**
     * Extensions offered for in-app editing (spec 005 scope).
     *
     * Deliberately NARROWER than FileValidationService::ALLOWED_EXTENSIONS:
     * previewable formats such as pdf (binary), csv/tsv (rendered as tables) and
     * html (active content) must not be opened in a plain-text editor.
     */
    public const EDITABLE_EXTENSIONS = [
        'txt', 'json', 'md', 'markdown',
        'yml', 'yaml',
        'sh', 'py', 'js', 'css', 'sql',
    ];

    /**
     * Whether a file extension may be opened in the live editor.
     */
    public function isEditableExtension(string $extension): bool
    {
        $normalized = strtolower(ltrim(trim($extension), '.'));

        return in_array($normalized, self::EDITABLE_EXTENSIONS, true);
    }

    private int $maxSizeBytes;

    public function __construct(int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES)
    {
        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Validate payload size and syntax before saving edits.
     *
     * @return array{isValid: bool, error: ?string, errorLine: ?int}
     */
    public function validate(string $content, string $extension): array
    {
        $sizeBytes = strlen($content);
        if ($sizeBytes > $this->maxSizeBytes) {
            return [
                'isValid' => false,
                'error' => sprintf('File content size (%d bytes) exceeds maximum edit limit of %d bytes.', $sizeBytes, $this->maxSizeBytes),
                'errorLine' => null,
            ];
        }

        $normalizedExt = strtolower(ltrim(trim($extension), '.'));

        if ($normalizedExt === 'json' && trim($content) !== '') {
            return $this->validateJsonSyntax($content);
        }

        return [
            'isValid' => true,
            'error' => null,
            'errorLine' => null,
        ];
    }

    /**
     * Check JSON syntax validity and report line offset on error.
     *
     * @return array{isValid: bool, error: ?string, errorLine: ?int}
     */
    private function validateJsonSyntax(string $content): array
    {
        json_decode($content);
        $errorCode = json_last_error();

        if ($errorCode === JSON_ERROR_NONE) {
            return [
                'isValid' => true,
                'error' => null,
                'errorLine' => null,
            ];
        }

        $errorMsg = json_last_error_msg();
        $errorLine = $this->guessJsonErrorLine($content);

        return [
            'isValid' => false,
            'error' => 'JSON Syntax Error: ' . $errorMsg,
            'errorLine' => $errorLine,
        ];
    }

    /**
     * Estimate error line in malformed JSON string.
     */
    private function guessJsonErrorLine(string $json): int
    {
        $lines = explode("\n", $json);
        $buffer = '';

        foreach ($lines as $index => $line) {
            $buffer .= $line . "\n";
            json_decode($buffer);
            if (json_last_error() !== JSON_ERROR_NONE && json_last_error() !== JSON_ERROR_CTRL_CHAR) {
                return $index + 1;
            }
        }

        return 1;
    }
}
