<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\EmployeeDocumentService;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class EmployeesFolderSelectionTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Loguea un usuario con permisos completos sobre el módulo employees.
     */
    private function login(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'employees',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_delete' => true,
        ]));
        $this->session(['Auth' => UserFactory::new(['role_id' => $role->id])->save()]);
    }

    private function service(): EmployeeDocumentService
    {
        return new EmployeeDocumentService();
    }

    private function makePdfUpload(string $name = 'a.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'empdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    public function testViewAutoselectsFirstFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        // El orderBy de view() es por nombre ASC, asi que 'Antecedentes' va primero
        // aunque se cree despues.
        $service->createFolder($employee->id, 'Contratos', null);
        $primera = $service->createFolder($employee->id, 'Antecedentes', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id);

        $this->assertResponseOk();
        $this->assertSame((string)$primera->id, $this->viewVariable('selectedFolderId'));
        $this->assertSame('Antecedentes', $this->viewVariable('selectedFolderName'));
    }

    public function testViewHonorsValidFolderQuery(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $service->createFolder($employee->id, 'Antecedentes', null);
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $contratos->id);

        $this->assertResponseOk();
        $this->assertSame((string)$contratos->id, $this->viewVariable('selectedFolderId'));
        $this->assertSame('Contratos', $this->viewVariable('selectedFolderName'));
    }

    public function testViewFallsBackToFirstFolderWhenQueryIsForeign(): void
    {
        $employee = EmployeeFactory::new()->save();
        $otro = EmployeeFactory::new()->save();
        $service = $this->service();
        $primera = $service->createFolder($employee->id, 'Antecedentes', null)->data;
        $ajena = $service->createFolder($otro->id, 'Ajena', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $ajena->id);

        $this->assertResponseOk();
        $this->assertSame((string)$primera->id, $this->viewVariable('selectedFolderId'));
    }

    public function testViewFallsBackToFirstFolderWhenQueryIsNotScalar(): void
    {
        $employee = EmployeeFactory::new()->save();
        $primera = $this->service()->createFolder($employee->id, 'Antecedentes', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder[]=1');

        $this->assertResponseOk();
        $this->assertSame((string)$primera->id, $this->viewVariable('selectedFolderId'));
    }

    public function testDeleteDocumentRedirectsToItsFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $service->createFolder($employee->id, 'Antecedentes', null);
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;
        $service->uploadDocuments($employee->id, $contratos->id, [$this->makePdfUpload()], null);
        $document = TableRegistry::getTableLocator()->get('EmployeeDocuments')->find()->firstOrFail();

        $this->login();
        $this->enableCsrfToken();
        $this->post('/employees/delete-document/' . $employee->id . '/' . $document->id);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/employees/view/' . $employee->id);
        $this->assertRedirectContains('folder=' . $contratos->id);
    }

    public function testAddFolderRedirectsToTheNewFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $this->service()->createFolder($employee->id, 'Antecedentes', null);

        $this->login();
        $this->enableCsrfToken();
        $this->post('/employees/add-folder/' . $employee->id, ['name' => 'Vacaciones']);

        $nueva = TableRegistry::getTableLocator()->get('EmployeeFolders')
            ->find()->where(['name' => 'Vacaciones'])->firstOrFail();

        $this->assertResponseCode(302);
        $this->assertRedirectContains('folder=' . $nueva->id);
    }

    public function testViewNoLongerRendersTheSyntheticRootNode(): void
    {
        $employee = EmployeeFactory::new()->save();
        $this->service()->createFolder($employee->id, 'Antecedentes', null);

        $this->login();
        $this->get('/employees/view/' . $employee->id);

        $this->assertResponseOk();
        $this->assertResponseNotContains('data-folder-id="all"');
    }

    public function testViewMarksTheSelectedFolderAsActive(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $primera = $service->createFolder($employee->id, 'Antecedentes', null)->data;
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $contratos->id);

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        // El ancla de la carpeta pedida es la activa...
        $this->assertMatchesRegularExpression(
            '/class="doc-tree-item is-active"[^>]*data-folder-id="' . $contratos->id . '"/s',
            $body,
        );
        // ...y la primera carpeta NO lo es.
        $this->assertDoesNotMatchRegularExpression(
            '/class="doc-tree-item is-active"[^>]*data-folder-id="' . $primera->id . '"/s',
            $body,
        );
    }

    public function testUploadModalPreselectsTheActiveFolder(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $service->createFolder($employee->id, 'Antecedentes', null);
        $contratos = $service->createFolder($employee->id, 'Contratos', null)->data;

        $this->login();
        $this->get('/employees/view/' . $employee->id . '?folder=' . $contratos->id);

        $this->assertResponseOk();
        $this->assertResponseContains('value="' . $contratos->id . '" selected="selected"');
    }
}
