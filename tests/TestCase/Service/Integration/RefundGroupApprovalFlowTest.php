<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceHistoryService;
use App\Service\NotificationService;
use App\Service\RefundApprovalGuard;
use App\Service\RefundApprovalService;
use App\Service\RefundPipelineService;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundApprovalFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Test de integración end-to-end del flujo de aprobación de área en lote
 * (Reintegros): vincular facturas en aprobacion, aprobar en grupo con todos
 * los aprobadores, y avanzar el reintegro propagando el estado a las
 * facturas hijas. Cubre también el gate de quórum sin bypass.
 */
class RefundGroupApprovalFlowTest extends TestCase
{
    public function testApproveThenAdvanceMovesChildrenToContabilidad(): void
    {
        $refund = RefundFactory::new(['accrued' => true, 'ready_for_payment' => true])
            ->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $linked = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // Soporte de la hija: el gate de grupo exige ≥1 documento por factura.
        InvoiceDocumentFactory::new(['invoice_id' => $linked->id])->save();
        $approver = UserFactory::new()->save();
        $operator = UserFactory::new()->save();

        // Aprobación de grupo.
        $approvalSvc = new RefundApprovalService($this->createMock(NotificationService::class));
        $approvalSvc->assignApprovers($refund, [$approver->id], 'https://x', (int)$operator->id);
        $a = TableRegistry::getTableLocator()->get('RefundApprovals')
            ->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $approvalSvc->applyFreshToken($a);
        TableRegistry::getTableLocator()->get('RefundApprovals')->saveOrFail($a);
        $approvalSvc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        $this->assertTrue($approvalSvc->areAllApproved((int)$refund->id));

        // Sin errores de avance (quórum + DIAN OK).
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);
        $auth->method('operableSteps')->willReturn([]);
        $pipeline = new RefundPipelineService(new InvoiceHistoryService(), $auth);

        $this->assertSame([], $pipeline->validateTransitionRequirements($refund));

        // Avance real → contabilidad; hijas propagadas.
        $result = $pipeline->advance($refund, $operator->role_id, $operator->id);
        $this->assertTrue($result->success, implode(' ', (array)$result->errors));

        $reloadedRefund = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_CONTABILIDAD, $reloadedRefund->status);
        $child = TableRegistry::getTableLocator()->get('Invoices')->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $child->pipeline_status);
    }

    public function testAdvanceSelfHealsAreaApprovalOnChildren(): void
    {
        $refund = RefundFactory::new(['accrued' => true, 'ready_for_payment' => true])
            ->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $child = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'area_approval' => InvoiceConstants::APPROVAL_PENDING,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();
        $approver = UserFactory::new()->save();
        $operator = UserFactory::new()->save();

        // Fila de aprobación creada directamente como "aprobada" (quórum de 1),
        // SIN pasar por processResponse — onAllApproved nunca se ejecuta, así
        // que area_approval de la hija queda divergente del gate por filas.
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $approver->id])->approved()->save();

        $guard = new RefundApprovalGuard();
        $this->assertTrue($guard->allApproved((int)$refund->id));

        $childBefore = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertNotSame(InvoiceConstants::APPROVAL_APPROVED, $childBefore->area_approval);

        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);
        $auth->method('operableSteps')->willReturn([]);
        $pipeline = new RefundPipelineService(new InvoiceHistoryService(), $auth);

        $result = $pipeline->advance($refund, $operator->role_id, $operator->id);
        $this->assertTrue($result->success, implode(' ', (array)$result->errors));

        $childAfter = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $childAfter->area_approval);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $childAfter->pipeline_status);
    }

    public function testAdvanceBlockedWithoutQuorum(): void
    {
        $refund = RefundFactory::new(['accrued' => true, 'ready_for_payment' => true])
            ->withStatus(RefundConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'dian_validation' => InvoiceConstants::DIAN_APPROVED])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);
        $pipeline = new RefundPipelineService(new InvoiceHistoryService(), $auth);

        $errors = $pipeline->validateTransitionRequirements($refund);
        $this->assertNotEmpty($errors); // sin aprobadores → gate de quórum bloquea
    }

    /**
     * Gate de soporte documental a nivel de grupo: con quórum y DIAN aprobada,
     * una hija sin ningún documento bloquea el avance; al subir el soporte el
     * avance pasa.
     */
    public function testAdvanceBlockedWhenChildHasNoSupportDocument(): void
    {
        $refund = RefundFactory::new(['accrued' => true, 'ready_for_payment' => true])
            ->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $child = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'F-SIN-SOPORTE',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();
        $operator = UserFactory::new()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $approver->id])->approved()->save();

        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);
        $auth->method('operableSteps')->willReturn([]);
        $pipeline = new RefundPipelineService(new InvoiceHistoryService(), $auth);

        $errors = $pipeline->validateTransitionRequirements($refund);
        $this->assertStringContainsString('Soporte pendiente', implode(' ', $errors));
        $this->assertStringContainsString('F-SIN-SOPORTE', implode(' ', $errors));

        $blocked = $pipeline->advance($refund, $operator->role_id, $operator->id);
        $this->assertFalse($blocked->success);
        $this->assertSame(
            RefundConstants::STATUS_APROBACION,
            TableRegistry::getTableLocator()->get('Refunds')->get($refund->id)->status,
        );

        // Se sube el soporte → el gate se libera.
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();

        $this->assertSame([], $pipeline->validateTransitionRequirements($refund));
        $result = $pipeline->advance($refund, $operator->role_id, $operator->id);
        $this->assertTrue($result->success, implode(' ', (array)$result->errors));
        $this->assertSame(
            RefundConstants::STATUS_CONTABILIDAD,
            TableRegistry::getTableLocator()->get('Refunds')->get($refund->id)->status,
        );
    }
}
