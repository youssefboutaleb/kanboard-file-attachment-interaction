<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\SyntaxLanguageRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Task 36: the language registry backing the preview modal's syntax picker.
 */
class SyntaxLanguageRegistryTest extends TestCase
{
    private SyntaxLanguageRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SyntaxLanguageRegistry();
    }

    /**
     * Every language named in the Task 36 requirement must be offered.
     */
    public function testOffersEveryRequiredPickerOption(): void
    {
        $options = $this->registry->getOptions();

        foreach (['json', 'yaml', 'bash', 'python', 'sql', 'php', 'css', 'text'] as $required) {
            $this->assertArrayHasKey($required, $options, sprintf('The picker must offer %s.', $required));
        }

        $this->assertSame('JSON', $options['json']);
        $this->assertSame('YAML', $options['yaml']);
        $this->assertSame('Bash', $options['bash']);
        $this->assertSame('Python', $options['python']);
        $this->assertSame('SQL', $options['sql']);
        $this->assertSame('PHP', $options['php']);
        $this->assertSame('CSS', $options['css']);
        $this->assertSame('Plain Text', $options['text']);
    }

    /**
     * Default language inferred from the file extension.
     *
     * @dataProvider extensionDefaultProvider
     */
    public function testResolvesDefaultLanguageFromExtension(string $extension, string $expected): void
    {
        $this->assertSame($expected, $this->registry->resolveFromExtension($extension));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function extensionDefaultProvider(): array
    {
        return [
            '.json -> JSON' => ['json', 'json'],
            '.yaml -> YAML' => ['yaml', 'yaml'],
            '.yml -> YAML' => ['yml', 'yaml'],
            '.sh -> Bash' => ['sh', 'bash'],
            '.bash -> Bash' => ['bash', 'bash'],
            '.py -> Python' => ['py', 'python'],
            '.sql -> SQL' => ['sql', 'sql'],
            '.php -> PHP' => ['php', 'php'],
            '.css -> CSS' => ['css', 'css'],
            '.js -> JavaScript' => ['js', 'javascript'],
            '.xml -> Markup' => ['xml', 'markup'],
            '.html -> Markup' => ['html', 'markup'],
            '.env -> Config' => ['env', 'config'],
            '.ini -> Config' => ['ini', 'config'],
            '.conf -> Config' => ['conf', 'config'],
            '.txt -> Plain Text' => ['txt', 'text'],
            '.log -> Plain Text' => ['log', 'text'],
            'leading dot tolerated' => ['.json', 'json'],
            'uppercase tolerated' => ['JSON', 'json'],
            'unknown falls back to plain text' => ['bak', 'text'],
            'empty falls back to plain text' => ['', 'text'],
        ];
    }

    /**
     * Every extension the preview whitelist accepts must map to a real picker
     * option, or the modal could open on a selection that does not exist.
     */
    public function testEveryPreviewableTextExtensionMapsToAnOfferedOption(): void
    {
        $options = $this->registry->getOptions();

        // Binary and tabular formats never reach the picker.
        $nonTextExtensions = ['pdf', 'xlsx', 'xls', 'csv', 'tsv', 'md', 'markdown'];

        foreach (FileValidationService::ALLOWED_EXTENSIONS as $extension) {
            if (in_array($extension, $nonTextExtensions, true)) {
                continue;
            }

            $resolved = $this->registry->resolveFromExtension($extension);

            $this->assertArrayHasKey(
                $resolved,
                $options,
                sprintf('.%s resolves to "%s", which is not an offered option.', $extension, $resolved)
            );
        }
    }

    /**
     * The `lang` request parameter is untrusted and must never pass through
     * unvalidated.
     */
    public function testNormalizeRejectsUnknownAndHostileValues(): void
    {
        $this->assertNull($this->registry->normalize('klingon'));
        $this->assertNull($this->registry->normalize('<script>alert(1)</script>'));
        $this->assertNull($this->registry->normalize('../../etc/passwd'));
        $this->assertNull($this->registry->normalize(''));
        $this->assertNull($this->registry->normalize(null));
    }

    public function testNormalizeAcceptsKnownLanguagesCaseInsensitively(): void
    {
        $this->assertSame('python', $this->registry->normalize('Python'));
        $this->assertSame('sql', $this->registry->normalize('  SQL  '));
        $this->assertSame('text', $this->registry->normalize('text'));
    }

    public function testIsPlainTextOnlyForTheNeutralLanguage(): void
    {
        $this->assertTrue($this->registry->isPlainText(SyntaxLanguageRegistry::PLAIN_TEXT));
        $this->assertFalse($this->registry->isPlainText('python'));
        $this->assertFalse($this->registry->isPlainText('json'));
    }

    /**
     * Comment syntax is language specific — this is what makes switching the
     * language change the rendering rather than just a CSS class.
     */
    public function testCommentPrefixesAreLanguageSpecific(): void
    {
        $this->assertSame(['#'], $this->registry->getCommentPrefixes('python'));
        $this->assertSame(['#'], $this->registry->getCommentPrefixes('bash'));
        $this->assertSame(['--'], $this->registry->getCommentPrefixes('sql'));
        $this->assertContains('//', $this->registry->getCommentPrefixes('javascript'));
        // JSON has no comment syntax at all.
        $this->assertSame([], $this->registry->getCommentPrefixes('json'));
        // Config files use both # and ;
        $this->assertSame(['#', ';'], $this->registry->getCommentPrefixes('config'));
    }

    /**
     * Markup comments are matched against already-escaped content.
     */
    public function testMarkupCommentPrefixIsEntityEscaped(): void
    {
        $this->assertSame(['&lt;!--'], $this->registry->getCommentPrefixes('markup'));
    }

    public function testKeywordSetsDifferPerLanguage(): void
    {
        $this->assertContains('def', $this->registry->getKeywords('python'));
        $this->assertNotContains('def', $this->registry->getKeywords('sql'));

        $this->assertContains('select', $this->registry->getKeywords('sql'));
        $this->assertNotContains('select', $this->registry->getKeywords('python'));

        $this->assertContains('fi', $this->registry->getKeywords('bash'));
        $this->assertSame([], $this->registry->getKeywords('text'));
    }

    public function testGetLabelFallsBackForUnknownLanguage(): void
    {
        $this->assertSame('Python', $this->registry->getLabel('python'));
        $this->assertSame('SH', $this->registry->getLabel('sh'));
    }
}
