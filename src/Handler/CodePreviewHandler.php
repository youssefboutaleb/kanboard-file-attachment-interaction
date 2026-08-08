<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Handler;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\PreviewResult;

/**
 * Safe code & config syntax highlighting preview handler supporting .json, .yml, .yaml, .xml, .html, .sh, .py, .php, .js, .css, .sql.
 */
class CodePreviewHandler implements FileHandlerInterface
{
    public const SUPPORTED_EXTENSIONS = [
        'json', 'yml', 'yaml', 'xml', 'html', 'htm', 'sh', 'bash', 'py', 'php', 'js', 'css', 'sql'
    ];

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
        $language = strtolower((string)$forcedExt);

        // Step 1: Escape code content to guarantee XSS safety
        $escapedContent = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Step 2: Highlight tokens safely
        $highlighted = $this->highlightSyntax($escapedContent, $language);

        $lineCount = substr_count($content, "\n") + (trim($content) !== '' ? 1 : 0);
        $charCount = mb_strlen($content, 'UTF-8');

        $metadata = [
            'handler' => $this->getHandlerName(),
            'language' => $language,
            'lineCount' => $lineCount,
            'charCount' => $charCount,
        ];

        return new PreviewResult($highlighted, true, $metadata);
    }

    /**
     * Tokenize and wrap keywords, strings, comments, numbers, and functions in highlighted span elements.
     */
    public function highlightSyntax(string $escapedCode, string $language): string
    {
        $lines = explode("\n", $escapedCode);
        $highlightedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Comment line
            if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
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
            $keywords = '\b(function|def|class|return|if|else|elif|for|while|import|from|as|try|except|catch|throw|new|public|private|protected|static|const|var|let|async|await|select|insert|update|delete|where|from|join|order|by|group|limit)\b';
            $line = (string) preg_replace(
                '/' . $keywords . '/i',
                '<span class="tok-keyword" style="color: #d73a49; font-weight: bold;">$1</span>',
                $line
            );

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

    public function getHandlerName(): string
    {
        return 'CodePreviewHandler';
    }
}
