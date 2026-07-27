<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\EmployeeNoveltyFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class NoveltyDocumentsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_NOVELTIES,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, NoveltyConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $novelty = EmployeeNoveltyFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyDocuments', 'action' => 'upload', $novelty->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $novelty = EmployeeNoveltyFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyDocuments', 'action' => 'upload', $novelty->id]);

        // El gate pasa (rol opera el step) y falla después por archivo faltante
        // (200 + success:false), NUNCA 403. Prueba que el gate CRUD ya no aplica.
        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }

    public function testDeleteForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, NoveltyConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $novelty = EmployeeNoveltyFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyDocuments', 'action' => 'delete', $novelty->id, 999999]);

        $this->assertResponseCode(403);
    }
}
