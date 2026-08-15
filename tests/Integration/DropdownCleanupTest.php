<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeModalHelper;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUrlHelper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';

/**
 * Task 35: the assembled attachment dropdown, core markup plus our hook.
 *
 * Assets/js/dropdown-cleanup.js runs against the DOM Kanboard actually produces,
 * so its selector assumptions are the thing most likely to break silently — a
 * core template tweak, or a change to how our marker is emitted, and the orphan
 * entry quietly returns. These tests assemble the real structure (core's
 * app/Template/task_file/files.php dropdown, then the hook output appended last,
 * exactly as HookHelper::render does) and assert every assumption the script
 * relies on.
 *
 * The href predicate below is a PHP transcription of the one in the script;
 * testCleanupPredicateMatchesTheShippedScript pins the two together so they
 * cannot drift apart unnoticed.
 */
class DropdownCleanupTest extends TestCase
{
    /**
     * Regex the cleanup script uses to recognise core's view actions.
     */
    private const CORE_VIEW_ACTION_PATTERN = '/[?&]action=(show|browser)(&|$)/';

    /**
     * Reproduce the dropdown <ul> from app/Template/task_file/files.php,
     * including the plugin hook output rendered last.
     */
    private function buildDropdown(string $filename, bool $canRemove = true): string
    {
        $urlHelper = FakeUrlHelper::withPluginRoutes();
        $modalHelper = new FakeModalHelper($urlHelper);
        $renderer = new FakeTemplateRenderer($urlHelper);

        $task = ['id' => 3, 'project_id' => 7];
        $file = ['id' => 42, 'name' => $filename, 'task_id' => 3];
        $coreParams = ['task_id' => $task['id'], 'file_id' => $file['id']];

        $html = '<ul>';

        // Core: view action — a modal for md/txt, a raw inline link otherwise.
        if ($renderer->file->getPreviewType($filename) !== null) {
            $html .= '<li>' . $modalHelper->large('eye', 'View file', 'FileViewerController', 'show', $coreParams) . '</li>';
        } elseif ($renderer->file->getBrowserViewType($filename) !== null) {
            $html .= '<li><i class="fa fa-eye fa-fw"></i>'
                . $urlHelper->link('View file', 'FileViewerController', 'browser', $coreParams, false, '', '', true)
                . '</li>';
        }

        // Core: download, always present.
        $html .= '<li>' . $urlHelper->icon('download', 'Download', 'FileViewerController', 'download', $coreParams) . '</li>';

        // Core: remove, permission gated.
        if ($canRemove) {
            $html .= '<li>' . $modalHelper->confirm('trash-o', 'Remove', 'TaskFileController', 'confirm', $coreParams) . '</li>';
        }

        // Plugin hook output is appended LAST — this is why the orphan entry
        // cannot be suppressed server-side.
        $html .= $renderer->renderPluginTemplate('file/dropdown', ['task' => $task, 'file' => $file]);

        return $html . '</ul>';
    }

    /**
     * Apply the cleanup rules the script implements, over the real DOM.
     *
     * @return list<string> Text labels of the entries that survive.
     */
    private function applyCleanup(string $dropdownHtml): array
    {
        $doc = new \DOMDocument();
        $doc->loadHTML('<meta charset="utf-8">' . $dropdownHtml, LIBXML_NOERROR);

        $xpath = new \DOMXPath($doc);
        $markers = $xpath->query('//li[contains(@class, "fic-safe-preview")][@data-fic-ext]');
        self::assertInstanceOf(\DOMNodeList::class, $markers);

        foreach ($markers as $marker) {
            $list = $marker->parentNode;
            if (!$list instanceof \DOMElement) {
                continue;
            }

            /** @var list<\DOMElement> $doomed */
            $doomed = [];

            foreach ($list->childNodes as $entry) {
                if (!$entry instanceof \DOMElement || $entry->tagName !== 'li' || $entry === $marker) {
                    continue;
                }

                $anchors = $entry->getElementsByTagName('a');
                if ($anchors->length === 0) {
                    continue;
                }

                $href = (string) $anchors->item(0)?->getAttribute('href');

                if ($this->isCoreViewAction($href)) {
                    $doomed[] = $entry;
                }
            }

            foreach ($doomed as $entry) {
                $list->removeChild($entry);
            }
        }

        $labels = [];
        $items = $doc->getElementsByTagName('li');

        foreach ($items as $item) {
            $labels[] = trim(preg_replace('/\s+/', ' ', $item->textContent) ?? '');
        }

        return $labels;
    }

    private function isCoreViewAction(string $href): bool
    {
        $url = str_replace('&amp;', '&', $href);

        if (!str_contains($url, 'controller=FileViewerController')) {
            return false;
        }

        return preg_match(self::CORE_VIEW_ACTION_PATTERN, $url) === 1;
    }

    /**
     * A PDF gets core's raw `browser` link; Safe Preview replaces it, so the
     * orphan must be gone while Download and Remove survive.
     */
    public function testCoreBrowserViewEntryIsRemovedForPdf(): void
    {
        $before = $this->buildDropdown('spec.pdf');
        $this->assertStringContainsString('View file', $before, 'Core must render its view action ahead of the hook.');

        $labels = $this->applyCleanup($before);

        $this->assertNotContains('View file', $labels);
        $this->assertContains('Safe Preview', $labels);
        $this->assertContains('Download', $labels);
        $this->assertContains('Remove', $labels);
    }

    /**
     * A .txt gets core's modal `show` variant instead — also an orphan.
     */
    public function testCoreModalViewEntryIsRemovedForText(): void
    {
        $labels = $this->applyCleanup($this->buildDropdown('notes.txt'));

        $this->assertNotContains('View file', $labels);
        $this->assertContains('Safe Preview', $labels);
        $this->assertContains('Edit Attachment', $labels);
        $this->assertContains('Download', $labels);
    }

    public function testCoreModalViewEntryIsRemovedForMarkdown(): void
    {
        $labels = $this->applyCleanup($this->buildDropdown('README.md'));

        $this->assertNotContains('View file', $labels);
        $this->assertContains('Safe Preview', $labels);
    }

    /**
     * THE scoping regression: Safe Preview does not handle audio, video or svg,
     * so core's view action is the only way to open them and must survive.
     *
     * @dataProvider coreOnlyFormatProvider
     */
    public function testCoreViewEntrySurvivesForFormatsWeDoNotHandle(string $filename): void
    {
        $labels = $this->applyCleanup($this->buildDropdown($filename));

        $this->assertContains('View file', $labels, $filename . ' has no Safe Preview replacement.');
        $this->assertNotContains('Safe Preview', $labels);
        $this->assertContains('Download', $labels);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coreOnlyFormatProvider(): array
    {
        return [
            'mp4' => ['clip.mp4'],
            'mp3' => ['audio.mp3'],
            'svg' => ['logo.svg'],
            'webm' => ['movie.webm'],
            'mov' => ['recording.mov'],
        ];
    }

    /**
     * Formats with no core view action at all must come out untouched.
     *
     * TASK 36 UPDATE: `.docx` now carries a Safe Preview entry (content
     * inspection), but deliberately no marker — core renders no view action for
     * it, so there is nothing for the cleanup script to remove and Download plus
     * Remove must both survive.
     */
    public function testDropdownUnchangedForFormatsWithNoCoreViewAction(): void
    {
        $dropdown = $this->buildDropdown('firmware.bin');

        $this->assertStringNotContainsString('fic-safe-preview', $dropdown, 'No marker means the script stays disarmed.');

        $labels = $this->applyCleanup($dropdown);

        $this->assertNotContains('View file', $labels);
        $this->assertContains('Safe Preview', $labels);
        $this->assertContains('Download', $labels);
        $this->assertContains('Remove', $labels);
    }

    /**
     * Cleanup must never touch the Download entry, whose href sits on the very
     * same controller as the actions being removed.
     */
    public function testDownloadEntryIsNeverRemoved(): void
    {
        foreach (['spec.pdf', 'notes.txt', 'README.md', 'data.csv', 'budget.xlsx'] as $filename) {
            $labels = $this->applyCleanup($this->buildDropdown($filename));

            $this->assertContains('Download', $labels, 'Download must survive for ' . $filename);
        }
    }

    public function testSafePreviewAndEditEntriesAreNeverRemoved(): void
    {
        $labels = $this->applyCleanup($this->buildDropdown('config.json'));

        $this->assertContains('Safe Preview', $labels);
        $this->assertContains('Edit Attachment', $labels);
    }

    /**
     * Only one dropdown's entries may be affected when several attachments are
     * listed side by side — the cleanup is scoped to the marker's own <ul>.
     */
    public function testCleanupIsScopedToTheMarkersOwnList(): void
    {
        $combined = $this->buildDropdown('spec.pdf') . $this->buildDropdown('clip.mp4');

        $labels = $this->applyCleanup($combined);

        // The mp4 dropdown has no marker, so its View file entry stays.
        $this->assertContains('View file', $labels);
        $this->assertContains('Safe Preview', $labels);
        // Exactly one View file survived: the mp4's.
        $this->assertCount(1, array_filter($labels, static fn (string $label): bool => $label === 'View file'));
    }

    /**
     * Bind the PHP transcription above to the shipped script, so a change to one
     * without the other fails here rather than in production.
     */
    public function testCleanupPredicateMatchesTheShippedScript(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/dropdown-cleanup.js');

        $jsPattern = trim(self::CORE_VIEW_ACTION_PATTERN, '/');

        $this->assertStringContainsString(
            $jsPattern,
            $script,
            'The shipped script no longer uses the action pattern these tests verify.'
        );
        $this->assertStringContainsString('controller=FileViewerController', $script);
    }

    /**
     * Confirm the premise of the whole approach: core's entry really is rendered
     * before the hook output, so server-side suppression is impossible.
     */
    public function testCoreViewEntryPrecedesThePluginHookOutput(): void
    {
        $html = $this->buildDropdown('spec.pdf');

        $corePosition = strpos($html, 'View file');
        $markerPosition = strpos($html, 'fic-safe-preview');

        $this->assertIsInt($corePosition);
        $this->assertIsInt($markerPosition);
        $this->assertLessThan(
            $markerPosition,
            $corePosition,
            'Core renders its view action first — hence the client-side cleanup.'
        );
    }
}
