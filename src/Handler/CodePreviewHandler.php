<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;
use Kanboard\Plugin\FileInteractionCore\Service\SyntaxLanguageRegistry;

/**
 * Safe code & config syntax highlighting preview handler supporting .json, .yml, .yaml, .xml, .html, .sh, .py, .php, .js, .css, .sql.
 */
class CodePreviewHandler implements FileHandlerInterface
{
    public const SUPPORTED_EXTENSIONS = [
        'json', 'yml', 'yaml', 'xml', 'html', 'htm', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql'
    ];

    private SyntaxLanguageRegistry $languageRegistry;

    public function __construct(?SyntaxLanguageRegistry $languageRegistry = null)
    {
        $this->languageRegistry = $languageRegistry ?? new SyntaxLanguageRegistry();
    }

    public function supports(string $extension, string $mimeType): bool
    {
        $normalizedExt = strtolower(ltrim(trim($extension), '.'));
        return in_array($normalizedExt, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function preview(string $content, array $options = []): PreviewResult
    {
        $forcedExt = $options['extension'] ?? 'txt';

        /**
         * `language` is the picker's explicit choice and outranks the extension.
         *
         * It stays the raw token so `metadata['language']` keeps its original
         * meaning (the extension, when nothing was picked); the canonical registry
         * id travels separately as `languageId`.
         */
        $language = strtolower((string) ($options['language'] ?? $forcedExt));

        // Canonical id drives the comment prefixes and keyword set. An unmapped
        // token (e.g. a bare extension like `sh`) resolves through the registry.
        $languageId = $this->languageRegistry->isSupported($language)
            ? (string) $this->languageRegistry->normalize($language)
            : $this->languageRegistry->resolveFromExtension($language);

        // Step 1: Escape code content to guarantee XSS safety
        $escapedContent = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Step 2: Highlight tokens safely
        $highlighted = $this->highlightSyntax($escapedContent, $language, $languageId);

        $lineCount = substr_count($content, "\n") + (trim($content) !== '' ? 1 : 0);
        $charCount = mb_strlen($content, 'UTF-8');

        $metadata = [
            'handler' => $this->getHandlerName(),
            'language' => $language,
            'languageId' => $languageId,
            'languageLabel' => $this->languageRegistry->getLabel($languageId),
            'lineCount' => $lineCount,
            'charCount' => $charCount,
        ];

        return new PreviewResult($highlighted, true, $metadata);
    }

    /**
     * Tokenize and wrap keywords, strings, comments, numbers, and functions in highlighted span elements.
     *
     * @param string      $language   Raw token used for the `language-*` CSS class.
     * @param string|null $languageId Canonical registry id selecting the comment
     *                                prefixes and keyword set. Resolved from
     *                                $language when omitted, so existing callers
     *                                keep working.
     */
    public function highlightSyntax(string $escapedCode, string $language, ?string $languageId = null): string
    {
        $resolvedId = $languageId ?? (
            $this->languageRegistry->isSupported($language)
                ? (string) $this->languageRegistry->normalize($language)
                : $this->languageRegistry->resolveFromExtension($language)
        );

        $commentPrefixes = $this->languageRegistry->getCommentPrefixes($resolvedId);
        $keywordPattern = $this->buildKeywordPattern($resolvedId);

        $lines = explode("\n", $escapedCode);
        $highlightedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Comment line — prefixes are language specific, so `#` is not treated
            // as a comment in JSON or CSS, and `--` is recognised in SQL.
            if ($this->isCommentLine($trimmed, $commentPrefixes)) {
                $highlightedLines[] = '<span class="tok-comment" style="color: #6a737d; font-style: italic;">' . $line . '</span>';
                continue;
            }

            // Step A: Mark strings with temporary placeholders
            $line = (string) preg_replace(
                '/(&quot;[^\n]*?&quot;|&#039;[^\n]*?&#039;|"[^\n]*?"|\'[^\n]*?\')/',
                '___STR___$1___ENDSTR___',
                $line
            );

            // Step B: Highlight keywords
            if ($keywordPattern !== null) {
                $line = (string) preg_replace(
                    $keywordPattern,
                    '<span class="tok-keyword" style="color: #d73a49; font-weight: bold;">$1</span>',
                    $line
                );
            }

            // Step C: Highlight numbers
            $line = (string) preg_replace(
                '/\b(\d+(?:\.\d+)?)\b/',
                '<span class="tok-number" style="color: #005cc5;">$1</span>',
                $line
            );

            // Step D: Re-wrap string placeholders with styled spans
            $line = (string) preg_replace(
                '/___STR___(.*?)___ENDSTR___/',
                '<span class="tok-string" style="color: #032f62;">$1</span>',
                $line
            );

            $highlightedLines[] = $line;
        }

        $code = implode("\n", $highlightedLines);

        return '<pre class="code-highlight language-' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '" style="background: #f6f8fa; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 13px; overflow-x: auto; border: 1px solid #e1e4e8;"><code>' . $code . '</code></pre>';
    }

    /**
     * @param list<string> $commentPrefixes
     */
    private function isCommentLine(string $trimmedLine, array $commentPrefixes): bool
    {
        if ($trimmedLine === '') {
            return false;
        }

        foreach ($commentPrefixes as $prefix) {
            if (str_starts_with($trimmedLine, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the keyword alternation for a language, or null when it has none.
     *
     * Keywords come from the registry and are quoted before use so a future entry
     * containing a regex metacharacter (`font-face`, `require_once`) cannot corrupt
     * the pattern.
     */
    private function buildKeywordPattern(string $languageId): ?string
    {
        $keywords = $this->languageRegistry->getKeywords($languageId);

        if ($keywords === []) {
            return null;
        }

        $quoted = array_map(
            static fn (string $keyword): string => preg_quote($keyword, '/'),
            $keywords
        );

        // Longest first, so `require_once` wins over `require`.
        usort($quoted, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return '/\b(' . implode('|', $quoted) . ')\b/i';
    }

    public function getHandlerName(): string
    {
        return 'CodePreviewHandler';
    }
}

