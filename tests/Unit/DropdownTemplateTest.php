<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUserHelper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';

/**
 * Task 35: orphan "View file" dropdown cleanup.
 *
 * Kanboard core renders its own un-sandboxed view action into the attachment
 * dropdown BEFORE the `template:task-file:documents:dropdown` hook fires, so the
 * plugin cannot suppress that <li> server-side without patching core. It is
 * pruned client-side by Assets/js/dropdown-cleanup.js, gated on the marker <li>
 * this template emits. These tests cover both halves of that contract:
 * the marker's presence/absence, and the script's targeting rules.
 */
class DropdownTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function renderTaskDropdown(string $filename, array $overrides = [], bool $canWrite = true): string
    {
        $renderer = new FakeTemplateRenderer(null, new FakeUserHelper($canWrite));

        return $renderer->renderPluginTemplate('file/dropdown', array_merge([
            'task' => ['id' => 3, 'project_id' => 7],
            'file' => ['id' => 42, 'name' => $filename, 'task_id' => 3],
        ], $overrides));
    }

    /**
     * @return list<string>
     */
    private function markerExtensions(string $html): array
    {
        preg_match_all('/<li class="fic-safe-preview" data-fic-ext="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    /**
     * Formats Safe Preview claims must carry the cleanup marker.
     *
     * @dataProvider handledExtensionProvider
     */
    public function testMarkerIsEmittedForHandledFormats(string $filename, string $expectedExtension): void
    {
        $html = $this->renderTaskDropdown($filename);

        $this->assertSame([$expectedExtension], $this->markerExtensions($html));
        $this->assertStringContainsString('Safe Preview', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function handledExtensionProvider(): array
    {
        return [
            'pdf' => ['spec.pdf', 'pdf'],
            'txt' => ['notes.txt', 'txt'],
            'md' => ['README.md', 'md'],
            'csv' => ['data.csv', 'csv'],
            'json' => ['config.json', 'json'],
            'xlsx' => ['budget.xlsx', 'xlsx'],
            'uppercase extension is normalised' => ['REPORT.PDF', 'pdf'],
        ];
    }

    /**
     * THE key scoping guarantee: core also offers "View file" for audio, video
     * and svg attachments that Safe Preview does NOT handle. Emitting neither a
     * marker nor an entry for those is what keeps the cleanup script from
     * stripping a legitimate action the user has no replacement for.
     *
     * @dataProvider coreMediaExtensionProvider
     */
    public function testNoEntryAtAllForCoreOwnedMediaFormats(string $filename): void
    {
        $html = $this->renderTaskDropdown($filename);

        $this->assertSame([], $this->markerExtensions($html));
        $this->assertStringNotContainsString('Safe Preview', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coreMediaExtensionProvider(): array
    {
        return [
            'mp4 is core-only' => ['clip.mp4'],
            'mp3 is core-only' => ['audio.mp3'],
            'svg is core-only' => ['logo.svg'],
            'webm is core-only' => ['movie.webm'],
            'png is core-only' => ['diagram.png'],
        ];
    }

    /**
     * Task 36: unknown and missing extensions DO get a Safe Preview entry — the
     * controller inspects their content and answers with an escaped text view or
     * a binary download notice.
     *
     * They must still carry NO marker: core renders no view action for them, so
     * there is no orphan to clean and no reason to arm the cleanup script.
     *
     * @dataProvider unclassifiedExtensionProvider
     */
    public function testEntryWithoutMarkerForUnclassifiedExtensions(string $filename): void
    {
        $html = $this->renderTaskDropdown($filename);

        $this->assertStringContainsString('Safe Preview', $html, $filename . ' must be inspectable.');
        $this->assertSame(
            [],
            $this->markerExtensions($html),
            $filename . ' must not arm the dropdown cleanup script.'
        );
        $this->assertStringNotContainsString('fic-safe-preview', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unclassifiedExtensionProvider(): array
    {
        return [
            'bin has no handler' => ['firmware.bin'],
            'zip has no handler' => ['bundle.zip'],
            'no extension at all' => ['LICENSE'],
            'unrecognised extension' => ['dump.bak'],
            'dotfile with no extension' => ['Makefile'],
        ];
    }

    public function testSafePreviewLinkTargetsPluginPreviewRoute(): void
    {
        $html = $this->renderTaskDropdown('spec.pdf');

        $this->assertStringContainsString('href="/b/7/task/3/file/42/preview"', $html);
    }

    public function testEditEntryStillRenderedForEditableFormats(): void
    {
        $html = $this->renderTaskDropdown('notes.txt');

        $this->assertStringContainsString('Edit Attachment', $html);
        $this->assertStringContainsString('href="/b/7/task/3/file/42/edit"', $html);
    }

    /**
     * Binary formats like PDF keep the marker (they are previewable)
     * but must never offer the plain-text editor.
     */
    public function testEditEntryWithheldForBinaryAndActiveContent(): void
    {
        foreach (['spec.pdf'] as $filename) {
            $html = $this->renderTaskDropdown($filename);

            $this->assertNotSame([], $this->markerExtensions($html), $filename . ' should still be previewable.');
            $this->assertStringNotContainsString('Edit Attachment', $html, $filename . ' must not be editable.');
        }
    }

    public function testEditEntryWithheldWithoutWritePermission(): void
    {
        $html = $this->renderTaskDropdown('notes.txt', [], false);

        $this->assertStringNotContainsString('Edit Attachment', $html);
        $this->assertStringContainsString('Safe Preview', $html);
    }

    /**
     * Project-overview attachments arrive with `project` instead of `task`, and
     * have no editable target because FileEditController resolves through
     * taskFileModel only.
     */
    public function testProjectOverviewAttachmentIsPreviewableButNotEditable(): void
    {
        $renderer = new FakeTemplateRenderer();

        $html = $renderer->renderPluginTemplate('file/dropdown', [
            'project' => ['id' => 9],
            'file' => ['id' => 55, 'name' => 'notes.txt'],
        ]);

        $this->assertSame(['txt'], $this->markerExtensions($html));
        $this->assertStringNotContainsString('Edit Attachment', $html);
        $this->assertStringContainsString('source=project', $html);
    }

    /**
     * A crafted filename must not break out of the data-fic-ext attribute.
     */
    public function testMarkerAttributeIsEscaped(): void
    {
        $html = $this->renderTaskDropdown('exploit."><script>alert(1)</script>.pdf');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame(['pdf'], $this->markerExtensions($html));
    }

    /**
     * The cleanup script must target only core's two view actions — never the
     * Download entry, and never a plugin controller.
     */
    public function testCleanupScriptTargetsOnlyCoreViewActions(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/dropdown-cleanup.js');

        $this->assertStringContainsString('controller=FileViewerController', $script);
        $this->assertStringContainsString('action=(show|browser)', $script);
        $this->assertStringNotContainsString('action=download', $script);
        $this->assertStringNotContainsString('FilePreviewController', $script);
    }

    /**
     * Cleanup must be gated on the marker, so it can only ever touch dropdowns
     * where Safe Preview supplied a replacement.
     */
    public function testCleanupScriptIsGatedOnTheTemplateMarker(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/dropdown-cleanup.js');

        $this->assertStringContainsString('li.fic-safe-preview[data-fic-ext]', $script);
        // Scoped to the marker's own list, not the whole document.
        $this->assertStringContainsString("closest('ul')", $script);
    }

    /**
     * Pin the operations the shipped script must actually perform.
     *
     * NOTE ON COVERAGE: the DOM behaviour itself is verified by the PHP
     * transcription in tests/Integration/DropdownCleanupTest.php, which asserts
     * the removal rules against the real assembled markup. That transcription
     * cannot catch the script losing its own removal call, so the essential
     * operations are pinned textually here. Executing the real script would need
     * a JS DOM runtime, which the php:8.1-cli test container does not provide —
     * end-to-end confirmation is a manual browser pass (see walkthrough.md).
     */
    public function testCleanupScriptStillPerformsTheRemoval(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../Assets/js/dropdown-cleanup.js');

        $this->assertMatchesRegularExpression(
            '/entry\.remove\(\)/',
            $script,
            'The script no longer removes the orphan entry.'
        );
        // Iterates the marker list's own children rather than a global query.
        $this->assertStringContainsString('list.children', $script);
        // Re-runs for dropdowns injected by Kanboard's ajax table re-render.
        $this->assertStringContainsString('MutationObserver', $script);
    }

    /**
     * Kanboard's CSP is `default-src 'self'` with no `script-src 'unsafe-inline'`,
     * so the cleanup must ship as a served asset. An inline <script> emitted from
     * the dropdown template would be silently blocked.
     */
    public function testCleanupIsNotShippedAsAnInlineScript(): void
    {
        $dropdown = (string) file_get_contents(__DIR__ . '/../../Template/file/dropdown.php');

        $this->assertStringNotContainsString('<script', $dropdown);
    }

    /**
     * The asset must be registered on the layout hook, and the path must resolve
     * relative to the Kanboard root the way AssetHelper expects.
     */
    public function testCleanupScriptIsRegisteredOnTheLayoutJsHook(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../Plugin.php');

        $this->assertStringContainsString('template:layout:js', $plugin);
        $this->assertStringContainsString('plugins/FileInteractionCore/Assets/js/dropdown-cleanup.js', $plugin);
        $this->assertFileExists(__DIR__ . '/../../Assets/js/dropdown-cleanup.js');
    }
}
