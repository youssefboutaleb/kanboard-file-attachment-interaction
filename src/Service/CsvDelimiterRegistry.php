<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Canonical registry of delimiter choices offered by the CSV preview modal.
 *
 * Delimiters travel as opaque TOKENS (`comma`, `semicolon`, `tab`, `pipe`) rather
 * than as their literal characters. A raw tab or pipe in a query string survives
 * neither URL encoding nor HTML attribute escaping reliably, and accepting a raw
 * character would mean feeding an arbitrary request value straight into
 * str_getcsv(). Tokens are validated against this allow-list first, so only one of
 * four known characters ever reaches the parser.
 *
 * Deliberately separate from CsvParserService, which stays focused on parsing —
 * this mirrors how SyntaxLanguageRegistry backs the Task 36 language picker.
 */
class CsvDelimiterRegistry
{
    /**
     * Token meaning "let CsvParserService sniff the delimiter".
     */
    public const AUTO = 'auto';

    /**
     * Token to literal delimiter character.
     *
     * Kept aligned with CsvParserService::CANDIDATE_DELIMITERS: a token whose
     * character the sniffer would never return is a token the picker should not
     * offer.
     *
     * @var array<string, string>
     */
    public const TOKEN_DELIMITERS = [
        'comma' => ',',
        'semicolon' => ';',
        'tab' => "\t",
        'pipe' => '|',
    ];

    /**
     * Picker options as `token => label`, in render order.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return [
            self::AUTO => 'Auto-detect',
            'comma' => 'Comma ( , )',
            'semicolon' => 'Semicolon ( ; )',
            'tab' => 'Tab ( \t )',
            'pipe' => 'Pipe ( | )',
        ];
    }

    /**
     * Validate a user-supplied token, falling back to AUTO.
     *
     * Never trust the `delimiter` request parameter — anything unrecognised
     * becomes auto-detection rather than reaching the parser.
     */
    public function normalizeToken(?string $token): string
    {
        if ($token === null) {
            return self::AUTO;
        }

        $normalized = strtolower(trim($token));

        if ($normalized === self::AUTO || isset(self::TOKEN_DELIMITERS[$normalized])) {
            return $normalized;
        }

        return self::AUTO;
    }

    /**
     * Resolve a token to the delimiter character, or null to auto-detect.
     *
     * Null is what CsvParserService::parse() already treats as "sniff it".
     */
    public function resolveDelimiter(?string $token): ?string
    {
        $normalized = $this->normalizeToken($token);

        return self::TOKEN_DELIMITERS[$normalized] ?? null;
    }

    public function isAuto(?string $token): bool
    {
        return $this->normalizeToken($token) === self::AUTO;
    }

    /**
     * Reverse lookup: the token naming a delimiter character.
     *
     * Used to report which delimiter auto-detection actually settled on.
     */
    public function getTokenForDelimiter(string $delimiter): string
    {
        $token = array_search($delimiter, self::TOKEN_DELIMITERS, true);

        return $token === false ? 'comma' : $token;
    }

    /**
     * Human-readable label for a delimiter character, for the modal's badge.
     */
    public function getDelimiterLabel(string $delimiter): string
    {
        return match ($delimiter) {
            "\t" => 'TAB',
            ',' => ',',
            ';' => ';',
            '|' => '|',
            default => $delimiter,
        };
    }
}
