<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\NoveltyLiquidationDocFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class NoveltyLiquidationDocsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $pipeline, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => $pipeline,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadForbiddenWhenRoleOperatesNoveltiesButNotLiquidation(): void
    {
        // Bloqueante: sembrar en el pipeline EQUIVOCADO (novelties) NO debe autorizar.
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_NOVELTIES, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadAllowedForRoleOperatingLiquidationStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        // El gate pasa (rol opera el step) y falla después por archivo faltante
        // (200 + success:false), NUNCA 403. Prueba que el gate CRUD ya no aplica.
        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }

    public function testUploadConflictWhenTerminal(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        $this->assertResponseCode(409);
    }

    public function testDeleteForbiddenForRoleNotOperatingStep(): void
    {
        // Bloqueante: sembrar en el pipeline EQUIVOCADO (novelties) NO debe autorizar el borrado.
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_NOVELTIES, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'deleteDocument', $doc->id, 999999]);

        $this->assertResponseCode(403);
    }
}
