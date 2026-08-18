<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

use Kanboard\Plugin\FileInteractionCore\Controller\FileEditController;
use Kanboard\Plugin\FileInteractionCore\Controller\FilePreviewController;
use Kanboard\Plugin\FileInteractionCore\Controller\FileStreamController;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\KanboardPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeProjectPermissionModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTaskFinder;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUserSession;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\RecordingStreamEmitter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/FakeContainer.php';

/**
 * Object-level authorization for attachments.
 *
 * THE DEFECT THESE LOCK DOWN: Kanboard authorizes these routes through
 * `projectAccessMap`, which proves the caller holds a role on the `project_id`
 * carried in the URL — and nothing about `file_id`. The controllers, meanwhile,
 * load attachments with `getById($fileId)`, keyed on the id alone. Nothing joined
 * the two, so a member of a project they legitimately belong to could name that
 * project in the path and a foreign attachment id in the query, and read (or, on
 * the edit route, overwrite) a file from a project they cannot see.
 *
 * Every scenario below is written from the attacker's seat: the caller is a genuine
 * member of project 5, and the attachment lives in project 7.
 */
class AttachmentAuthorizationTest extends TestCase
{
    private const PDF_BYTES = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /**
     * Task 42 belongs to project 7; the caller will claim project 5.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $file
     */
    private function buildContainer(array $params, array $file, string $content): FakeContainer
    {
        return new FakeContainer([
            'request' => new FakeRequest($params),
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'taskFileModel' => new FakeFileModel($file),
            'objectStorage' => new FakeObjectStorage($content),
            'taskFinderModel' => new FakeTaskFinder([42 => 7, 8 => 5]),
            'userSession' => new FakeUserSession(3),
        ]);
    }

    public function testPreviewRefusesAttachmentOwnedByAnotherTask(): void
    {
        $container = $this->buildContainer(
            ['file_id' => 999, 'task_id' => 8, 'project_id' => 5],
            ['id' => 999, 'name' => 'salaries.txt', 'path' => 'tasks/42/salaries.txt', 'task_id' => 42],
            'CONFIDENTIAL'
        );

        $controller = new FilePreviewController(
            $container,
            new PermissionService(new MockPermissionChecker(true))
        );

        $controller->show();

        $response = $container->offsetGet('response');
        $template = $container->offsetGet('template');

        $this->assertSame(403, $response->statusCode);
        $this->assertSame('FileInteractionCore:file/preview_error', $template->renderedTemplate);
        $this->assertSame('access_denied', $template->renderedVars['reason']);
        // The whole point: the bytes must never reach the rendered output.
        $this->assertStringNotContainsString('CONFIDENTIAL', (string) $response->body);
    }

    public function testStreamRefusesAttachmentOwnedByAnotherTask(): void
    {
        $container = $this->buildContainer(
            ['file_id' => 999, 'task_id' => 8, 'project_id' => 5],
            ['id' => 999, 'name' => 'contract.pdf', 'path' => 'tasks/42/contract.pdf', 'task_id' => 42],
            self::PDF_BYTES
        );

        $emitter = new RecordingStreamEmitter();
        $controller = new FileStreamController(
            $container,
            new PermissionService(new MockPermissionChecker(true)),
            new FileValidationService(),
            $emitter
        );

        $result = $controller->inline();

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('access_denied', $result['reason']);
        $this->assertStringNotContainsString('%PDF', $emitter->body);
    }

    /**
     * The task id matches, but that task sits in a project the URL misrepresents —
     * so the role check ran against the wrong project and must not stand.
     */
    public function testPreviewRefusesTaskBelongingToAnotherProject(): void
    {
        $container = $this->buildContainer(
            ['file_id' => 999, 'task_id' => 42, 'project_id' => 5],
            ['id' => 999, 'name' => 'notes.txt', 'path' => 'tasks/42/notes.txt', 'task_id' => 42],
            'CONFIDENTIAL'
        );

        $controller = new FilePreviewController(
            $container,
            new PermissionService(new MockPermissionChecker(true))
        );

        $controller->show();

        $this->assertSame(403, $container->offsetGet('response')->statusCode);
        $this->assertSame('access_denied', $container->offsetGet('template')->renderedVars['reason']);
    }

    public function testEditRefusesAttachmentOwnedByAnotherTask(): void
    {
        $container = $this->buildContainer(
            ['file_id' => 999, 'task_id' => 8, 'project_id' => 5],
            ['id' => 999, 'name' => 'budget.txt', 'path' => 'tasks/42/budget.txt', 'task_id' => 42],
            'CONFIDENTIAL'
        );

        $controller = new FileEditController(
            $container,
            new PermissionService(new MockPermissionChecker(true))
        );

        $controller->edit();

        $this->assertSame(403, $container->offsetGet('response')->statusCode);
        $this->assertSame('access_denied', $container->offsetGet('template')->renderedVars['reason']);
    }

    /**
     * The write primitive: the foreign file's bytes must be untouched afterwards.
     */
    public function testUpdateRefusesToOverwriteAttachmentOwnedByAnotherTask(): void
    {
        $storage = new FakeObjectStorage('ORIGINAL');
        $container = new FakeContainer([
            'request' => new FakeRequest([
                'file_id' => 999,
                'task_id' => 8,
                'project_id' => 5,
                'content' => 'TAMPERED',
            ]),
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'taskFileModel' => new FakeFileModel(
                ['id' => 999, 'name' => 'budget.txt', 'path' => 'tasks/42/budget.txt', 'task_id' => 42]
            ),
            'objectStorage' => $storage,
            'taskFinderModel' => new FakeTaskFinder([42 => 7, 8 => 5]),
            'userSession' => new FakeUserSession(3),
        ]);

        $controller = new FileEditController(
            $container,
            new PermissionService(new MockPermissionChecker(true))
        );

        $controller->update();

        $this->assertSame(403, $container->offsetGet('response')->statusCode);
        $this->assertSame('ORIGINAL', $storage->get('tasks/42/budget.txt'));
    }

    /**
     * The gate must not fire on the legitimate case, or every preview breaks.
     */
    public function testPreviewAllowsAttachmentOwnedByTheRequestedTask(): void
    {
        $container = $this->buildContainer(
            ['file_id' => 1, 'task_id' => 8, 'project_id' => 5],
            ['id' => 1, 'name' => 'notes.txt', 'path' => 'tasks/8/notes.txt', 'task_id' => 8],
            'hello world'
        );

        $controller = new FilePreviewController(
            $container,
            new PermissionService(new MockPermissionChecker(true))
        );

        $controller->show();

        $this->assertNotSame(403, $container->offsetGet('response')->statusCode);
        $this->assertNotSame(
            'FileInteractionCore:file/preview_error',
            $container->offsetGet('template')->renderedTemplate
        );
    }

    // ---------------------------------------------------------------------
    // KanboardPermissionChecker: the real ACL, replacing the allow-all mock.
    // ---------------------------------------------------------------------

    public function testCheckerDeniesNonMemberOfProject(): void
    {
        $checker = new KanboardPermissionChecker(new FakeContainer([
            'userSession' => new FakeUserSession(3),
            'projectPermissionModel' => new FakeProjectPermissionModel(['5:3' => true]),
            'taskFinderModel' => new FakeTaskFinder([8 => 5]),
        ]));

        $this->assertTrue($checker->canReadProject(5, 3));
        $this->assertFalse($checker->canReadProject(7, 3));
    }

    public function testCheckerDeniesTaskFromAnotherProject(): void
    {
        $checker = new KanboardPermissionChecker(new FakeContainer([
            'userSession' => new FakeUserSession(3),
            'projectPermissionModel' => new FakeProjectPermissionModel(['5:3' => true]),
            'taskFinderModel' => new FakeTaskFinder([42 => 7, 8 => 5]),
        ]));

        $this->assertTrue($checker->canReadFile(5, 8, 1, 3));
        $this->assertFalse($checker->canReadFile(5, 42, 999, 3));
    }

    /**
     * A checker that cannot reach the models it needs must answer "no", never "yes".
     */
    public function testCheckerFailsClosedWithoutModels(): void
    {
        $checker = new KanboardPermissionChecker(new FakeContainer([]));

        $this->assertFalse($checker->canReadProject(5, 3));
        $this->assertFalse($checker->canReadFile(5, 8, 1, 3));
        $this->assertFalse($checker->canWriteProject(5, 3));
    }

    /**
     * A project VIEWER may read an attachment but must not be able to overwrite it.
     */
    public function testViewerCanReadButNotWrite(): void
    {
        $service = new PermissionService(new KanboardPermissionChecker(new FakeContainer([
            'userSession' => new FakeUserSession(3),
            'projectPermissionModel' => new FakeProjectPermissionModel(
                ['5:3' => true],   // allowed to see project 5
                []                 // but not a member of it
            ),
            'taskFinderModel' => new FakeTaskFinder([8 => 5]),
        ])));

        $this->assertTrue($service->canUserReadFile(5, 8, 1, 3));
        $this->assertFalse($service->canUserWriteFile(5, 8, 1, 3));
    }

    public function testMemberCanWrite(): void
    {
        $service = new PermissionService(new KanboardPermissionChecker(new FakeContainer([
            'userSession' => new FakeUserSession(3),
            'projectPermissionModel' => new FakeProjectPermissionModel(['5:3' => true], ['5:3' => true]),
            'taskFinderModel' => new FakeTaskFinder([8 => 5]),
        ])));

        $this->assertTrue($service->canUserWriteFile(5, 8, 1, 3));
    }

    /**
     * Controllers must install the real checker whenever Kanboard's models are
     * reachable — the allow-all mock must no longer be able to serve a request.
     */
    public function testControllerInstallsRealCheckerWhenContainerHasAclModels(): void
    {
        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 1, 'task_id' => 8, 'project_id' => 5]),
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'taskFileModel' => new FakeFileModel(
                ['id' => 1, 'name' => 'notes.txt', 'path' => 'tasks/8/notes.txt', 'task_id' => 8]
            ),
            'objectStorage' => new FakeObjectStorage('secret payload'),
            'taskFinderModel' => new FakeTaskFinder([8 => 5]),
            'userSession' => new FakeUserSession(3),
            // User 3 has NO access to project 5.
            'projectPermissionModel' => new FakeProjectPermissionModel([], []),
        ]);

        // No PermissionService injected: the controller must build its own.
        $controller = new FilePreviewController($container);
        $controller->show();

        $this->assertSame(403, $container->offsetGet('response')->statusCode);
        $this->assertStringNotContainsString(
            'secret payload',
            (string) $container->offsetGet('response')->body
        );
    }
}
