<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class PettyCashRecordsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_PETTY_CASH,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(200); // sin archivo → success:false + mensaje (este módulo responde 200 en JSON de error de archivo)
        $this->assertResponseContains('archivo');
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadConflictWhenRecordPagada(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(409);
    }

    public function testDeleteForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'deleteDocument', $record->id, 999999]);

        $this->assertResponseCode(403);
    }
}
