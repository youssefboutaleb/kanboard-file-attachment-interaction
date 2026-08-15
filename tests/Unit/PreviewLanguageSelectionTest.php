<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/FakeContainer.php';

/**
 * Task 36: dynamic language selection through the preview controller.
 *
 * Switching language is a server-side round trip driven by the `lang` request
 * parameter, so these tests exercise it exactly as the modal does.
 */
class PreviewLanguageSelectionTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $file
     */
    private function buildContainer(
        array $params,
        array $file,
        string $content,
        FakeTemplate $template,
        FakeResponse $response
    ): FakeContainer {
        return new FakeContainer([
            'request' => new FakeRequest($params),
            'response' => $response,
            'template' => $template,
            'taskFileModel' => new FakeFileModel($file),
            'objectStorage' => new FakeObjectStorage($content),
        ]);
    }

    private function controller(FakeContainer $container): FilePreviewController
    {
        return new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
    }

    // ------------------------------------------------------------------
    // Default language from extension
    // ------------------------------------------------------------------

    /**
     * @dataProvider extensionDefaultProvider
     */
    public function testDefaultSelectedLanguageComesFromExtension(string $filename, string $expected): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1],
            ['name' => $filename, 'path' => 'tasks/1/f', 'task_id' => 1],
            "key: value\n",
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertSame($expected, $template->renderedVars['selectedLanguage']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function extensionDefaultProvider(): array
    {
        return [
            '.json opens on JSON' => ['config.json', 'json'],
            '.yml opens on YAML' => ['deploy.yml', 'yaml'],
            '.yaml opens on YAML' => ['deploy.yaml', 'yaml'],
            '.sh opens on Bash' => ['run.sh', 'bash'],
            '.py opens on Python' => ['script.py', 'python'],
            '.sql opens on SQL' => ['query.sql', 'sql'],
            '.php opens on PHP' => ['index.php', 'php'],
            '.css opens on CSS' => ['style.css', 'css'],
            '.env opens on Config' => ['.env.local.env', 'config'],
            '.txt opens on Plain Text' => ['notes.txt', 'text'],
        ];
    }

    public function testSelectorIsEnabledWithTheFullOptionListForTextViews(): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'config.json', 'path' => 'tasks/1/f', 'task_id' => 1],
            '{"a":1}',
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertTrue($template->renderedVars['languageSelectorEnabled']);

        $options = $template->renderedVars['languageOptions'];
        foreach (['json', 'yaml', 'bash', 'python', 'sql', 'php', 'css', 'text'] as $required) {
            $this->assertArrayHasKey($required, $options);
        }
    }

    /**
     * The picker has no meaning for tabular, spreadsheet or PDF views.
     *
     * @dataProvider nonTextViewProvider
     */
    public function testSelectorIsDisabledForNonTextViews(string $filename, string $content): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1],
            ['name' => $filename, 'path' => 'tasks/1/f', 'task_id' => 1],
            $content,
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertFalse($template->renderedVars['languageSelectorEnabled']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonTextViewProvider(): array
    {
        return [
            'csv table' => ['data.csv', "a,b\n1,2"],
            'excel grid' => ['book.xlsx', 'PK payload'],
            'pdf viewer' => ['doc.pdf', '%PDF-1.4'],
        ];
    }

    // ------------------------------------------------------------------
    // Switching language on the fly
    // ------------------------------------------------------------------

    /**
     * The picker's whole purpose: highlight a file as a language its extension
     * does not imply.
     */
    public function testExplicitLanguageOverridesTheExtensionDefault(): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1, 'lang' => 'python'],
            ['name' => 'notes.txt', 'path' => 'tasks/1/f', 'task_id' => 1],
            "def hello():\n    return 1\n",
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertSame('python', $template->renderedVars['selectedLanguage']);
        $this->assertSame('CodePreviewHandler', $template->renderedVars['handler']);
        $this->assertSame('python', $template->renderedVars['metadata']['languageId']);
        $this->assertSame('Python', $template->renderedVars['metadata']['languageLabel']);
        // Highlighted, so it renders through the rich view.
        $this->assertSame('FileInteractionCore:file/markdown_preview', $template->renderedTemplate);
    }

    /**
     * Choosing "Plain Text" must drop highlighting entirely and route to the
     * escaped text view, even for a file whose extension is a code format.
     */
    public function testPlainTextSelectionRoutesToTheEscapedTextView(): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1, 'lang' => 'text'],
            ['name' => 'script.py', 'path' => 'tasks/1/f', 'task_id' => 1],
            "def hello():\n    return 1\n",
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertSame('text', $template->renderedVars['selectedLanguage']);
        $this->assertSame('TextPreviewHandler', $template->renderedVars['handler']);
        $this->assertSame('FileInteractionCore:file/preview', $template->renderedTemplate);
        // No highlight spans in an escaped plain-text render.
        $this->assertStringNotContainsString('tok-keyword', (string) $template->renderedVars['content']);
    }

    /**
     * Switching language must change the actual rendering, not merely a badge:
     * `#` is a comment in Bash but not in JSON.
     */
    public function testSwitchingLanguageChangesCommentTokenisation(): void
    {
        $source = "# not a json comment\nvalue\n";

        $asBash = $this->renderWithLanguage($source, 'notes.txt', 'bash');
        $asJson = $this->renderWithLanguage($source, 'notes.txt', 'json');

        $this->assertStringContainsString('tok-comment', $asBash, 'Bash treats # as a comment.');
        $this->assertStringNotContainsString('tok-comment', $asJson, 'JSON has no comment syntax.');
    }

    /**
     * SQL uses `--` where shells use `#`.
     */
    public function testSqlRecognisesDoubleDashComments(): void
    {
        $source = "-- select everything\nSELECT 1\n";

        $asSql = $this->renderWithLanguage($source, 'query.sql', 'sql');
        $asPython = $this->renderWithLanguage($source, 'query.sql', 'python');

        $this->assertStringContainsString('tok-comment', $asSql);
        $this->assertStringNotContainsString('tok-comment', $asPython);
    }

    /**
     * Keyword sets are per language, so a SQL keyword is not highlighted in
     * Python and vice versa.
     */
    public function testKeywordHighlightingIsLanguageSpecific(): void
    {
        $asSql = $this->renderWithLanguage("SELECT id FROM users\n", 'q.txt', 'sql');
        $this->assertStringContainsString('tok-keyword', $asSql);

        $asCss = $this->renderWithLanguage("SELECT id FROM users\n", 'q.txt', 'css');
        $this->assertStringNotContainsString('tok-keyword', $asCss);
    }

    private function renderWithLanguage(string $content, string $filename, string $language): string
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1, 'lang' => $language],
            ['name' => $filename, 'path' => 'tasks/1/f', 'task_id' => 1],
            $content,
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        return (string) $template->renderedVars['content'];
    }

    // ------------------------------------------------------------------
    // Parameter safety
    // ------------------------------------------------------------------

    /**
     * The `lang` parameter is untrusted: an unknown value must fall back to the
     * extension default rather than reaching the highlighter.
     *
     * @dataProvider hostileLanguageProvider
     */
    public function testUnknownLanguageFallsBackToExtensionDefault(string $hostile): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1, 'lang' => $hostile],
            ['name' => 'config.json', 'path' => 'tasks/1/f', 'task_id' => 1],
            '{"a":1}',
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertSame('json', $template->renderedVars['selectedLanguage']);
        $this->assertStringNotContainsString($hostile, (string) $template->renderedVars['content']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileLanguageProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'traversal' => ['../../etc/passwd'],
            'quote break out' => ['" onmouseover="alert(1)'],
            'unknown language' => ['klingon'],
        ];
    }

    /**
     * A picker round trip must preserve the project-attachment flag, or the next
     * request would resolve the file through the wrong model.
     */
    public function testSelectorParamsPreserveProjectSource(): void
    {
        $template = new FakeTemplate();
        $container = new FakeContainer([
            'request' => new FakeRequest([
                'file_id' => 5,
                'task_id' => 0,
                'project_id' => 9,
                'source' => 'project',
            ]),
            'response' => new FakeResponse(),
            'template' => $template,
            'projectFileModel' => new FakeFileModel(['name' => 'notes.txt', 'path' => 'projects/9/f']),
            'objectStorage' => new FakeObjectStorage('plain text'),
        ]);

        $this->controller($container)->show();

        $params = $template->renderedVars['languageParams'];
        $this->assertSame('project', $params['source']);
        $this->assertSame('FileInteractionCore', $params['plugin']);
        $this->assertSame(5, $params['file_id']);
    }

    public function testSelectorParamsCarryPluginAndIdentifiers(): void
    {
        $template = new FakeTemplate();
        $container = $this->buildContainer(
            ['file_id' => 42, 'task_id' => 3, 'project_id' => 7],
            ['name' => 'notes.txt', 'path' => 'tasks/3/f', 'task_id' => 3],
            'plain',
            $template,
            new FakeResponse()
        );

        $this->controller($container)->show();

        $this->assertSame(
            ['plugin' => 'FileInteractionCore', 'project_id' => 7, 'task_id' => 3, 'file_id' => 42],
            $template->renderedVars['languageParams']
        );
    }
}
