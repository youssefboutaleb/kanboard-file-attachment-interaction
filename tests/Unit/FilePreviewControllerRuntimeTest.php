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
 * Regression coverage for the Kanboard HTTP runtime path.
 *
 * These tests reproduce the "Safe Preview modal is empty" defect: because
 * Kanboard\Core\Base implements __get() without __isset(), any context detection
 * based on `isset($this->request)` silently evaluates to false, so request
 * parameters were never read and no HTML was ever emitted.
 */
class FilePreviewControllerRuntimeTest extends TestCase
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

    public function testRendersHtmlModalFromHttpRequestParameters(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'report.json', 'path' => 'tasks/1/abc123', 'task_id' => 1],
            '{"status":"ok"}',
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview', $template->renderedTemplate);
        $this->assertSame(200, $response->statusCode);
        $this->assertNotNull($response->body);
        $this->assertNotSame('', $response->body, 'Preview modal body must never be empty.');
    }

    public function testResolvesRequestParametersDespiteMissingIssetMagic(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = $this->buildContainer(
            ['file_id' => 42, 'task_id' => 7, 'project_id' => 3],
            ['name' => 'deploy.yml', 'path' => 'tasks/7/def456', 'task_id' => 7],
            "key: value\nother: 1\n",
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $vars = $template->renderedVars;

        $this->assertSame(42, $vars['fileId'], 'file_id must be read from the HTTP request.');
        $this->assertSame(7, $vars['taskId']);
        $this->assertSame(3, $vars['projectId']);
        $this->assertSame('deploy.yml', $vars['filename']);
        $this->assertSame('TextPreviewHandler', $vars['handler']);
    }

    public function testLoadsAttachmentContentFromObjectStorage(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = $this->buildContainer(
            ['file_id' => 9, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'notes.txt', 'path' => 'tasks/1/ghi789', 'task_id' => 1],
            'hello from object storage',
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertStringContainsString('hello from object storage', (string) $template->renderedVars['content']);
    }

    public function testEscapesHtmlAttachmentContentInRuntimeContext(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = $this->buildContainer(
            ['file_id' => 2, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'page.html', 'path' => 'tasks/1/xss', 'task_id' => 1],
            '<script>alert("xss")</script>',
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $content = (string) $template->renderedVars['content'];

        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    public function testRendersErrorModalInsteadOfThrowingForDisallowedExtension(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = $this->buildContainer(
            ['file_id' => 3, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'payload.docx', 'path' => 'tasks/1/doc', 'task_id' => 1],
            'binary',
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame(400, $response->statusCode);
        $this->assertSame('invalid_file', $template->renderedVars['reason']);
    }

    public function testRendersAccessDeniedModalWithForbiddenStatus(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $checker = new MockPermissionChecker(true);
        $checker->setFileAccess(1, 1, 5, false);

        $container = $this->buildContainer(
            ['file_id' => 5, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'secret.json', 'path' => 'tasks/1/secret', 'task_id' => 1],
            '{"a":1}',
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService($checker));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame(403, $response->statusCode);
        $this->assertSame('access_denied', $template->renderedVars['reason']);
    }

    public function testStandaloneModeStillReturnsArrayWithoutContainer(): void
    {
        $controller = new FilePreviewController(null, new PermissionService(new MockPermissionChecker(true)));

        $result = $controller->show(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testRendersCsvPreviewTemplateForCsvFile(): void
    {
        $template = new FakeTemplate();
        $response = new FakeResponse();

        $container = $this->buildContainer(
            ['file_id' => 12, 'task_id' => 1, 'project_id' => 1],
            ['name' => 'data.csv', 'path' => 'tasks/1/data.csv', 'task_id' => 1, 'project_id' => 1],
            "id,name,role\n1,Alice,Admin\n2,Bob,User",
            $template,
            $response
        );

        $controller = new FilePreviewController($container, new PermissionService(new MockPermissionChecker(true)));
        $controller->show();

        $this->assertSame('FileInteractionCore:file/csv_preview', $template->renderedTemplate);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('CsvPreviewHandler', $template->renderedVars['handler']);
        $this->assertSame(',', $template->renderedVars['metadata']['delimiter']);
        $this->assertCount(3, $template->renderedVars['metadata']['rows']);
    }
}
