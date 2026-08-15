<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeContainer.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';

/**
 * v0.7.1 requirement 3: the Rendered / Raw view mode toggle.
 *
 * Driven through the real controller so the routing under test is the production
 * one, then rendered with the real templates.
 */
class ViewModeToggleTest extends TestCase
{
    /**
     * Run the controller and return both its view variables and the rendered HTML.
     *
     * @param array<string, mixed> $params
     * @return array{vars: array<string, mixed>, template: string, html: string}
     */
    private function preview(string $filename, string $content, array $params = []): array
    {
        $template = new FakeTemplate();

        $container = new FakeContainer([
            'request' => new FakeRequest($params + ['file_id' => 42, 'task_id' => 3, 'project_id' => 7]),
            'response' => new FakeResponse(),
            'template' => $template,
            'taskFileModel' => new FakeFileModel(['name' => $filename, 'path' => 'tasks/3/f', 'task_id' => 3]),
            'objectStorage' => new FakeObjectStorage($content),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $renderer = new FakeTemplateRenderer();
        [, $path] = explode(':', $template->renderedTemplate, 2);

        return [
            'vars' => $template->renderedVars,
            'template' => $template->renderedTemplate,
            'html' => $renderer->renderPluginTemplate($path, $template->renderedVars),
        ];
    }

    // ------------------------------------------------------------------
    // Toggle availability
    // ------------------------------------------------------------------

    /**
     * @dataProvider richFormatProvider
     */
    public function testToggleIsOfferedForRichFormats(string $filename, string $content): void
    {
        $result = $this->preview($filename, $content);

        $this->assertTrue($result['vars']['rawViewAvailable'], $filename . ' has a rich rendering to toggle.');
        $this->assertStringContainsString('data-fic-view-mode', $result['html']);
        $this->assertStringContainsString('Rendered', $result['html']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function richFormatProvider(): array
    {
        return [
            'markdown' => ['README.md', "# Title\n\ntext\n"],
            'csv' => ['data.csv', "a,b\n1,2\n"],
            'shell script' => ['run.sh', "#!/bin/sh\necho hi\n"],
            'html' => ['page.html', "<p>hi</p>\n"],
        ];
    }

    /**
     * PDF has no text source, and plain text / JSON are already source — a toggle
     * there would be a no-op control.
     *
     * @dataProvider nonToggleFormatProvider
     */
    public function testToggleIsWithheldWhereThereIsNoRichRendering(string $filename, string $content): void
    {
        $result = $this->preview($filename, $content);

        $this->assertFalse($result['vars']['rawViewAvailable'], $filename . ' must not offer the toggle.');
        $this->assertStringNotContainsString('data-fic-view-mode', $result['html']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonToggleFormatProvider(): array
    {
        return [
            'pdf has no source view' => ['doc.pdf', '%PDF-1.4 payload'],
            'plain text is already raw' => ['notes.txt', "plain\n"],
            'json is already source' => ['config.json', '{"a":1}'],
        ];
    }

    // ------------------------------------------------------------------
    // Switching actually changes the rendering
    // ------------------------------------------------------------------

    /**
     * THE core behaviour: rendered markdown becomes highlighted source.
     */
    public function testRawViewShowsSourceInsteadOfRenderedMarkdown(): void
    {
        $source = "# Heading\n\nSome *emphasis* here.\n";

        $rendered = $this->preview('README.md', $source);
        $raw = $this->preview('README.md', $source, ['view' => 'raw']);

        // Rendered: real HTML produced by the markdown parser.
        $this->assertSame('MarkdownPreviewHandler', $rendered['vars']['handler']);
        $this->assertStringContainsString('<h1>Heading</h1>', (string) $rendered['vars']['content']);

        // Raw: the '#' is escaped source text, not a heading element.
        $this->assertSame('CodePreviewHandler', $raw['vars']['handler']);
        $this->assertStringNotContainsString('<h1>Heading</h1>', (string) $raw['vars']['content']);
        $this->assertStringContainsString('# Heading', (string) $raw['vars']['content']);
        $this->assertStringContainsString('code-highlight', (string) $raw['vars']['content']);
    }

    /**
     * A CSV in raw mode shows the delimited text, not a table.
     */
    public function testRawViewShowsCsvSourceInsteadOfATable(): void
    {
        $source = "id,name\n1,Alice\n";

        $rendered = $this->preview('data.csv', $source);
        $raw = $this->preview('data.csv', $source, ['view' => 'raw']);

        $this->assertSame('FileInteractionCore:file/csv_preview', $rendered['template']);
        $this->assertStringContainsString('<table', $rendered['html']);

        $this->assertSame('CodePreviewHandler', $raw['vars']['handler']);
        $this->assertStringNotContainsString('<table', $raw['html']);
        $this->assertStringContainsString('id,name', (string) $raw['vars']['content']);
    }

    /**
     * Raw content is still entity-escaped — switching view must not become a way to
     * inject markup.
     */
    public function testRawViewKeepsContentEscaped(): void
    {
        $raw = $this->preview('page.html', '<script>alert(1)</script>', ['view' => 'raw']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', (string) $raw['vars']['content']);
        $this->assertStringContainsString('&lt;script&gt;', (string) $raw['vars']['content']);
    }

    // ------------------------------------------------------------------
    // The toggle control itself
    // ------------------------------------------------------------------

    /**
     * The toggle points at the mode it would switch TO, so the server always
     * renders the correct next target and the control keeps no state.
     */
    public function testToggleLinksToTheOppositeMode(): void
    {
        $rendered = $this->preview('README.md', "# Title\n");
        $this->assertSame(
            1,
            preg_match('/<a href="([^"]*)"\s*\n?\s*class="[^"]*fic-btn-view-mode[^"]*"/s', $rendered['html'], $onMatch)
        );
        $this->assertStringContainsString('view=raw', $onMatch[1], 'While rendered, the toggle links to raw.');

        $raw = $this->preview('README.md', "# Title\n", ['view' => 'raw']);
        $this->assertSame(
            1,
            preg_match('/<a href="([^"]*)"\s*\n?\s*class="[^"]*fic-btn-view-mode[^"]*"/s', $raw['html'], $offMatch)
        );
        $this->assertStringContainsString('view=rendered', $offMatch[1], 'While raw, the toggle links back.');
    }

    /**
     * The toggle must survive its own use: after switching to raw the handler is
     * CodePreviewHandler, which would hide the control if availability were keyed
     * off the handler actually in use rather than the one that renders.
     */
    public function testToggleRemainsAvailableInRawMode(): void
    {
        $raw = $this->preview('README.md', "# Title\n", ['view' => 'raw']);

        $this->assertTrue($raw['vars']['rawViewAvailable'], 'The toggle must not vanish once used.');
        $this->assertSame('raw', $raw['vars']['viewMode']);
        $this->assertStringContainsString('data-fic-view-mode="raw"', $raw['html']);
        $this->assertStringContainsString('fa-toggle-off', $raw['html']);
    }

    public function testRenderedModeShowsTheToggleAsOn(): void
    {
        $rendered = $this->preview('README.md', "# Title\n");

        $this->assertSame('rendered', $rendered['vars']['viewMode']);
        $this->assertStringContainsString('data-fic-view-mode="rendered"', $rendered['html']);
        $this->assertStringContainsString('fa-toggle-on', $rendered['html']);
    }

    /**
     * An unrecognised `view` value must fall back to the rendered view and never
     * reach the output.
     *
     * @dataProvider hostileViewProvider
     */
    public function testHostileViewParameterIsRejected(string $hostile): void
    {
        $result = $this->preview('README.md', "# Title\n", ['view' => $hostile]);

        $this->assertSame('rendered', $result['vars']['viewMode']);
        $this->assertStringContainsString('<h1>Title</h1>', (string) $result['vars']['content']);
        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringNotContainsString('onmouseover', $result['html']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileViewProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'quote break out' => ['" onmouseover="alert(1)'],
            'unknown mode' => ['source'],
        ];
    }

    public function testProjectSourceSurvivesTheToggleRoundTrip(): void
    {
        $template = new FakeTemplate();

        $container = new FakeContainer([
            'request' => new FakeRequest([
                'file_id' => 55,
                'task_id' => 0,
                'project_id' => 9,
                'source' => 'project',
            ]),
            'response' => new FakeResponse(),
            'template' => $template,
            'projectFileModel' => new FakeFileModel(['name' => 'README.md', 'path' => 'projects/9/f']),
            'objectStorage' => new FakeObjectStorage("# Title\n"),
        ]);

        (new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true))))->show();

        $this->assertSame('project', $template->renderedVars['viewToggleParams']['source']);
    }

    // ------------------------------------------------------------------
    // Binary-backed formats: raw answers with the notice + Render button
    // ------------------------------------------------------------------

    /**
     * An `.xlsx` has no raw source view, so supportsRawView is false and requesting raw stays on the grid.
     */
    public function testExcelDoesNotOfferRawViewMode(): void
    {
        $binaryWorkbook = "PK\x03\x04\x14\x00\x00\x00\x08\x00" . str_repeat("\x00\xff", 64);

        $result = $this->preview('budget.xlsx', $binaryWorkbook, ['view' => 'raw']);

        $this->assertSame('FileInteractionCore:file/excel_preview', $result['template']);
        $this->assertFalse($result['vars']['rawViewAvailable']);
        $this->assertStringNotContainsString('fic-btn-view-mode', $result['html']);
    }

    /**
     * The unknown-extension binary path shows no Render button: there is no rich
     * rendering to go back to.
     */
    public function testUnclassifiedBinaryNoticeOffersNoRenderButton(): void
    {
        $result = $this->preview('bundle.zip', "PK\x03\x04\x00\x00binary\x00payload");

        $this->assertSame('FileInteractionCore:file/binary_notice', $result['template']);
        $this->assertFalse($result['vars']['renderAvailable']);
        $this->assertStringNotContainsString('fic-btn-render', $result['html']);
    }

    /**
     * A text-backed workbook substitute still renders raw normally, proving the
     * binary branch is keyed on content rather than on the extension.
     */
    public function testRawViewOfTextBackedRichFormatRendersSource(): void
    {
        $result = $this->preview('data.csv', "a;b\n1;2\n", ['view' => 'raw']);

        $this->assertSame('CodePreviewHandler', $result['vars']['handler']);
        $this->assertStringContainsString('a;b', (string) $result['vars']['content']);
    }
}
