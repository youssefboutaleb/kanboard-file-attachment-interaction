<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Unit;

require_once __DIR__ . '/../stubs/BaseController.php';
require_once __DIR__ . '/../stubs/FakeContainer.php';

use Kanboard\Plugin\FileInteractionCore\Controller\FileEditController;
use Kanboard\Plugin\FileInteractionCore\Service\MockPermissionChecker;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeContainer;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFileModel;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeObjectStorage;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeRequest;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeResponse;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeTemplate;
use Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeUserSession;
use PHPUnit\Framework\TestCase;

class FileEditControllerTest extends TestCase
{
    public function testEditActionRendersModalForAuthorizedUser(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $result = $controller->edit(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertIsArray($result);
        $this->assertSame(1, $result['fileId']);
        $this->assertSame('config.json', $result['filename']);
        $this->assertSame('json', $result['extension']);
        $this->assertSame('{"status":"ok"}', $result['content']);
    }

    public function testEditActionReturns403ForUnauthorizedUser(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(false)));

        $result = $controller->edit(1, 10, 100, 'config.json', '{"status":"ok"}');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['statusCode']);
        $this->assertSame('access_denied', $result['reason']);
    }

    public function testUpdateActionSavesValidPayload(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $result = $controller->update(1, 10, 100, 'notes.txt', 'Updated content', 'overwrite');

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('overwrite', $result['mode']);
    }

    public function testUpdateActionRejectsInvalidJson(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $invalidJson = "{\n  \"status\": active\n}";
        $result = $controller->update(1, 10, 100, 'config.json', $invalidJson, 'overwrite');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('validation_error', $result['reason']);
        $this->assertNotNull($result['errorLine']);
    }

    public function testUpdateActionRejectsInvalidCsrfToken(): void
    {
        $container = new FakeContainer([
            'request' => new FakeRequest(['file_id' => 1, 'task_id' => 10, 'project_id' => 100, 'content' => 'Data']),
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'userSession' => new FakeUserSession(1),
            'taskFileModel' => new FakeFileModel(['id' => 1, 'name' => 'test.txt', 'path' => 'tasks/1/test.txt']),
            'objectStorage' => new FakeObjectStorage('Data'),
            'token' => new class {
                public function validateCSRFToken(): bool { return false; }
            },
        ]);

        $controller = new FileEditController($container, new PermissionService(new MockPermissionChecker(true)));
        $response = $controller->update();

        $this->assertNotNull($response);
    }

    public function testEditAndSaveCsv(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $csvData = "Name,Role\nAlice,Admin\nBob,User";
        $editResult = $controller->edit(1, 10, 100, 'users.csv', $csvData);

        $this->assertIsArray($editResult);
        $this->assertSame('users.csv', $editResult['filename']);
        $this->assertSame('csv', $editResult['extension']);
        $this->assertSame($csvData, $editResult['content']);

        $updatedCsv = "Name,Role\nAlice,Admin\nBob,Superuser";
        $updateResult = $controller->update(1, 10, 100, 'users.csv', $updatedCsv, 'overwrite');
        $this->assertIsArray($updateResult);
        $this->assertTrue($updateResult['success']);
    }

    public function testEditAndSaveXlsx(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $excelWriter = new \Kanboard\Plugin\FileInteractionCore\Service\ExcelWriterService();
        $xlsxBinary = $excelWriter->csvToXlsx("Item,Price\nBook,15\nPen,2");

        $editResult = $controller->edit(1, 10, 100, 'inventory.xlsx', $xlsxBinary);
        $this->assertIsArray($editResult);
        $this->assertSame('inventory.xlsx', $editResult['filename']);
        $this->assertSame('xlsx', $editResult['extension']);
        $this->assertStringContainsString('Item,Price', $editResult['content']);
        $this->assertStringContainsString('Book,15', $editResult['content']);

        $updateResult = $controller->update(1, 10, 100, 'inventory.xlsx', "Item,Price\nBook,18\nPen,3", 'overwrite');
        $this->assertIsArray($updateResult);
        $this->assertTrue($updateResult['success']);
    }

    public function testEditSpreadsheetReturnsStructuredSheets(): void
    {
        $controller = new FileEditController(null, new PermissionService(new MockPermissionChecker(true)));

        $excelWriter = new \Kanboard\Plugin\FileInteractionCore\Service\ExcelWriterService();
        $xlsxBinary = $excelWriter->buildXlsxFromMultiSheet([
            'Q1' => [['Product', 'Sales'], ['Apples', '100']],
            'Q2' => [['Product', 'Sales'], ['Apples', '120']],
        ]);

        $editResult = $controller->edit(1, 10, 100, 'report.xlsx', $xlsxBinary);
        $this->assertIsArray($editResult);
        $this->assertTrue($editResult['isSpreadsheet']);
        $this->assertSame(['Q1', 'Q2'], $editResult['sheetNames']);
        $this->assertSame('Q1', $editResult['activeSheet']);
        $this->assertArrayHasKey('Q1', $editResult['sheets']);
        $this->assertArrayHasKey('Q2', $editResult['sheets']);
    }

    public function testUpdateWithGridDataSavesValidMultiSheetXlsx(): void
    {
        $fakeStorage = new FakeObjectStorage('');
        $container = new FakeContainer([
            'request' => new FakeRequest([
                'file_id' => 1,
                'task_id' => 10,
                'project_id' => 100,
                'grid_data' => json_encode([
                    'Sales' => [['Product', 'Revenue'], ['Widget', '500']],
                    'Costs' => [['Category', 'Amount'], ['Marketing', '200']],
                ]),
                'csrf_token' => 'valid_token',
            ]),
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'userSession' => new FakeUserSession(1),
            'taskFileModel' => new FakeFileModel(['id' => 1, 'name' => 'finance.xlsx', 'path' => 'tasks/1/finance.xlsx']),
            'objectStorage' => $fakeStorage,
            'token' => new class {
                public function validateCSRFToken(string $token): bool { return $token === 'valid_token'; }
            },
        ]);

        $controller = new FileEditController($container, new PermissionService(new MockPermissionChecker(true)));
        $response = $controller->update();
        $this->assertNotNull($response);

        // Verify stored content is valid XLSX with both sheets
        $savedBytes = $fakeStorage->get('tasks/1/finance.xlsx');
        $parser = new \Kanboard\Plugin\FileInteractionCore\Service\ExcelParserService();
        $parsed = $parser->parseXlsxContent($savedBytes);
        $this->assertSame(['Sales', 'Costs'], $parsed['sheetNames']);
        $this->assertSame('Widget', $parsed['sheets']['Sales']['rows'][1][0]);
    }

    public function testEditInStandaloneModeWrapsWithApplicationLayout(): void
    {
        $fakeHelper = new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeHelper();
        $container = new FakeContainer([
            'request' => new FakeRequest([], false), // Non-AJAX standalone request
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'userSession' => new FakeUserSession(1),
            'taskFileModel' => new FakeFileModel(['id' => 1, 'name' => 'sample.txt', 'path' => 'tasks/1/sample.txt']),
            'objectStorage' => new FakeObjectStorage('hello world'),
            'helper' => $fakeHelper,
        ]);

        $controller = new FileEditController($container, new PermissionService(new MockPermissionChecker(true)));
        $response = $controller->edit(1, 10, 100, 'sample.txt', 'hello world');

        $this->assertSame('LAYOUT_APP:FileInteractionCore:file/edit:Edit sample.txt', $response);
    }

    public function testUpdateNonAjaxRedirectsToPreviewWithFlash(): void
    {
        $fakeStorage = new FakeObjectStorage('');
        $fakeHelper = new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeHelper();
        $fakeFlash = new \Kanboard\Plugin\FileInteractionCore\Tests\Stubs\FakeFlash();

        $container = new FakeContainer([
            'request' => new FakeRequest([
                'file_id' => 1,
                'task_id' => 10,
                'project_id' => 100,
                'content' => 'updated text',
                'csrf_token' => 'valid_token',
            ], false), // Non-AJAX
            'response' => new FakeResponse(),
            'template' => new FakeTemplate(),
            'userSession' => new FakeUserSession(1),
            'taskFileModel' => new FakeFileModel(['id' => 1, 'name' => 'sample.txt', 'path' => 'tasks/1/sample.txt']),
            'objectStorage' => $fakeStorage,
            'helper' => $fakeHelper,
            'flash' => $fakeFlash,
            'token' => new class {
                public function validateCSRFToken(string $token): bool { return $token === 'valid_token'; }
            },
        ]);

        $controller = new FileEditController($container, new PermissionService(new MockPermissionChecker(true)));
        $response = $controller->update();

        $this->assertSame('/?controller=FilePreviewController&action=show', $response);
        $this->assertSame('File saved successfully.', $fakeFlash->message);
        $this->assertSame('updated text', $fakeStorage->get('tasks/1/sample.txt'));
    }
}



