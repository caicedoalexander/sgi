<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\InvoiceViewViewModel;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoiceViewViewModel — derivación escalar de la vista de detalle.
 *
 * Cubre badge anti-drift, título, terminalidad, totales de pago, aprobadores,
 * pills del hero (variante "Aprobada", "Pago Parcial"), nombre del titular y
 * detección de legalización vinculada. Puro, sin BD.
 */
final class InvoiceViewViewModelTest extends TestCase
{
    private function vm(Invoice $invoice, bool $isRejected = false, bool $isApproved = false): InvoiceViewViewModel
    {
        return new InvoiceViewViewModel($invoice, $isRejected, $isApproved);
    }

    public function testStatusBadgeAndLabelFromPresentation(): void
    {
        $vm = $this->vm(new Invoice(['id' => 7, 'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]));

        $this->assertSame(InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_CONTABILIDAD], $vm->statusLabel);
        $this->assertSame(InvoicePresentation::STATUS_BADGES[InvoiceConstants::STATUS_CONTABILIDAD], $vm->statusPill);
        $this->assertSame([$vm->statusLabel, $vm->statusPill], $vm->currentStatusBadge);
    }

    public function testPageTitleUsesNumberOrId(): void
    {
        $withNumber = $this->vm(new Invoice(['id' => 7, 'invoice_number' => 'F-1', 'pipeline_status' => InvoiceConstants::STATUS_APROBACION]));
        $withoutNumber = $this->vm(new Invoice(['id' => 7, 'pipeline_status' => InvoiceConstants::STATUS_APROBACION]));

        $this->assertSame('Factura F-1', $withNumber->pageTitle);
        $this->assertSame('Factura #7', $withoutNumber->pageTitle);
    }

    public function testIsTerminal(): void
    {
        $this->assertTrue($this->vm(new Invoice(['id' => 1, 'pipeline_status' => InvoiceConstants::STATUS_PAGADA]))->isTerminal);
        $this->assertTrue($this->vm(new Invoice(['id' => 1, 'pipeline_status' => InvoiceConstants::STATUS_LEGALIZADA]))->isTerminal);
        $this->assertFalse($this->vm(new Invoice(['id' => 1, 'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]))->isTerminal);
    }

    public function testPaymentTotalsFromInvoicePayments(): void
    {
        $invoice = new Invoice(['id' => 1, 'pipeline_status' => InvoiceConstants::STATUS_TESORERIA]);
        $invoice->set('invoice_payments', [
            new Entity(['amount' => 100]),
            new Entity(['amount' => 50]),
        ]);

        $vm = $this->vm($invoice);

        $this->assertSame(2, $vm->pagosCount);
        $this->assertSame(150.0, $vm->pagosTotal);
    }

    public function testApprovedNamesListsOnlyApprovedWithUser(): void
    {
        $invoice = new Invoice(['id' => 1, 'pipeline_status' => InvoiceConstants::STATUS_APROBACION]);
        $invoice->set('invoice_approvals', [
            new Entity([
                'status' => InvoiceConstants::APPROVER_STATUS_APPROVED,
                'user_id' => 1,
                'user' => new Entity(['full_name' => 'Juan Pérez']),
            ]),
            new Entity(['status' => 'Pendiente', 'user_id' => 2]),
        ]);

        $vm = $this->vm($invoice);

        $this->assertSame(['Juan Pérez'], $vm->approvedNames);
    }

    public function testHeroStatusPillResolvesApprovedVariant(): void
    {
        $invoice = new Invoice(['id' => 1, 'pipeline_status' => InvoiceConstants::STATUS_APROBACION]);

        $vm = $this->vm($invoice, isRejected: false, isApproved: true);

        $this->assertSame('pill-primary-soft', $vm->heroStatusPill);
        $this->assertSame('Aprobada', $vm->heroStatusLabel);
    }

    public function testHeroExtraPillForPartialPaymentInTreasury(): void
    {
        $invoice = new Invoice([
            'id' => 1,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'payment_status' => InvoiceConstants::PAYMENT_PARTIAL,
        ]);

        $vm = $this->vm($invoice);

        $this->assertNotNull($vm->heroExtraPill);
        $this->assertStringContainsString('Pago Parcial', $vm->heroExtraPill);
    }

    public function testIsLinkedLegalization(): void
    {
        $invoice = new Invoice([
            'id' => 1,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => 5,
        ]);

        $this->assertTrue($this->vm($invoice)->isLinkedLegalization);
    }

    public function testIsLinkedLegalizationForLinkedReciboDeCaja(): void
    {
        $invoice = new Invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'advance_id' => 88,
        ]);
        $this->assertTrue($this->vm($invoice)->isLinkedLegalization);
    }

    public function testIsNotLinkedLegalizationForUnlinkedLegalizacion(): void
    {
        $invoice = new Invoice([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => null,
        ]);
        $this->assertFalse($this->vm($invoice)->isLinkedLegalization);
    }

    public function testProviderNameForManualReciboDeCaja(): void
    {
        $invoice = new Invoice([
            'id' => 1,
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'equivalent_holder_type' => 'manual',
            'manual_document_number' => 'CC-123',
        ]);

        $vm = $this->vm($invoice);

        $this->assertTrue($vm->isReciboDeCaja);
        $this->assertSame('CC-123', $vm->providerName);
    }
}
