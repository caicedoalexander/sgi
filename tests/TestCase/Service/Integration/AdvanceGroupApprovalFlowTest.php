<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AdvanceConstants;
use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Service\NotificationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

/**
 * Test de integración end-to-end del flujo de aprobación de área en lote
 * (Anticipos): vincular una Legalización en aprobacion, aprobar en grupo con
 * todos los aprobadores, y avanzar la legalización propagando el estado a las
 * facturas hijas. Cubre también los gates de quórum y DIAN sin bypass, y el
 * camino de rechazo. Espejo estructural de RefundGroupApprovalFlowTest (Fase 1).
 */
final class AdvanceGroupApprovalFlowTest extends TestCase
{
    private function buildService(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    private function buildApprovalService(): AdvanceLegalizationApprovalService
    {
        return new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
    }

    /**
     * Anticipo pagado + leg en validacion con una Legalización hija vinculada
     * (invoice-aprobacion, DIAN aprobada y con soporte documental, que el gate
     * de grupo exige) y la relación de facturas (PDF) sembrada como firma
     * pendiente en advance_legalization_signatures, para que
     * ValidacionState::validateAdvance pase (facturas vinculadas + soporte
     * de relación pendiente).
     *
     * @return array{0: object, 1: object} [$leg, $child]
     */
    private function legWithLinkedChildInValidacion(): array
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $child = InvoiceFactory::new([
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->legalizacion()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();

        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signatures->saveOrFail($signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion-facturas.pdf',
            'file_name' => 'relacion-facturas.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        return [$leg, $child];
    }

    public function testFullFlowApprovesAndAdvancesToRevisionFirmas(): void
    {
        [$leg, $child] = $this->legWithLinkedChildInValidacion();
        $operator = UserFactory::new()->save();
        $approver1 = UserFactory::new()->save();
        $approver2 = UserFactory::new()->save();

        $service = $this->buildService();
        $moveResult = $service->moveToAprobacion($leg, (int)$operator->id);
        $this->assertTrue($moveResult->success, implode(' ', $moveResult->errors));

        $legInAprobacion = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_APROBACION, $legInAprobacion->status);

        $approvalSvc = $this->buildApprovalService();
        $assignResult = $approvalSvc->assignApprovers(
            $legInAprobacion,
            [$approver1->id, $approver2->id],
            'https://x',
            (int)$operator->id,
        );
        $this->assertTrue($assignResult->success, implode(' ', $assignResult->errors));
        $this->assertFalse($approvalSvc->areAllApproved((int)$leg->id));

        // Sin quórum (0/2 aprobados): moveToRevisionFirmas bloqueado, leg permanece en aprobacion.
        $blocked = $service->moveToRevisionFirmas($legInAprobacion, (int)$operator->id);
        $this->assertFalse($blocked->success);
        $this->assertSame(
            AdvanceConstants::STATUS_APROBACION,
            $this->fetchTable('AdvanceLegalizations')->get($leg->id)->status,
        );

        // Ambos aprobadores aprueban.
        $approvalsTable = $this->fetchTable('AdvanceLegalizationApprovals');
        $rows = $approvalsTable->find()->where(['advance_legalization_id' => $leg->id])->all();
        foreach ($rows as $row) {
            $secret = $approvalSvc->applyFreshToken($row);
            $approvalsTable->saveOrFail($row);
            $result = $approvalSvc->processResponse(
                $secret,
                ApprovalConstants::ACTION_APPROVE,
                null,
                '127.0.0.1',
                'phpunit',
            );
            $this->assertTrue($result->success, implode(' ', $result->errors));
        }

        $this->assertTrue($approvalSvc->areAllApproved((int)$leg->id));
        $childAfterApproval = $this->fetchTable('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $childAfterApproval->area_approval);

        // Avance real: leg → revision_firmas; hija → invoice-contabilidad.
        $legReadyToAdvance = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $advanceResult = $service->moveToRevisionFirmas($legReadyToAdvance, (int)$operator->id);
        $this->assertTrue($advanceResult->success, implode(' ', $advanceResult->errors));

        $legFinal = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_REVISION_FIRMAS, $legFinal->status);

        $childFinal = $this->fetchTable('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $childFinal->pipeline_status);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $childFinal->area_approval);
    }

    public function testApproverRejectionRegressesLegToValidacion(): void
    {
        [$leg] = $this->legWithLinkedChildInValidacion();
        $operator = UserFactory::new()->save();
        $approver1 = UserFactory::new()->save();
        $approver2 = UserFactory::new()->save();

        $service = $this->buildService();
        $moveResult = $service->moveToAprobacion($leg, (int)$operator->id);
        $this->assertTrue($moveResult->success, implode(' ', $moveResult->errors));
        $legInAprobacion = $this->fetchTable('AdvanceLegalizations')->get($leg->id);

        $approvalSvc = $this->buildApprovalService();
        $approvalSvc->assignApprovers(
            $legInAprobacion,
            [$approver1->id, $approver2->id],
            'https://x',
            (int)$operator->id,
        );

        $approvalsTable = $this->fetchTable('AdvanceLegalizationApprovals');
        $row = $approvalsTable->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $approvalSvc->applyFreshToken($row);
        $approvalsTable->saveOrFail($row);

        $result = $approvalSvc->processResponse(
            $secret,
            ApprovalConstants::ACTION_REJECT,
            'faltan soportes',
            '127.0.0.1',
            'phpunit',
        );
        $this->assertTrue($result->success, implode(' ', $result->errors));

        $legAfterReject = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $legAfterReject->status);
        $this->assertFalse($approvalSvc->hasPendingApprovals((int)$leg->id));
    }

    public function testMoveToRevisionFirmasBlockedWhenChildMissingDian(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_APROBACION)->save();
        // DIAN por defecto queda 'Pendiente' (no aprobada): la ofensora del gate DIAN.
        // Con soporte sembrado, el único requisito pendiente es el de DIAN.
        $child = InvoiceFactory::new(['advance_id' => $anticipo->id])
            ->legalizacion()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();
        $operator = UserFactory::new()->save();
        $approver = UserFactory::new()->save();

        // Quórum satisfecho (1/1 aprobado) para que el mensaje devuelto sea el de DIAN,
        // no el de quórum (moveToRevisionFirmas solo retorna el primer error).
        $approvalSvc = $this->buildApprovalService();
        $approvalSvc->assignApprovers($leg, [$approver->id], 'https://x', (int)$operator->id);
        $approvalsTable = $this->fetchTable('AdvanceLegalizationApprovals');
        $row = $approvalsTable->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $approvalSvc->applyFreshToken($row);
        $approvalsTable->saveOrFail($row);
        $approvalSvc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        $this->assertTrue($approvalSvc->areAllApproved((int)$leg->id));

        $result = $this->buildService()->moveToRevisionFirmas($leg, (int)$operator->id);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('DIAN', implode(' ', $result->errors));
        $this->assertStringContainsString((string)$child->invoice_number, implode(' ', $result->errors));
        $this->assertSame(
            AdvanceConstants::STATUS_APROBACION,
            $this->fetchTable('AdvanceLegalizations')->get($leg->id)->status,
        );
    }

    /**
     * Exención de DIAN del Recibo de Caja en el flujo real: un RC hijo con DIAN
     * pendiente (pero con soporte) NO bloquea el avance a revision_firmas.
     */
    public function testReciboDeCajaWithPendingDianDoesNotBlockAdvance(): void
    {
        [$leg] = $this->legWithLinkedChildInValidacion();
        $rc = InvoiceFactory::new([
            'advance_id' => $leg->advance_invoice_id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $rc->id])->save();
        $operator = UserFactory::new()->save();
        $approver = UserFactory::new()->save();

        $service = $this->buildService();
        $this->assertTrue($service->moveToAprobacion($leg, (int)$operator->id)->success);
        $legInAprobacion = $this->fetchTable('AdvanceLegalizations')->get($leg->id);

        $approvalSvc = $this->buildApprovalService();
        $approvalSvc->assignApprovers($legInAprobacion, [$approver->id], 'https://x', (int)$operator->id);
        $approvalsTable = $this->fetchTable('AdvanceLegalizationApprovals');
        $row = $approvalsTable->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $approvalSvc->applyFreshToken($row);
        $approvalsTable->saveOrFail($row);
        $approvalSvc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        $this->assertTrue($approvalSvc->areAllApproved((int)$leg->id));

        $result = $service->moveToRevisionFirmas(
            $this->fetchTable('AdvanceLegalizations')->get($leg->id),
            (int)$operator->id,
        );

        $this->assertTrue($result->success, implode(' ', $result->errors));
        $this->assertSame(
            AdvanceConstants::STATUS_REVISION_FIRMAS,
            $this->fetchTable('AdvanceLegalizations')->get($leg->id)->status,
        );
        // El RC sigue con DIAN pendiente: avanzó por exención, no por validarse.
        $this->assertSame(
            InvoiceConstants::DIAN_PENDING,
            $this->fetchTable('Invoices')->get($rc->id)->dian_validation,
        );
    }
}
