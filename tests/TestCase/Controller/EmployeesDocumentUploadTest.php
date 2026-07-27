<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class EmployeesDocumentUploadTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // assertFlashMessage()/assertFlashElement() leen de _requestSession, que
        // sólo recibe el flash re-inyectado si retain está activo (patrón de
        // UsersControllerTest.php:27).
        $this->enableRetainFlashMessages();
    }

    private function userWithCreate(bool $canCreate): object
    {
        $role = RoleFactory::new()->save();
        if ($canCreate) {
            $permissions = TableRegistry::getTableLocator()->get('Permissions');
            $permissions->saveOrFail($permissions->newEntity([
                'role_id' => $role->id,
                'module' => 'employees',
                'can_create' => true,
            ]));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    public function testUploadForbiddenWithoutCreatePermission(): void
    {
        $employee = EmployeeFactory::new()->save();

        $this->session(['Auth' => $this->userWithCreate(false)]);
        $this->enableCsrfToken();
        $this->post('/employees/upload-document/' . $employee->id, ['employee_folder_id' => 1]);

        $this->assertResponseCode(403);
    }

    public function testUploadWithoutFileRedirectsWithError(): void
    {
        $employee = EmployeeFactory::new()->save();

        $this->session(['Auth' => $this->userWithCreate(true)]);
        $this->enableCsrfToken();
        $this->post('/employees/upload-document/' . $employee->id, ['employee_folder_id' => 1]);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/employees/view/' . $employee->id);
        $this->assertFlashElement('flash/error');
        $this->assertFlashMessage('No se recibió ningún archivo válido.');
    }
}
