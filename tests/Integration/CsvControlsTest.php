<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Service\CsvDelimiterRegistry;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeContainer.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';

/**
 * Task 37: CSV delimiter picker and header toggle, end to end.
 *
 * These run the real controller to produce the view variables, then render the
 * real template with them — so the controls, the URLs they carry, and the table
 * they produce are all exercised together rather than in isolation.
 */
class CsvControlsTest extends TestCase
{
    private const SEMICOLON_CSV = "id;name;role\n1;Alice;Admin\n2;Bob;User";
    private const COMMA_CSV = "id,name,role\n1,Alice,Admin\n2,Bob,User";
    private const PIPE_CSV = "id|name|role\n1|Alice|Admin";
    private const TAB_CSV = "id\tname\trole\n1\tAlice\tAdmin";

    /**
     * Run the controller, then render the CSV template with its output.
     *
     * @param array<string, mixed> $params
     * @return array{html: string, vars: array<string, mixed>}
     */
    private function preview(string $content, array $params = [], string $filename = 'data.csv'): array
    {
        $container = new FakeContainer([
            'request' => new FakeRequest($params + ['file_id' => 42, 'task_id' => 3, 'project_id' => 7]),
            'response' => new FakeResponse(),
            'template' => new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate(),
            'taskFileModel' => new FakeFileModel(['name' => $filename, 'path' => 'tasks/3/f', 'task_id' => 3]),
            'objectStorage' => new FakeObjectStorage($content),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        /** @var \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate $template */
        $template = $container['template'];
        $vars = $template->renderedVars;

        $renderer = new FakeTemplateRenderer();
        $html = $renderer->renderPluginTemplate('file/csv_preview', $vars);

        return ['html' => $html, 'vars' => $vars];
    }

    /**
     * @return list<string>
     */
    private function optionValues(string $html): array
    {
        preg_match_all('/<option value="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    /**
     * Cell texts of the rendered <thead>, excluding the `#` gutter.
     *
     * @return list<string>
     */
    private function headerCells(string $html): array
    {
        if (preg_match('/<thead.*?<tr>(.*?)<\/tr>/s', $html, $rowMatch) !== 1) {
            return [];
        }

        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $rowMatch[1], $cellMatches);

        $cells = array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cellMatches[1]);

        // Drop the leading row-number gutter column.
        return array_values(array_slice($cells, 1));
    }

    /**
     * Cell texts of each <tbody> row, excluding the row-number gutter.
     *
     * @return list<list<string>>
     */
    private function bodyRows(string $html): array
    {
        if (preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $bodyMatch) !== 1) {
            return [];
        }

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $bodyMatch[1], $rowMatches);

        $rows = [];
        foreach ($rowMatches[1] as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cellMatches);
            $cells = array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cellMatches[1]);
            $rows[] = array_values(array_slice($cells, 1));
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Controls are rendered
    // ------------------------------------------------------------------

    public function testControlsRenderWithEveryRequiredDelimiterOption(): void
    {
        $html = $this->preview(self::COMMA_CSV)['html'];

        $this->assertStringContainsString('data-fic-csv-control="delimiter"', $html);

        foreach (['Auto-detect', 'Comma', 'Semicolon', 'Tab', 'Pipe'] as $label) {
            $this->assertStringContainsString($label, $html, $label . ' must be offered.');
        }
    }

    public function testHeaderToggleRendersCheckedByDefault(): void
    {
        $html = $this->preview(self::COMMA_CSV)['html'];

        $this->assertSame(
            1,
            preg_match('/<input[^>]*data-fic-csv-control="header"[^>]*checked="checked"/s', $html),
            'The header toggle must default to checked.'
        );
    }

    public function testControlsAreAbsentFromNonCsvPreviews(): void
    {
        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 42, 'task_id' => 3, 'project_id' => 7]),
            'response' => new FakeResponse(),
            'template' => new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate(),
            'taskFileModel' => new FakeFileModel(['name' => 'notes.txt', 'path' => 'tasks/3/f', 'task_id' => 3]),
            'objectStorage' => new FakeObjectStorage('plain text'),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        /** @var \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate $template */
        $template = $container['template'];

        $this->assertFalse($template->renderedVars['csvControlsEnabled']);
    }

    // ------------------------------------------------------------------
    // Control URLs
    // ------------------------------------------------------------------

    /**
     * Each option carries a complete preview URL, which is what lets the script
     * hand it straight to KB.modal.replace() without building anything.
     */
    public function testEachDelimiterOptionCarriesItsOwnPreviewUrl(): void
    {
        $html = $this->preview(self::COMMA_CSV)['html'];
        $values = $this->optionValues($html);

        $this->assertNotSame([], $values);

        foreach ($values as $url) {
            $this->assertStringContainsString('controller=FilePreviewController', $url);
            $this->assertStringContainsString('action=show', $url);
            $this->assertStringContainsString('plugin=FileInteractionCore', $url);
            $this->assertStringContainsString('delimiter=', $url);
            // The header state must survive a delimiter change.
            $this->assertStringContainsString('header=', $url);
            $this->assertStringContainsString('file_id=42', $url);
        }
    }

    public function testOptionUrlsCoverEveryOfferedDelimiter(): void
    {
        $values = $this->optionValues($this->preview(self::COMMA_CSV)['html']);
        $registry = new CsvDelimiterRegistry();

        foreach (array_keys($registry->getOptions()) as $token) {
            $matching = array_filter(
                $values,
                static fn (string $url): bool => str_contains($url, 'delimiter=' . $token)
            );

            $this->assertNotSame([], $matching, 'No option URL for ' . $token);
        }
    }

    /**
     * Delimiters travel as tokens: a raw tab or pipe in a URL survives neither
     * encoding nor attribute escaping reliably.
     */
    public function testDelimiterUrlsCarryTokensNotRawCharacters(): void
    {
        foreach ($this->optionValues($this->preview(self::TAB_CSV)['html']) as $url) {
            $this->assertStringNotContainsString("\t", $url);
            $this->assertStringNotContainsString('delimiter=%09', $url);
            $this->assertStringNotContainsString('delimiter=|', $url);
        }
    }

    /**
     * The checkbox carries the URL for its TOGGLED state, so the server always
     * renders the correct next target and the script keeps no state.
     */
    public function testHeaderToggleUrlFlipsTheHeaderParameter(): void
    {
        $onHtml = $this->preview(self::COMMA_CSV)['html'];
        $this->assertSame(1, preg_match('/data-fic-csv-control="header"\s+data-fic-url="([^"]*)"/s', $onHtml, $onMatch));
        $this->assertStringContainsString('header=0', $onMatch[1], 'While on, the toggle must link to off.');

        $offHtml = $this->preview(self::COMMA_CSV, ['header' => '0'])['html'];
        $this->assertSame(1, preg_match('/data-fic-csv-control="header"\s+data-fic-url="([^"]*)"/s', $offHtml, $offMatch));
        $this->assertStringContainsString('header=1', $offMatch[1], 'While off, the toggle must link back to on.');
    }

    /**
     * A delimiter choice must survive toggling the header, and vice versa.
     */
    public function testControlsPreserveEachOthersState(): void
    {
        $html = $this->preview(self::SEMICOLON_CSV, ['delimiter' => 'semicolon', 'header' => '0'])['html'];

        preg_match('/data-fic-csv-control="header"\s+data-fic-url="([^"]*)"/s', $html, $headerMatch);
        $this->assertStringContainsString('delimiter=semicolon', $headerMatch[1]);

        foreach ($this->optionValues($html) as $url) {
            $this->assertStringContainsString('header=0', $url);
        }
    }

    public function testProjectSourceSurvivesInControlUrls(): void
    {
        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 55, 'task_id' => 0, 'project_id' => 9, 'source' => 'project']),
            'response' => new FakeResponse(),
            'template' => new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate(),
            'projectFileModel' => new FakeFileModel(['name' => 'data.csv', 'path' => 'projects/9/f']),
            'objectStorage' => new FakeObjectStorage(self::COMMA_CSV),
        ]);

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        /** @var \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate $template */
        $template = $container['template'];
        $renderer = new FakeTemplateRenderer();
        $html = $renderer->renderPluginTemplate('file/csv_preview', $template->renderedVars);

        foreach ($this->optionValues($html) as $url) {
            $this->assertStringContainsString('source=project', $url);
        }
    }

    // ------------------------------------------------------------------
    // Dynamic delimiter rendering — the table actually changes
    // ------------------------------------------------------------------

    /**
     * THE core behaviour: choosing a delimiter re-parses the file into different
     * columns, not merely a different badge.
     */
    public function testChoosingSemicolonSplitsWhereCommaWouldNot(): void
    {
        // Auto-detection settles on the semicolon for this payload.
        $auto = $this->preview(self::SEMICOLON_CSV);
        $this->assertSame(['id', 'name', 'role'], $this->headerCells($auto['html']));

        // Forcing the comma leaves each line as a single unsplit column.
        $asComma = $this->preview(self::SEMICOLON_CSV, ['delimiter' => 'comma']);
        $this->assertSame(['id;name;role'], $this->headerCells($asComma['html']));
        $this->assertSame([['1;Alice;Admin'], ['2;Bob;User']], $this->bodyRows($asComma['html']));
    }

    /**
     * @dataProvider delimiterRenderingProvider
     */
    public function testExplicitDelimiterParsesTheMatchingPayload(string $token, string $content): void
    {
        $result = $this->preview($content, ['delimiter' => $token]);

        $this->assertSame(['id', 'name', 'role'], $this->headerCells($result['html']));
        $this->assertSame($token, $result['vars']['metadata']['delimiterToken']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function delimiterRenderingProvider(): array
    {
        return [
            'comma' => ['comma', self::COMMA_CSV],
            'semicolon' => ['semicolon', self::SEMICOLON_CSV],
            'tab' => ['tab', self::TAB_CSV],
            'pipe' => ['pipe', self::PIPE_CSV],
        ];
    }

    /**
     * Auto-detection stays selected in the picker even though it resolves to a
     * concrete delimiter — otherwise the control would silently jump off
     * "Auto-detect" and the user could never return to it.
     */
    public function testAutoDetectRemainsTheSelectedOptionWhileActive(): void
    {
        $result = $this->preview(self::SEMICOLON_CSV);

        $this->assertSame('auto', $result['vars']['delimiterMode']);
        $this->assertSame('semicolon', $result['vars']['selectedDelimiter'], 'The effective delimiter is reported.');

        $this->assertSame(
            1,
            preg_match('/<option value="[^"]*delimiter=auto[^"]*"\s+selected="selected"/', $result['html']),
            'Auto-detect must stay selected while it is the active mode.'
        );
        $this->assertSame(1, substr_count($result['html'], 'selected="selected"'));
    }

    public function testExplicitChoiceBecomesTheSelectedOption(): void
    {
        $html = $this->preview(self::SEMICOLON_CSV, ['delimiter' => 'pipe'])['html'];

        $this->assertSame(
            1,
            preg_match('/<option value="[^"]*delimiter=pipe[^"]*"\s+selected="selected"/', $html),
            'An explicit choice must be reflected in the picker.'
        );
    }

    /**
     * The auto-detected delimiter is surfaced so the user knows what happened.
     */
    public function testAutoDetectedDelimiterIsReportedInTheUi(): void
    {
        $html = $this->preview(self::SEMICOLON_CSV)['html'];

        $this->assertStringContainsString('Auto-detected', $html);
        $this->assertStringContainsString('SEMICOLON', $html);
    }

    /**
     * A raw tab must never be printed into the badge.
     */
    public function testTabDelimiterIsLabelledInTheBadge(): void
    {
        $html = $this->preview(self::TAB_CSV, ['delimiter' => 'tab'])['html'];

        $this->assertStringContainsString('Delimiter: "TAB"', $html);
    }

    // ------------------------------------------------------------------
    // Header toggle changes the table
    // ------------------------------------------------------------------

    /**
     * With the toggle on, the first row is promoted out of the body.
     */
    public function testHeaderOnPromotesFirstRowToTheHead(): void
    {
        $result = $this->preview(self::COMMA_CSV);

        $this->assertSame(['id', 'name', 'role'], $this->headerCells($result['html']));
        $this->assertSame([['1', 'Alice', 'Admin'], ['2', 'Bob', 'User']], $this->bodyRows($result['html']));
    }

    /**
     * With it off, no row is consumed — every line is data, and the head carries
     * column indices so the sticky gutter stays aligned.
     */
    public function testHeaderOffKeepsEveryRowAsData(): void
    {
        $result = $this->preview(self::COMMA_CSV, ['header' => '0']);

        $this->assertSame(['1', '2', '3'], $this->headerCells($result['html']), 'Column indices replace the header row.');
        $this->assertSame(
            [['id', 'name', 'role'], ['1', 'Alice', 'Admin'], ['2', 'Bob', 'User']],
            $this->bodyRows($result['html']),
            'The first row must remain in the body.'
        );
    }

    public function testHeaderToggleStateIsReflectedInTheCheckbox(): void
    {
        $offHtml = $this->preview(self::COMMA_CSV, ['header' => '0'])['html'];

        $this->assertSame(
            0,
            preg_match('/<input[^>]*data-fic-csv-control="header"[^>]*checked="checked"/s', $offHtml),
            'The toggle must render unchecked when the header is off.'
        );
    }

    /**
     * Only an explicit `0` turns the header off; anything else keeps the default.
     *
     * @dataProvider headerParameterProvider
     */
    public function testHeaderParameterIsTriState(string $param, bool $expected): void
    {
        $result = $this->preview(self::COMMA_CSV, $param === '' ? [] : ['header' => $param]);

        $this->assertSame($expected, $result['vars']['hasHeaderRow']);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function headerParameterProvider(): array
    {
        return [
            'absent defaults to on' => ['', true],
            'explicit 1 is on' => ['1', true],
            'explicit 0 is off' => ['0', false],
            'garbage is off, not a crash' => ['maybe', false],
        ];
    }

    // ------------------------------------------------------------------
    // Safety
    // ------------------------------------------------------------------

    /**
     * A hostile delimiter parameter must collapse to auto-detection and never
     * appear in the rendered output.
     *
     * @dataProvider hostileDelimiterProvider
     */
    public function testHostileDelimiterParameterIsRejected(string $hostile): void
    {
        $result = $this->preview(self::COMMA_CSV, ['delimiter' => $hostile]);

        $this->assertSame('auto', $result['vars']['delimiterMode']);

        // The rejected value must never be echoed back as a delimiter parameter.
        // (A plain substring check would false-positive on "colon" inside the
        // "Semicolon" label, so match the parameter itself.)
        $this->assertStringNotContainsString('delimiter=' . rawurlencode($hostile), $result['html']);
        $this->assertStringNotContainsString('delimiter=' . $hostile, $result['html']);

        // No markup or handler may leak out of the payload either.
        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringNotContainsString('onmouseover', $result['html']);

        // Auto-detection still produced a correctly split table.
        $this->assertSame(['id', 'name', 'role'], $this->headerCells($result['html']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileDelimiterProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'quote break out' => ['" onmouseover="alert(1)'],
            'traversal' => ['../../etc/passwd'],
            'unknown token' => ['colon'],
        ];
    }

    /**
     * Cell escaping must hold whatever delimiter is chosen — re-parsing must not
     * become a way to smuggle markup through.
     */
    public function testCellsStayEscapedUnderEveryDelimiterChoice(): void
    {
        $malicious = "a;b\n<script>alert(1)</script>;<img src=x onerror=alert(1)>";

        foreach (['auto', 'comma', 'semicolon', 'tab', 'pipe'] as $token) {
            $html = $this->preview($malicious, ['delimiter' => $token])['html'];

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'Escaped for ' . $token);
            $this->assertStringNotContainsString('<img src=x', $html, 'Escaped for ' . $token);
            $this->assertStringContainsString('&lt;script&gt;', $html);
        }
    }

    /**
     * An empty CSV must not render controls over a broken table.
     */
    public function testEmptyCsvStillRendersCleanly(): void
    {
        $result = $this->preview('', ['delimiter' => 'comma']);

        $this->assertStringContainsString('The CSV file is empty.', $result['html']);
        $this->assertStringContainsString('data-fic-csv-control="delimiter"', $result['html']);
    }

    /**
     * The control script must be a registered asset, not inline — CSP blocks
     * inline handlers.
     */
    public function testControlsShipAsARegisteredAssetNotInline(): void
    {
        $controlsTemplate = (string) file_get_contents(__DIR__ . '/../../Template/file/csv_controls.php');
        $plugin = (string) file_get_contents(__DIR__ . '/../../Plugin.php');

        $this->assertStringNotContainsString('<script', $controlsTemplate);
        $this->assertStringNotContainsString('onchange', $controlsTemplate);
        $this->assertStringContainsString('plugins/FileInteractionCore/Assets/js/preview-controls.js', $plugin);
        $this->assertFileExists(__DIR__ . '/../../Assets/js/preview-controls.js');
    }

    /**
     * Pin the operations the shipped script performs. Its DOM behaviour is not
     * executable in the php:8.1-cli container, so — as with Tasks 35 and 36 — the
     * essentials are pinned textually and confirmed by a manual browser pass.
     */
    public function testControlScriptHandlesBothControlKinds(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/preview-controls.js');

        $this->assertStringContainsString('data-fic-csv-control', $script);
        // Still drives the Task 36 language picker from the same handler.
        $this->assertStringContainsString('data-fic-language-select', $script);
        $this->assertStringContainsString("addEventListener('change'", $script);
        $this->assertStringContainsString('KB.modal.replace', $script);
        $this->assertStringContainsString('window.location.href', $script);
        // The checkbox target comes from data-fic-url, the select from its value.
        $this->assertStringContainsString("getAttribute('data-fic-url')", $script);
    }
}
