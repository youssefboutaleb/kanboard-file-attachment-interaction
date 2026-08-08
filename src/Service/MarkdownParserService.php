<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Safe, lightweight Markdown to HTML parser with strict XSS containment and link URL sanitization.
 */
class MarkdownParserService
{
    /**
     * Parse raw Markdown text into safe HTML output with metadata.
     *
     * @return array{
     *     html: string,
     *     headingCount: int,
     *     lineCount: int,
     *     codeBlockCount: int
     * }
     */
    public function parse(string $markdown): array
    {
        $normalized = str_replace("\r\n", "\n", trim($markdown));

        if ($normalized === '') {
            return [
                'html' => '',
                'headingCount' => 0,
                'lineCount' => 0,
                'codeBlockCount' => 0,
            ];
        }

        $lines = explode("\n", $normalized);
        $lineCount = count($lines);
        $headingCount = 0;
        $codeBlockCount = 0;

        $inCodeBlock = false;
        $codeLang = '';
        $codeLines = [];

        $inList = false;
        $listType = 'ul';

        $outputHtml = [];

        foreach ($lines as $line) {
            // Check code block fence ```
            if (str_starts_with(trim($line), '```')) {
                if ($inCodeBlock) {
                    // Close code block
                    $escapedCode = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $langAttr = $codeLang !== '' ? ' class="language-' . htmlspecialchars($codeLang, ENT_QUOTES, 'UTF-8') . '"' : '';
                    $outputHtml[] = '<pre' . $langAttr . '><code>' . $escapedCode . '</code></pre>';
                    
                    $inCodeBlock = false;
                    $codeLang = '';
                    $codeLines = [];
                    $codeBlockCount++;
                } else {
                    // Close open list if active
                    if ($inList) {
                        $outputHtml[] = "</{$listType}>";
                        $inList = false;
                    }

                    $inCodeBlock = true;
                    $codeLang = strtolower(trim(substr(trim($line), 3)));
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeLines[] = $line;
                continue;
            }

            // Close open list if non-list line encountered
            $trimmedLine = trim($line);
            $isUnorderedList = str_starts_with($trimmedLine, '- ') || str_starts_with($trimmedLine, '* ');
            $isOrderedList = (bool) preg_match('/^\d+\.\s+/', $trimmedLine);

            if (!$isUnorderedList && !$isOrderedList && $inList) {
                $outputHtml[] = "</{$listType}>";
                $inList = false;
            }

            if ($trimmedLine === '') {
                continue;
            }

            // Headers (# to ######)
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmedLine, $matches)) {
                $headingCount++;
                $level = strlen($matches[1]);
                $headingText = $this->parseInlineElements($matches[2]);
                $outputHtml[] = "<h{$level}>{$headingText}</h{$level}>";
                continue;
            }

            // Blockquotes (> text)
            if (str_starts_with($trimmedLine, '> ')) {
                $quoteText = $this->parseInlineElements(substr($trimmedLine, 2));
                $outputHtml[] = "<blockquote><p>{$quoteText}</p></blockquote>";
                continue;
            }

            // Unordered List (- item or * item)
            if ($isUnorderedList) {
                if (!$inList || $listType !== 'ul') {
                    if ($inList) {
                        $outputHtml[] = "</{$listType}>";
                    }
                    $outputHtml[] = '<ul>';
                    $inList = true;
                    $listType = 'ul';
                }
                $itemText = $this->parseInlineElements(substr($trimmedLine, 2));
                $outputHtml[] = "<li>{$itemText}</li>";
                continue;
            }

            // Ordered List (1. item)
            if ($isOrderedList) {
                if (!$inList || $listType !== 'ol') {
                    if ($inList) {
                        $outputHtml[] = "</{$listType}>";
                    }
                    $outputHtml[] = '<ol>';
                    $inList = true;
                    $listType = 'ol';
                }
                $itemText = $this->parseInlineElements((string) preg_replace('/^\d+\.\s+/', '', $trimmedLine));
                $outputHtml[] = "<li>{$itemText}</li>";
                continue;
            }

            // Paragraph
            $paraText = $this->parseInlineElements($trimmedLine);
            $outputHtml[] = "<p>{$paraText}</p>";
        }

        if ($inCodeBlock) {
            $escapedCode = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $langAttr = $codeLang !== '' ? ' class="language-' . htmlspecialchars($codeLang, ENT_QUOTES, 'UTF-8') . '"' : '';
            $outputHtml[] = '<pre' . $langAttr . '><code>' . $escapedCode . '</code></pre>';
            $codeBlockCount++;
        }

        if ($inList) {
            $outputHtml[] = "</{$listType}>";
        }

        return [
            'html' => implode("\n", $outputHtml),
            'headingCount' => $headingCount,
            'lineCount' => $lineCount,
            'codeBlockCount' => $codeBlockCount,
        ];
    }

    /**
     * Parse inline markdown formatting (bold, italic, code, links) with HTML entity escaping.
     */
    public function parseInlineElements(string $text): string
    {
        // Step 1: Escape all raw HTML tags to prevent XSS
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Step 2: Inline code (`code`)
        $escaped = (string) preg_replace_callback('/`([^`]+)`/', function ($m) {
            return '<code>' . $m[1] . '</code>';
        }, $escaped);

        // Step 3: Bold (**text**)
        $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);

        // Step 4: Italic (*text*)
        $escaped = (string) preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped);

        // Step 5: Links ([text](url)) with URL sanitization
        $escaped = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($matches) {
            $linkText = $matches[1];
            $url = $this->sanitizeUrl(html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8'));

            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
                $linkText
            );
        }, $escaped);

        return $escaped;
    }

    /**
     * Sanitize link URL protocols to prevent javascript:, data:, or vbscript: injection.
     */
    public function sanitizeUrl(string $url): string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return '#';
        }

        // Allow relative path links or anchor links
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '?')) {
            return $trimmed;
        }

        // Parse protocol scheme
        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if (in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return $trimmed;
        }

        // Block dangerous schemes (javascript:, data:, vbscript:)
        return '#';
    }
}
