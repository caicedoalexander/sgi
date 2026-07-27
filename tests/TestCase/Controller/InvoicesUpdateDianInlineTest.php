<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * `InvoicesController::updateDianInline()` — resolución inline del DIAN desde
 * la tabla de hijas de un padre (Reintegro / Caja Menor / Anticipo), sin salir
 * de la vista del padre (spec 2026-07-14 §3.4).
 *
 * Es un endpoint de MUTACIÓN por AJAX: los 4 gates (pertenencia al padre,
 * estado `aprobacion`, valor/doctype válidos, RBAC de pipeline + campo
 * editable) son el contrato bajo test.
 */
final class InvoicesUpdateDianInlineTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Rol con los DOS seeds que exige el endpoint: `permissions` (módulo
     * `invoices`, can_view + can_edit → atributo #[Permission(action: 'edit')])
     * y `pipeline_permissions` (pipeline `invoices`, paso `aprobacion` →
     * canOperate + campos editables del FieldAccessPolicy, que es rol-aware).
     */
    private function userWithInvoiceAprobacionOperator(bool $withPipelinePermission = true): object
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'invoices',
            'can_view' => true,
            'can_edit' => true,
        ]));

        if ($withPipelinePermission) {
            $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
                'step' => InvoiceConstants::STATUS_APROBACION,
                'can_operate' => true,
            ]));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postDian(object $user, int $invoiceId, array $data): void
    {
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]]);
        $this->post('/invoices/update-dian-inline/' . $invoiceId, $data);
    }

    private function dianInDb(int $invoiceId): ?string
    {
        return TableRegistry::getTableLocator()->get('Invoices')->get($invoiceId)->dian_validation;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    public function testHappyPathUpdatesDianAndReturnsReadiness(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator();
        $refund = RefundFactory::new()->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // Con soporte cargado, resolver el DIAN deja el grupo sin pendientes.
        InvoiceDocumentFactory::new(['invoice_id' => $invoice->id])->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'parent_field' => 'refund_id',
            'parent_id' => $refund->id,
        ]);

        $this->assertResponseOk();
        $this->assertSame(InvoiceConstants::DIAN_APPROVED, $this->dianInDb($invoice->id));

        $body = $this->jsonBody();
        $this->assertTrue($body['success']);
        $this->assertSame(InvoiceConstants::DIAN_APPROVED, $body['dian_validation']);
        $this->assertSame(0, $body['readiness']['dian_pending']);
        $this->assertSame(0, $body['readiness']['support_missing']);
        $this->assertFalse($body['readiness']['blocked']);

        // La auditoría del cambio queda registrada (dian_validation ∈ FIELDS_TO_TRACK).
        $histories = TableRegistry::getTableLocator()->get('InvoiceHistories')
            ->find()
            ->where(['invoice_id' => $invoice->id, 'field_changed' => 'dian_validation'])
            ->all()
            ->toArray();
        $this->assertCount(1, $histories);
        $this->assertSame(InvoiceConstants::DIAN_APPROVED, $histories[0]->new_value);
    }

    /**
     * Anti-IDOR: la factura pertenece a OTRO reintegro. Debe responder 404 y,
     * sobre todo, NO haber tocado la BD (el rechazo debe ser real, no cosmético).
     */
    public function testRejectsInvoiceNotBelongingToParent(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator();
        $ownerRefund = RefundFactory::new()->save();
        $otherRefund = RefundFactory::new()->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $ownerRefund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'parent_field' => 'refund_id',
            'parent_id' => $otherRefund->id,
        ]);

        $this->assertResponseCode(404);
        $this->assertSame(InvoiceConstants::DIAN_PENDING, $this->dianInDb($invoice->id));
        $this->assertFalse($this->jsonBody()['success']);
    }

    /**
     * `parent_field` fuera de la whitelist `PARENT_FOREIGN_KEYS` (aquí una
     * columna real de Invoices) no puede usarse como criterio de pertenencia.
     */
    public function testRejectsParentFieldOutsideWhitelist(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator();
        $invoice = InvoiceFactory::new([
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'parent_field' => 'id',
            'parent_id' => $invoice->id,
        ]);

        $this->assertResponseCode(404);
        $this->assertSame(InvoiceConstants::DIAN_PENDING, $this->dianInDb($invoice->id));
    }

    /**
     * Tabla stale en el navegador: la factura ya avanzó de `aprobacion`.
     * El endpoint debe fallar EXPLÍCITAMENTE (409), nunca escribir en silencio.
     */
    public function testRejectsInvoiceOutsideAprobacion(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator();
        $refund = RefundFactory::new()->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'parent_field' => 'refund_id',
            'parent_id' => $refund->id,
        ]);

        $this->assertResponseCode(409);
        $this->assertSame(InvoiceConstants::DIAN_PENDING, $this->dianInDb($invoice->id));
        $this->assertFalse($this->jsonBody()['success']);
    }

    /**
     * El rol tiene can_edit del módulo `invoices` (pasa el #[Permission]) pero
     * NINGUNA fila en `pipeline_permissions` → no puede operar `aprobacion`.
     */
    public function testRejectsRoleWithoutPipelinePermission(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator(withPipelinePermission: false);
        $refund = RefundFactory::new()->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'parent_field' => 'refund_id',
            'parent_id' => $refund->id,
        ]);

        $this->assertResponseCode(403);
        $this->assertSame(InvoiceConstants::DIAN_PENDING, $this->dianInDb($invoice->id));
        $this->assertFalse($this->jsonBody()['success']);
    }

    /** El Recibo de Caja está exento de DIAN: no hay nada que resolver. */
    public function testRejectsDianExemptDoctype(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator();
        $refund = RefundFactory::new()->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'parent_field' => 'refund_id',
            'parent_id' => $refund->id,
        ]);

        $this->assertResponseCode(422);
        $this->assertSame(InvoiceConstants::DIAN_PENDING, $this->dianInDb($invoice->id));
        $this->assertFalse($this->jsonBody()['success']);
    }

    /** Valor fuera de `DIAN_STATUSES` → 422, sin escritura. */
    public function testRejectsInvalidDianValue(): void
    {
        $user = $this->userWithInvoiceAprobacionOperator();
        $refund = RefundFactory::new()->save();
        $invoice = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->postDian($user, $invoice->id, [
            'dian_validation' => 'Aprobadisima',
            'parent_field' => 'refund_id',
            'parent_id' => $refund->id,
        ]);

        $this->assertResponseCode(422);
        $this->assertSame(InvoiceConstants::DIAN_PENDING, $this->dianInDb($invoice->id));
    }
}
