<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Service\SyntaxLanguageRegistry;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';

/**
 * Task 36: the rendered picker markup and the binary notice.
 */
class LanguageSelectorTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function renderPreview(string $template, array $overrides = []): string
    {
        $registry = new SyntaxLanguageRegistry();
        $renderer = new FakeTemplateRenderer();

        return $renderer->renderPluginTemplate($template, array_merge([
            'projectId' => 7,
            'taskId' => 3,
            'fileId' => 42,
            'filename' => 'notes.txt',
            'extension' => 'txt',
            'handler' => 'TextPreviewHandler',
            'content' => 'body text',
            'isFormatted' => false,
            'metadata' => ['lineCount' => 1, 'charCount' => 9],
            'languageOptions' => $registry->getOptions(),
            'selectedLanguage' => 'text',
            'languageSelectorEnabled' => true,
            'languageParams' => [
                'plugin' => 'FileInteractionCore',
                'project_id' => 7,
                'task_id' => 3,
                'file_id' => 42,
            ],
        ], $overrides));
    }

    /**
     * @return list<string>
     */
    private function optionValues(string $html): array
    {
        preg_match_all('/<option value="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    // ------------------------------------------------------------------
    // Picker rendering
    // ------------------------------------------------------------------

    public function testPickerRendersEveryRequiredLanguageOption(): void
    {
        $html = $this->renderPreview('file/preview');

        foreach (['JSON', 'YAML', 'Bash', 'Python', 'SQL', 'PHP', 'CSS', 'Plain Text'] as $label) {
            $this->assertStringContainsString('>' . $label, $html, $label . ' must appear in the picker.');
        }
    }

    public function testPickerCarriesTheHookAttributeTheScriptListensOn(): void
    {
        $html = $this->renderPreview('file/preview');

        $this->assertStringContainsString('data-fic-language-select', $html);
        $this->assertStringContainsString('<select', $html);
    }

    /**
     * Each option carries a complete preview URL, which is what lets the script
     * hand it straight to KB.modal.replace().
     */
    public function testEachOptionCarriesItsOwnPreviewUrlWithLangParameter(): void
    {
        $values = $this->optionValues($this->renderPreview('file/preview'));

        $this->assertNotSame([], $values);

        foreach ($values as $url) {
            $this->assertStringContainsString('controller=FilePreviewController', $url);
            $this->assertStringContainsString('action=show', $url);
            $this->assertStringContainsString('lang=', $url);
            // Kanboard's Router needs the plugin param to dispatch to us.
            $this->assertStringContainsString('plugin=FileInteractionCore', $url);
            $this->assertStringContainsString('file_id=42', $url);
        }
    }

    public function testOptionUrlsCoverEveryOfferedLanguage(): void
    {
        $values = $this->optionValues($this->renderPreview('file/preview'));
        $registry = new SyntaxLanguageRegistry();

        foreach (array_keys($registry->getOptions()) as $languageId) {
            $matching = array_filter(
                $values,
                static fn (string $url): bool => str_contains($url, 'lang=' . $languageId)
            );

            $this->assertNotSame([], $matching, 'No option URL for ' . $languageId);
        }
    }

    public function testCurrentLanguageIsMarkedSelected(): void
    {
        $html = $this->renderPreview('file/preview', ['selectedLanguage' => 'python']);

        $this->assertSame(
            1,
            preg_match('/<option value="[^"]*lang=python"\s+selected="selected"/', $html),
            'The active language must be the selected option.'
        );
    }

    public function testOnlyOneOptionIsSelected(): void
    {
        $html = $this->renderPreview('file/preview', ['selectedLanguage' => 'sql']);

        $this->assertSame(1, substr_count($html, 'selected="selected"'));
    }

    public function testPickerIsOmittedWhenDisabled(): void
    {
        $html = $this->renderPreview('file/preview', ['languageSelectorEnabled' => false]);

        $this->assertStringNotContainsString('data-fic-language-select', $html);
    }

    /**
     * A project attachment must keep its source flag across a picker round trip.
     */
    public function testProjectSourceSurvivesInOptionUrls(): void
    {
        $html = $this->renderPreview('file/preview', [
            'languageParams' => [
                'plugin' => 'FileInteractionCore',
                'project_id' => 9,
                'task_id' => 0,
                'file_id' => 55,
                'source' => 'project',
            ],
        ]);

        foreach ($this->optionValues($html) as $url) {
            $this->assertStringContainsString('source=project', $url);
        }
    }

    public function testPickerAlsoRendersInTheHighlightedCodeView(): void
    {
        $html = $this->renderPreview('file/markdown_preview', [
            'handler' => 'CodePreviewHandler',
            'content' => '<pre class="code-highlight language-python"><code>pass</code></pre>',
            'metadata' => [
                'language' => 'python',
                'languageId' => 'python',
                'languageLabel' => 'Python',
                'lineCount' => 1,
                'charCount' => 4,
            ],
            'selectedLanguage' => 'python',
        ]);

        $this->assertStringContainsString('data-fic-language-select', $html);
        // v0.7.1: the inline language badge ("PYTHON") was deleted as an internal
        // technical label. The picker itself is now the only place the language is
        // shown, and the friendly type name lives in the action bar.
        $this->assertStringNotContainsString('>PYTHON<', $html);
        $this->assertStringContainsString('Python', $html);
    }

    /**
     * Task 36 flags a preview reached by content inspection rather than by the
     * extension whitelist.
     */
    public function testDetectedTextBadgeIsShownForInspectedAttachments(): void
    {
        $html = $this->renderPreview('file/preview', [
            'filename' => 'LICENSE',
            'extension' => '',
            'metadata' => ['detectedAsText' => true, 'lineCount' => 1, 'charCount' => 4],
        ]);

        $this->assertStringContainsString('Detected Text', $html);
    }

    public function testDetectedTextBadgeIsAbsentForWhitelistedExtensions(): void
    {
        $html = $this->renderPreview('file/preview');

        $this->assertStringNotContainsString('Detected Text', $html);
    }

    // ------------------------------------------------------------------
    // Binary notice
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     */
    private function renderBinaryNotice(array $overrides = []): string
    {
        $renderer = new FakeTemplateRenderer();

        return $renderer->renderPluginTemplate('file/binary_notice', array_merge([
            'projectId' => 7,
            'taskId' => 3,
            'fileId' => 42,
            'filename' => 'bundle.zip',
            'extension' => 'zip',
            'handler' => 'BinaryNotice',
            'content' => '',
            'isFormatted' => false,
            'metadata' => [
                'isBinary' => true,
                'reason' => 'null_byte',
                'sizeBytes' => 20480,
                'sniffedBytes' => 8192,
                'controlRatio' => 0.0,
                'maxSizeBytes' => 10485760,
            ],
        ], $overrides));
    }

    public function testBinaryNoticeShowsTheRequiredMessage(): void
    {
        $html = $this->renderBinaryNotice();

        $this->assertStringContainsString('Binary File (Preview not supported, click Download)', $html);
    }

    public function testBinaryNoticeOffersADownloadAction(): void
    {
        $html = $this->renderBinaryNotice();

        $this->assertSame(
            1,
            preg_match('/<a href="([^"]*)"[^>]*class="btn btn-blue"/', $html, $matches),
            'A download button must be present.'
        );

        $this->assertStringContainsString('controller=FileViewerController', $matches[1]);
        $this->assertStringContainsString('action=download', $matches[1]);
        $this->assertStringContainsString('file_id=42', $matches[1]);
    }

    /**
     * The notice must never offer inline viewing — the payload is unclassified.
     */
    public function testBinaryNoticeDoesNotOfferInlineViewing(): void
    {
        $html = $this->renderBinaryNotice();

        $this->assertStringNotContainsString('action=inline', $html);
        $this->assertStringNotContainsString('action=browser', $html);
        $this->assertStringNotContainsString('<object', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    /**
     * @dataProvider binaryReasonProvider
     */
    public function testBinaryNoticeExplainsTheDetectionReason(string $reason, string $expected): void
    {
        $html = $this->renderBinaryNotice([
            'metadata' => [
                'isBinary' => true,
                'reason' => $reason,
                'sizeBytes' => 1024,
                'sniffedBytes' => 1024,
                'controlRatio' => 0.5,
                'maxSizeBytes' => 10485760,
            ],
        ]);

        $this->assertStringContainsString($expected, $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function binaryReasonProvider(): array
    {
        return [
            'null byte' => ['null_byte', 'null bytes'],
            'control characters' => ['control_characters', 'non-printable control characters'],
            'invalid encoding' => ['invalid_encoding', 'not valid UTF-8'],
            'too large' => ['too_large', 'too large to inspect safely'],
        ];
    }

    public function testBinaryNoticeEscapesTheFilename(): void
    {
        $html = $this->renderBinaryNotice([
            'filename' => '<img src=x onerror=alert(1)>.zip',
        ]);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function testBinaryNoticeLabelsAMissingExtension(): void
    {
        $html = $this->renderBinaryNotice(['filename' => 'coredump', 'extension' => '']);

        $this->assertStringContainsString('No Extension', $html);
    }

    /**
     * The picker script must ship as a registered asset, not inline, or CSP
     * silently blocks it.
     */
    public function testPickerScriptIsARegisteredAssetNotInline(): void
    {
        $selectorTemplate = (string) file_get_contents(__DIR__ . '/../../Template/file/language_selector.php');
        $plugin = (string) file_get_contents(__DIR__ . '/../../Plugin.php');

        $this->assertStringNotContainsString('<script', $selectorTemplate);
        $this->assertStringContainsString('plugins/FileInteractionCore/Assets/js/preview-controls.js', $plugin);
        $this->assertFileExists(__DIR__ . '/../../Assets/js/preview-controls.js');

        // preview-language-selector.js was a verbatim second copy of the picker
        // handler. Both files bound their own delegated `change` listener on the
        // document, so one language change fired TWO KB.modal.replace() calls and
        // the modal was fetched and rebuilt twice. Registering it again would
        // silently restore that.
        $this->assertStringNotContainsString('preview-language-selector.js', $plugin);
        $this->assertFileDoesNotExist(__DIR__ . '/../../Assets/js/preview-language-selector.js');
    }

    /**
     * Pin the operations the shipped picker script performs. Its DOM behaviour is
     * not executable in the php:8.1-cli test container, so — as with the Task 35
     * cleanup script — the essentials are pinned textually and confirmed by a
     * manual browser pass.
     */
    public function testPickerScriptUsesModalReplaceWithNavigationFallback(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/preview-controls.js');

        $this->assertStringContainsString('data-fic-language-select', $script);
        $this->assertStringContainsString("addEventListener('change'", $script);
        $this->assertStringContainsString('KB.modal.replace', $script);
        $this->assertStringContainsString('window.location.href', $script);
    }
}
