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
 * Verifica la interacción RC↔Legalización (§6.3 del spec) a través de la
 * aprobación de grupo de Anticipos: un Recibo de Caja vinculado queda
 * congelado exactamente en invoice-contabilidad tras moveToRevisionFirmas —
 * el mismo estado de "congelado" que hoy espera
 * ReciboCajaDocumentTypePolicy::blocksAdvance (advance_id != null +
 * pipeline_status=contabilidad) — y NO avanza solo por efecto de la
 * aprobación de grupo (F1–F3 de RC↔Legalización siguen coherentes).
 */
final class AdvanceReciboCajaFreezeTest extends TestCase
{
    private function buildService(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    public function testReciboCajaEndsFrozenInContabilidadAfterGroupApproval(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $rc = InvoiceFactory::new([
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // El gate de grupo exige ≥1 documento por hija (el RC no está exento de soporte).
        InvoiceDocumentFactory::new(['invoice_id' => $rc->id])->save();

        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signatures->saveOrFail($signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion-facturas.pdf',
            'file_name' => 'relacion-facturas.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        $operator = UserFactory::new()->save();
        $approver = UserFactory::new()->save();

        $service = $this->buildService();
        $moveResult = $service->moveToAprobacion($leg, (int)$operator->id);
        $this->assertTrue($moveResult->success, implode(' ', $moveResult->errors));

        $legInAprobacion = $this->fetchTable('AdvanceLegalizations')->get($leg->id);

        $approvalSvc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $approvalSvc->assignApprovers($legInAprobacion, [$approver->id], 'https://x', (int)$operator->id);
        $approvalsTable = $this->fetchTable('AdvanceLegalizationApprovals');
        $row = $approvalsTable->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $approvalSvc->applyFreshToken($row);
        $approvalsTable->saveOrFail($row);
        $approveResult = $approvalSvc->processResponse(
            $secret,
            ApprovalConstants::ACTION_APPROVE,
            null,
            '127.0.0.1',
            'phpunit',
        );
        $this->assertTrue($approveResult->success, implode(' ', $approveResult->errors));
        $this->assertTrue($approvalSvc->areAllApproved((int)$leg->id));

        $advanceResult = $service->moveToRevisionFirmas($legInAprobacion, (int)$operator->id);
        $this->assertTrue($advanceResult->success, implode(' ', $advanceResult->errors));

        $rcFinal = $this->fetchTable('Invoices')->get($rc->id);
        // Congelado exactamente en contabilidad con area_approval=Aprobada: el
        // estado que ReciboCajaDocumentTypePolicy::blocksAdvance reconoce como
        // "vinculado a una legalización, avanzará junto con ella".
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $rcFinal->pipeline_status);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $rcFinal->area_approval);
        $this->assertNotNull($rcFinal->advance_id);

        // La legalización sí avanzó a revision_firmas; el RC NO la siguió más
        // allá de contabilidad (no hay avance automático del RC por su cuenta).
        $legFinal = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_REVISION_FIRMAS, $legFinal->status);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $rcFinal->pipeline_status);
    }
}
