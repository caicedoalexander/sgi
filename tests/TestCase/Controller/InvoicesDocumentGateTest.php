<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class InvoicesDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        // Rol con el step operable pero SIN permisos CRUD del módulo.
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        // Sin archivo: el gate pasa y falla después por archivo faltante
        // (Invoices responde 200 + success:false, NO 403). Prueba que el gate CRUD ya no aplica.
        $this->post(['controller' => 'Invoices', 'action' => 'uploadDocument', $invoice->id]);

        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_TESORERIA); // opera OTRO step
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'Invoices', 'action' => 'uploadDocument', $invoice->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadConflictWhenInvoiceInFinalState(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'Invoices', 'action' => 'uploadDocument', $invoice->id]);

        $this->assertResponseCode(409);
    }

    public function testDeleteForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_TESORERIA); // opera OTRO step
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        // documentId inexistente: el gate (403) corre ANTES de buscar el documento.
        $this->post(['controller' => 'Invoices', 'action' => 'deleteDocument', $invoice->id, 999999]);

        $this->assertResponseCode(403);
    }
}
