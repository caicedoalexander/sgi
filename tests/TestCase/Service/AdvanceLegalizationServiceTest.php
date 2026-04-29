<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationServiceTest extends TestCase
{
    private AdvanceLegalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdvanceLegalizationService();
    }

    public function testInitializeRequiresAnticipoDocumentType(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'unit',
            'amount' => 1,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);

        $result = $this->service->initialize($invoice, 1);
        $this->assertFalse($result->success);
    }

    public function testHappyPathExacto(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        // 1. Anticipo @ pagada
        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'Anticipo unit test',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);
        $this->assertTrue((bool)$invoices->save($advance), json_encode($advance->getErrors()));

        $init = $this->service->initialize($advance, 1);
        $this->assertTrue($init->success, $init->firstError() ?? '');

        $leg = $legTable->find()->where(['advance_invoice_id' => $advance->id])->firstOrFail();
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $leg->status);

        // 2. Linked Legalización invoice with same total
        $legInv = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'detail' => 'Legalization invoice',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);
        $this->assertTrue((bool)$invoices->save($legInv), json_encode($legInv->getErrors()));

        $linked = $this->service->linkInvoices($leg, [$legInv->id], 1);
        $this->assertTrue($linked->success, $linked->firstError() ?? '');
        $this->assertEqualsWithDelta(0.0, $this->service->getDifference($leg), 0.01);

        // 3. Skip the relation-doc check by injecting a signature row directly
        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $sigTable->save($sigTable->newEntity([
            'legalization_id' => $leg->id,
            'document_path' => 'uploads/test.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        $r1 = $this->service->moveToRevisionFirmas($leg, 1);
        $this->assertTrue($r1->success, $r1->firstError() ?? '');

        $r2 = $this->service->markSigned($leg, 1);
        $this->assertTrue($r2->success, $r2->firstError() ?? '');

        $r3 = $this->service->markExact($leg, 1);
        $this->assertTrue($r3->success, $r3->firstError() ?? '');

        $reloaded = $legTable->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $reloaded->status);
        $this->assertSame(AdvanceConstants::CASE_EXACTO, $reloaded->case_type);

        // Cleanup
        $legTable->deleteAll(['id' => $leg->id]);
        $invoices->deleteAll(['id IN' => [$advance->id, $legInv->id]]);
    }
}
