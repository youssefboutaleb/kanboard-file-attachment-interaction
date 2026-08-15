<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\CsvDelimiterRegistry;
use Kanboard\Plugin\FileInteractionCore\Service\CsvParserService;
use PHPUnit\Framework\TestCase;

/**
 * Task 37: the delimiter registry backing the CSV preview picker.
 */
class CsvDelimiterRegistryTest extends TestCase
{
    private CsvDelimiterRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CsvDelimiterRegistry();
    }

    /**
     * Every option named in the Task 37 requirement must be offered.
     */
    public function testOffersEveryRequiredDelimiterOption(): void
    {
        $options = $this->registry->getOptions();

        foreach (['auto', 'comma', 'semicolon', 'tab', 'pipe'] as $required) {
            $this->assertArrayHasKey($required, $options, sprintf('The picker must offer %s.', $required));
        }

        $this->assertSame('Auto-detect', $options['auto']);
        $this->assertStringContainsString(',', $options['comma']);
        $this->assertStringContainsString(';', $options['semicolon']);
        $this->assertStringContainsString('Tab', $options['tab']);
        $this->assertStringContainsString('|', $options['pipe']);
    }

    /**
     * Auto-detect must be the first option, so it reads as the default.
     */
    public function testAutoDetectIsTheFirstOption(): void
    {
        $this->assertSame('auto', array_key_first($this->registry->getOptions()));
    }

    /**
     * @dataProvider tokenDelimiterProvider
     */
    public function testResolvesTokenToDelimiterCharacter(string $token, ?string $expected): void
    {
        $this->assertSame($expected, $this->registry->resolveDelimiter($token));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function tokenDelimiterProvider(): array
    {
        return [
            'comma' => ['comma', ','],
            'semicolon' => ['semicolon', ';'],
            'tab' => ['tab', "\t"],
            'pipe' => ['pipe', '|'],
            // null hands the decision back to the sniffer.
            'auto sniffs' => ['auto', null],
        ];
    }

    /**
     * The `delimiter` request parameter is untrusted. Anything unrecognised must
     * collapse to auto-detection rather than reaching str_getcsv().
     *
     * @dataProvider hostileTokenProvider
     */
    public function testHostileTokensCollapseToAutoDetection(string $hostile): void
    {
        $this->assertSame('auto', $this->registry->normalizeToken($hostile));
        $this->assertNull(
            $this->registry->resolveDelimiter($hostile),
            'A rejected token must not resolve to a delimiter character.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileTokenProvider(): array
    {
        return [
            'raw comma character' => [','],
            'raw tab character' => ["\t"],
            'regex metacharacter' => ['.*'],
            'multi-character string' => ['comma;semicolon'],
            'script tag' => ['<script>alert(1)</script>'],
            'quote break out' => ['" onmouseover="alert(1)'],
            'traversal' => ['../../etc/passwd'],
            'unknown token' => ['colon'],
            'empty string' => [''],
        ];
    }

    public function testNullTokenIsAutoDetection(): void
    {
        $this->assertSame('auto', $this->registry->normalizeToken(null));
        $this->assertNull($this->registry->resolveDelimiter(null));
        $this->assertTrue($this->registry->isAuto(null));
    }

    public function testNormalizeIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertSame('semicolon', $this->registry->normalizeToken('  SEMICOLON  '));
        $this->assertSame('tab', $this->registry->normalizeToken('Tab'));
    }

    public function testIsAutoOnlyForTheAutoToken(): void
    {
        $this->assertTrue($this->registry->isAuto('auto'));
        $this->assertFalse($this->registry->isAuto('comma'));
        $this->assertFalse($this->registry->isAuto('tab'));
    }

    /**
     * @dataProvider reverseLookupProvider
     */
    public function testReverseLookupNamesTheDetectedDelimiter(string $delimiter, string $expectedToken): void
    {
        $this->assertSame($expectedToken, $this->registry->getTokenForDelimiter($delimiter));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reverseLookupProvider(): array
    {
        return [
            'comma' => [',', 'comma'],
            'semicolon' => [';', 'semicolon'],
            'tab' => ["\t", 'tab'],
            'pipe' => ['|', 'pipe'],
            'unknown character defaults to comma' => [':', 'comma'],
        ];
    }

    /**
     * A raw tab is unreadable in a badge, so it gets a label.
     */
    public function testTabIsLabelledRatherThanPrintedRaw(): void
    {
        $this->assertSame('TAB', $this->registry->getDelimiterLabel("\t"));
        $this->assertSame(',', $this->registry->getDelimiterLabel(','));
        $this->assertSame('|', $this->registry->getDelimiterLabel('|'));
    }

    /**
     * Offering a delimiter the sniffer would never return would make the picker
     * inconsistent with auto-detection, so the two lists must stay aligned.
     */
    public function testTokenCharactersMatchTheParserCandidateList(): void
    {
        $tokenCharacters = array_values(CsvDelimiterRegistry::TOKEN_DELIMITERS);

        $this->assertEqualsCanonicalizing(
            CsvParserService::CANDIDATE_DELIMITERS,
            $tokenCharacters,
            'Picker delimiters and CsvParserService::CANDIDATE_DELIMITERS have drifted apart.'
        );
    }

    /**
     * Every non-auto option must resolve to a real character, or the picker would
     * offer a choice that silently does nothing.
     */
    public function testEveryOfferedOptionResolvesOrIsAuto(): void
    {
        foreach (array_keys($this->registry->getOptions()) as $token) {
            if ($token === CsvDelimiterRegistry::AUTO) {
                continue;
            }

            $this->assertNotNull(
                $this->registry->resolveDelimiter($token),
                sprintf('Option "%s" does not resolve to a delimiter.', $token)
            );
        }
    }
}
