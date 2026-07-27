<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Service\NotificationService;
use App\Service\RefundApprovalService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración autenticados de RefundsController: regressStatus (M1) y
 * linkInvoices (M2) supersedan las aprobaciones activas del grupo/factura vinculada.
 *
 * RBAC: `regressStatus` usa `#[PipelineAction(pipeline: refunds)]` sin `step`
 * (acción dinámica) — el gate CRUD del controller se salta y el rol se valida
 * dentro de `RefundPipelineService::denialReasonForRegress()` vía
 * `pipeline_permissions` (rol, pipeline, step=status actual). `linkInvoices` usa
 * `#[Permission(action: 'edit')]` — requiere una fila en `permissions` con
 * `module = 'refunds'` y `can_edit = true`. No existe factory para ninguna de
 * las 2 tablas; se siembran directo vía `TableRegistry` (mismo patrón que
 * `PipelineAuthorizationService::savePermissions()`/`AuthorizationService`).
 *
 * M2 y el mismo drift de Part A: `RefundsController::linkInvoices()` hace
 * `TableRegistry::getTableLocator()->get('InvoiceApprovals')->updateAll(['status'
 * => ..., 'token_hash' => null, ...], ...)` directo (no vía servicio inyectado,
 * no mockeable por contenedor). `invoice_approvals.token_hash` no existe
 * físicamente en la BD de test compartida (`sgi_test`) pese a que
 * `HashInvoiceApprovals`/`DropTokenFromInvoiceApprovals` están registradas como
 * aplicadas en `cake_migrations` (confirmado con `DESCRIBE` directo; la BD
 * `default` sí tiene el esquema correcto — drift real, no introducido por esta
 * tarea).
 *
 * `updateAll` es una llamada directa a `TableRegistry`, no un servicio
 * inyectado, así que no es mockeable vía `ContainerStubTrait::mockService()`.
 * Sustituir la clase directamente con `TableRegistry::getTableLocator()->get(...,
 * ['className' => ...])` ANTES del request tampoco alcanza:
 * `App\Application::bootstrap()` hace `FactoryLocator::add('Table', new
 * TableLocator())` — un locator NUEVO — en CADA request simulado por
 * `IntegrationTestTrait` (`createApp()` instancia una `Application` nueva por
 * `_sendRequest`), así que cualquier swap hecho antes del request queda
 * descartado antes de que el controller corra. La sustitución se aplica
 * entonces vía `ContainerStubTrait::configApplication()` (seam oficial de
 * CakePHP para tests de integración) con una subclase de `Application` que
 * reaplica el swap DESPUÉS de `parent::bootstrap()` — momento en que sí
 * persiste para ese request. `InvoiceApprovalsTableWithoutTokenHashColumn`
 * omite esa única columna inexistente en `updateAll()` y delega el resto a la
 * Table real — permite verificar el efecto REAL de supersede contra datos
 * reales sin tocar BD ni código de producción.
 */
class RefundsControllerGroupSupersessionTest extends TestCase
{
    use IntegrationTestTrait;

    public function testRegressStatusSupersedesGroupApprovals(): void
    {
        $role = RoleFactory::new()->save();
        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_REFUNDS,
            'step' => RefundConstants::STATUS_APROBACION,
            'can_operate' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();
        $approvalService = new RefundApprovalService($this->createStub(NotificationService::class));
        $approvalService->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/refunds/regress-status/' . $refund->id, [
            'reason' => 'Motivo de prueba con longitud suficiente para pasar la validación.',
        ]);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/refunds');

        $refundApprovalsTable = TableRegistry::getTableLocator()->get('RefundApprovals');
        $rows = $refundApprovalsTable->find()->where(['refund_id' => $refund->id])->all();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $this->assertSame(InvoiceConstants::APPROVER_STATUS_SUPERSEDED, $row->status);
        }

        $reloadedRefund = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_AGRUPACION, $reloadedRefund->status);
    }

    public function testLinkInvoicesSupersedesActiveInvoiceApprovals(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'refunds',
            'can_view' => true,
            'can_edit' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_AGRUPACION)->save();
        $invoice = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_REINTEGRO])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();

        $invoiceApprovalsTable = TableRegistry::getTableLocator()->get('InvoiceApprovals');
        $approval = $invoiceApprovalsTable->saveOrFail($invoiceApprovalsTable->newEntity([
            'invoice_id' => $invoice->id,
            'user_id' => $approver->id,
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            'token_expires_at' => new DateTime('+48 hours'),
        ]));

        $this->configApplication(TestApplicationWithoutInvoiceApprovalsTokenHash::class, [CONFIG]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/refunds/link-invoices/' . $refund->id, ['invoice_ids' => [$invoice->id]]);

        $this->assertResponseCode(302);

        $reloadedApproval = TableRegistry::getTableLocator()->get('InvoiceApprovals')->get($approval->id);
        $this->assertSame(InvoiceConstants::APPROVER_STATUS_SUPERSEDED, $reloadedApproval->status);

        $reloadedInvoice = TableRegistry::getTableLocator()->get('Invoices')->get($invoice->id);
        $this->assertSame($refund->id, $reloadedInvoice->refund_id);
    }
}
