<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Test\Factory\AdvanceLegalizationApprovalFactory;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationConsolidationTest extends TestCase
{
    private function _coordinator(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            EventManager::instance(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    public function testConsolidateMovesChildrenToContabilidadAndAdvancesLeg(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        // Hija en invoice-aprobacion, DIAN aprobada y con soporte (el gate de
        // grupo exige ≥1 documento por factura hija).
        $child = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();

        // Quórum: un aprobador ya en Aprobada.
        $u1 = UserFactory::new()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u1->id])
            ->approved()->save();

        $result = $this->_coordinator()->moveToRevisionFirmas($leg, (int)$u1->id);
        $this->assertTrue($result->success, $result->firstError() ?? '');

        $reloadedLeg = $legTable->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_REVISION_FIRMAS, $reloadedLeg->status);

        $reloadedChild = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $reloadedChild->pipeline_status);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloadedChild->area_approval);
    }

    public function testConsolidateBlockedWithoutQuorum(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        // Sin aprobadores → sin quórum.
        $result = $this->_coordinator()->moveToRevisionFirmas($leg, 1);
        $this->assertFalse($result->success);

        $this->assertSame(AdvanceConstants::STATUS_APROBACION, $legTable->get($leg->id)->status);
    }

    public function testReturnToAprobacionMovesChildrenBackToInvoiceAprobacion(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_REVISION_FIRMAS;
        $legTable->saveOrFail($leg);

        // Estado post-consolidación: hija en invoice-contabilidad + area_approval=Aprobada.
        $child = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $anticipo->id,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ])->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        // Firma pendiente de la relación (la que el firmante rechaza al devolver).
        $signatures = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $signature = $signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion-facturas.pdf',
            'file_name' => 'relacion-facturas.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);
        $signatures->saveOrFail($signature);

        // Aprobador del grupo ya en Aprobada: debe conservarse tras la devolución.
        $u1 = UserFactory::new()->save();
        $approval = AdvanceLegalizationApprovalFactory::new(
            ['advance_legalization_id' => $leg->id, 'user_id' => $u1->id],
        )->approved()->save();

        $result = $this->_coordinator()->returnToAprobacion($leg, 'Re-firmar la relación.', (int)$u1->id);
        $this->assertTrue($result->success, $result->firstError() ?? '');

        $this->assertSame(AdvanceConstants::STATUS_APROBACION, $legTable->get($leg->id)->status);

        // La hija vuelve a invoice-aprobacion; area_approval se conserva Aprobada
        // (coherente con conservar los aprobadores del grupo).
        $reloadedChild = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::STATUS_APROBACION, $reloadedChild->pipeline_status);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloadedChild->area_approval);

        // La firma pendiente queda rechazada con el motivo.
        $reloadedSig = $signatures->get($signature->id);
        $this->assertSame(AdvanceConstants::SIGNATURE_REJECTED, $reloadedSig->signature_status);
        $this->assertSame('Re-firmar la relación.', $reloadedSig->rejection_reason);

        // El aprobador del grupo NO se invalida (sigue Aprobada) para re-consolidar.
        $reloadedApproval = TableRegistry::getTableLocator()
            ->get('AdvanceLegalizationApprovals')->get($approval->id);
        $this->assertSame(InvoiceConstants::APPROVER_STATUS_APPROVED, $reloadedApproval->status);
    }

    public function testReturnFromAprobacionGoesBackToValidacion(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        // Usuario real: _setStatus persiste updated_by (FK a users).
        $u1 = UserFactory::new()->save();
        $result = $this->_coordinator()->returnToValidacionFromAprobacion($leg, (int)$u1->id);
        $this->assertTrue($result->success, $result->firstError() ?? '');
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $legTable->get($leg->id)->status);
    }
}
