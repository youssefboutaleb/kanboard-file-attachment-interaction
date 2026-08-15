<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Stubs;

/**
 * Helper for asserting on what a PHP template actually EMITS.
 *
 * Several templates document the "no inline `<script>`" rule in prose that
 * necessarily names the tag. A naive substring check over the raw file would flag
 * that explanation, so comments are stripped before the assertion runs.
 */
trait InspectsPhpSource
{
    /**
     * Return the source with all comment tokens removed.
     */
    protected function executablePhpSource(string $path): string
    {
        $kept = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * Return a JS file's source with comment lines removed.
     *
     * The plugin's scripts document the CSP/innerHTML defect in prose that quotes
     * the very constructs the assertions forbid, so those comments have to be
     * excluded before checking what the file actually does.
     *
     * Line-based on purpose: every comment in these files is either a `//` line or a
     * block whose continuation lines start with `*`. That is simple enough to be
     * obviously correct, unlike a general JS tokenizer — but it means a `//` inside
     * a string literal would also be dropped, so keep assertions to code patterns
     * rather than exact text.
     */
    protected function executableJsSource(string $path): string
    {
        $kept = [];

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*/')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }
}
