<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Content sniffer deciding whether an attachment with an unknown or missing
 * extension can be shown as text.
 *
 * Used only for files the extension whitelist cannot classify. The verdict picks
 * between two safe outcomes — an escaped text preview or a "binary file, download
 * instead" notice — so a false positive costs a download prompt, never an unsafe
 * render.
 *
 * Detection is deliberately conservative and format-agnostic: no allow-list of
 * magic numbers to maintain, and nothing here ever executes or parses the payload.
 */
class BinaryContentDetector
{
    /**
     * Bytes inspected before making a decision.
     *
     * Bounded so a large attachment cannot turn detection into a memory or CPU
     * cost proportional to its size. `file(1)` uses a comparable window.
     */
    public const SNIFF_BYTES = 8192;

    /**
     * Share of control characters above which a sample is considered binary.
     *
     * Real text files carry almost none beyond tab/newline/carriage return; 10%
     * leaves generous room for stray formatting bytes without admitting binaries
     * that happen to lack a NUL in their header.
     */
    public const CONTROL_CHARACTER_THRESHOLD = 0.1;

    /**
     * Detection verdict plus the reason, which the notice template displays.
     *
     * @return array{binary: bool, reason: string, sniffedBytes: int, controlRatio: float}
     */
    public function inspect(string $content): array
    {
        $sample = substr($content, 0, self::SNIFF_BYTES);
        $sampleLength = strlen($sample);

        if ($sampleLength === 0) {
            // An empty file has nothing binary about it; the text view renders an
            // "empty file" notice, which is more useful than a download prompt.
            return $this->verdict(false, 'empty', 0, 0.0);
        }

        // A NUL byte is the single strongest signal and what `file(1)` keys on:
        // no text encoding this plugin previews emits one.
        if (str_contains($sample, "\0")) {
            return $this->verdict(true, 'null_byte', $sampleLength, 0.0);
        }

        $controlRatio = $this->controlCharacterRatio($sample);

        if ($controlRatio > self::CONTROL_CHARACTER_THRESHOLD) {
            return $this->verdict(true, 'control_characters', $sampleLength, $controlRatio);
        }

        // Invalid UTF-8 is checked last and only on a truncation-safe sample:
        // slicing at SNIFF_BYTES can cut a multi-byte sequence in half, so a
        // trailing partial character must not be mistaken for binary content.
        if (!$this->isValidUtf8IgnoringTruncation($sample, strlen($content) > $sampleLength)) {
            return $this->verdict(true, 'invalid_encoding', $sampleLength, $controlRatio);
        }

        return $this->verdict(false, 'text', $sampleLength, $controlRatio);
    }

    /**
     * Convenience predicate over inspect().
     */
    public function isBinary(string $content): bool
    {
        return $this->inspect($content)['binary'];
    }

    /**
     * Proportion of bytes that are control characters other than tab, newline,
     * carriage return, form feed and escape.
     */
    private function controlCharacterRatio(string $sample): float
    {
        $length = strlen($sample);

        if ($length === 0) {
            return 0.0;
        }

        $controlCount = 0;

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($sample[$i]);

            // Tab (9), LF (10), FF (12), CR (13) and ESC (27) are legitimate in
            // text files — the last covers ANSI-coloured log attachments.
            if ($byte === 9 || $byte === 10 || $byte === 12 || $byte === 13 || $byte === 27) {
                continue;
            }

            // C0 controls plus DEL.
            if ($byte < 32 || $byte === 127) {
                $controlCount++;
            }
        }

        return $controlCount / $length;
    }

    /**
     * Validate UTF-8, tolerating a multi-byte character clipped by the sample
     * boundary.
     *
     * Only relevant when the sample is a prefix of a longer file; a complete file
     * that fails validation really is invalid.
     */
    private function isValidUtf8IgnoringTruncation(string $sample, bool $isTruncated): bool
    {
        if (mb_check_encoding($sample, 'UTF-8')) {
            return true;
        }

        if (!$isTruncated) {
            return false;
        }

        // Drop up to 3 trailing bytes — the longest UTF-8 sequence tail — and
        // accept the sample if that repairs it.
        for ($trim = 1; $trim <= 3 && $trim < strlen($sample); $trim++) {
            if (mb_check_encoding(substr($sample, 0, -$trim), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{binary: bool, reason: string, sniffedBytes: int, controlRatio: float}
     */
    private function verdict(bool $binary, string $reason, int $sniffedBytes, float $controlRatio): array
    {
        return [
            'binary' => $binary,
            'reason' => $reason,
            'sniffedBytes' => $sniffedBytes,
            'controlRatio' => round($controlRatio, 4),
        ];
    }
}
