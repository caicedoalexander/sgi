<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationLinkFilterTest extends TestCase
{
    private function _service(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            EventManager::instance(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    public function testLinksLegalizacionInvoiceInAprobacion(): void
    {
        // Anticipo (Invoice) pagado + su legalización en validacion.
        $anticipo = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new([
            'advance_invoice_id' => $anticipo->id,
        ])->save();
        // status es non-accessible: setear directo.
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        $legalizacion = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $result = $this->_service()->linkInvoices($leg, [(int)$legalizacion->id], (int)$anticipo->registered_by ?: 1);
        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['linked']);

        $reloaded = TableRegistry::getTableLocator()->get('Invoices')->get($legalizacion->id);
        $this->assertSame((int)$anticipo->id, (int)$reloaded->advance_id);
    }

    public function testDoesNotLinkLegalizacionInvoiceInContabilidad(): void
    {
        $anticipo = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new([
            'advance_invoice_id' => $anticipo->id,
        ])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        $legalizacion = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
        ])->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $result = $this->_service()->linkInvoices($leg, [(int)$legalizacion->id], (int)$anticipo->registered_by ?: 1);
        // No-op: la factura en contabilidad ya no es vinculable.
        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['linked']);
    }

    public function testDoesNotLinkReciboAlreadyOnPettyCash(): void
    {
        $anticipo = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new([
            'advance_invoice_id' => $anticipo->id,
        ])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        // RC en aprobacion pero YA vinculado a un registro de caja menor.
        $record = PettyCashRecordFactory::new()->save();
        $recibo = InvoiceFactory::new(['petty_cash_record_id' => $record->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $result = $this->_service()->linkInvoices($leg, [(int)$recibo->id], (int)$anticipo->registered_by ?: 1);
        // No-op: el compare-and-set no alcanza al RC ya en caja menor.
        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['linked']);

        $reloaded = TableRegistry::getTableLocator()->get('Invoices')->get($recibo->id);
        $this->assertNull($reloaded->advance_id);
    }
}
