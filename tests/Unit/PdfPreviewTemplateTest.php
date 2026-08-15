<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplateRenderer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUrlHelper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/TemplateFunctions.php';
require_once __DIR__ . '/../stubs/FakeTemplateRenderer.php';

/**
 * Task 35: PDF stream URL generation in the viewer modal.
 *
 * Two defects are locked down here:
 *   1. <object data> pointed at core's FileViewerController, whose responses all
 *      carry `X-Frame-Options: DENY` — so the PDF never rendered inline and the
 *      "not supported" fallback banner showed instead.
 *   2. The fullscreen control was bound to the `download` action, so asking for
 *      fullscreen raised a save dialog rather than displaying the document.
 */
class PdfPreviewTemplateTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $renderer = new FakeTemplateRenderer();

        return $renderer->renderPluginTemplate('file/pdf_preview', array_merge([
            'projectId' => 7,
            'taskId' => 3,
            'fileId' => 42,
            'filename' => 'spec.pdf',
            'extension' => 'pdf',
            'handler' => 'PdfPreviewHandler',
            'content' => '',
            'isFormatted' => true,
            'metadata' => ['handler' => 'PdfPreviewHandler', 'isBinary' => true, 'sizeBytes' => 2048],
            // Supplied by FilePreviewController in production (v0.7.1).
            'typeLabel' => 'PDF Document',
            'rawViewAvailable' => false,
            'viewMode' => 'rendered',
            'viewToggleParams' => [
                'plugin' => 'FileInteractionCore',
                'project_id' => 7,
                'task_id' => 3,
                'file_id' => 42,
            ],
        ], $overrides));
    }

    /**
     * Extract the value of the <object data="…"> attribute.
     */
    private function objectDataUrl(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<object\s+data="([^"]*)"/', $html, $matches),
            'The viewer must render exactly one <object data="…"> container.'
        );

        return $matches[1];
    }

    public function testObjectStreamsThroughPluginStreamRoute(): void
    {
        $dataUrl = $this->objectDataUrl($this->render());

        $this->assertSame('/b/7/task/3/file/42/stream', $dataUrl);
    }

    /**
     * The regression guard: routing the <object> back through core's viewer
     * reintroduces the X-Frame-Options: DENY fallback banner.
     */
    public function testObjectDoesNotUseCoreFileViewerController(): void
    {
        $dataUrl = $this->objectDataUrl($this->render());

        $this->assertStringNotContainsString('FileViewerController', $dataUrl);
        $this->assertStringNotContainsString('action=browser', $dataUrl);
    }

    /**
     * Pointing the viewer at `download` is what made the modal serve a save
     * dialog instead of rendering the document.
     */
    public function testObjectDoesNotUseDownloadAction(): void
    {
        $dataUrl = $this->objectDataUrl($this->render());

        $this->assertStringNotContainsString('action=download', $dataUrl);
    }

    public function testObjectDeclaresPdfMimeType(): void
    {
        $this->assertStringContainsString('type="application/pdf"', $this->render());
    }

    /**
     * v0.7.1: Fullscreen became the shared in-modal toggle in the unified action
     * bar, and the "open the stream in a new tab" action — which is what the old
     * Fullscreen link did — is now its own control.
     */
    public function testOpenInNewTabLinkTargetsTheInlineStream(): void
    {
        $html = $this->render();

        $this->assertSame(
            1,
            preg_match('/<a href="([^"]*)" target="_blank"[^>]*>\s*<i class="fa fa-external-link"><\/i>\s*Open in new tab/s', $html, $matches),
            'The PDF modal must offer a new-tab link to the inline stream.'
        );

        $this->assertSame('/b/7/task/3/file/42/stream', $matches[1]);
        $this->assertStringNotContainsString('action=download', $matches[1]);
    }

    public function testFullscreenControlIsTheSharedInModalToggle(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('class="fic-btn-fullscreen"', $html);
        $this->assertStringContainsString('data-fic-fullscreen-toggle', $html);
        $this->assertStringContainsString('fa-arrows-alt', $html);
    }

    /**
     * v0.7.1 requirement 2: internal handler class names must not reach the UI.
     */
    public function testHandlerNameIsNotRendered(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('PdfPreviewHandler', $html);
        // Replaced by the friendly type name in the action bar.
        $this->assertStringContainsString('PDF Document Modal', $html);
    }

    /**
     * Fullscreen and Download were once a single combined link bound to the
     * download URL. They must remain two distinct actions.
     */
    public function testFullscreenAndDownloadAreSeparateActions(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('Open Fullscreen / Download', $html);
        $this->assertStringContainsString('Fullscreen', $html);
        $this->assertStringContainsString('Download', $html);
    }

    public function testDownloadLinkStillTargetsCoreDownloadAction(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('controller=FileViewerController', $html);
        $this->assertStringContainsString('action=download', $html);
    }

    /**
     * The fallback banner stays in the markup as <object> child content for
     * browsers with no PDF viewer — it just must no longer be what users see.
     */
    public function testFallbackDownloadLinkRemainsInsideObject(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Inline PDF viewing is not supported', $html);

        $objectBody = substr($html, (int) strpos($html, '<object'), (int) strpos($html, '</object>') - (int) strpos($html, '<object'));
        $this->assertStringContainsString('Download PDF Document', $objectBody);
    }

    /**
     * Route::findUrl() only matches when the supplied params are exactly the
     * route's params, so a project-less attachment must still produce the pretty
     * stream path rather than degrading to a query string.
     */
    public function testStreamUrlIsGeneratedWhenProjectIdIsZero(): void
    {
        $dataUrl = $this->objectDataUrl($this->render(['projectId' => 0]));

        $this->assertSame('/b/0/task/3/file/42/stream', $dataUrl);
    }

    /**
     * If the plugin route were ever unregistered, href() silently degrades to a
     * query-string URL. That must still carry the plugin param, or Kanboard's
     * Router cannot dispatch to a plugin controller.
     */
    public function testStreamUrlFallsBackToQueryStringCarryingPluginParam(): void
    {
        $renderer = new FakeTemplateRenderer(new FakeUrlHelper());

        $html = $renderer->renderPluginTemplate('file/pdf_preview', [
            'projectId' => 7,
            'taskId' => 3,
            'fileId' => 42,
            'filename' => 'spec.pdf',
            'extension' => 'pdf',
            'handler' => 'PdfPreviewHandler',
            'content' => '',
            'isFormatted' => true,
            'metadata' => ['sizeBytes' => 2048],
        ]);

        $dataUrl = $this->objectDataUrl($html);

        $this->assertStringContainsString('controller=FileStreamController', $dataUrl);
        $this->assertStringContainsString('action=inline', $dataUrl);
        $this->assertStringContainsString('plugin=FileInteractionCore', $dataUrl);
    }

    public function testFilenameIsEscapedInHeader(): void
    {
        $html = $this->render(['filename' => '<img src=x onerror=alert(1)>.pdf']);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }
}
