<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testUsesLegalizationViewForLegalizacion(): void
    {
        $invoice = new Invoice(['document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION]);
        $this->assertTrue($invoice->usesLegalizationView());
    }

    public function testUsesLegalizationViewForLinkedReciboDeCaja(): void
    {
        $invoice = new Invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'advance_id' => 123,
        ]);
        $this->assertTrue($invoice->usesLegalizationView());
    }

    public function testDoesNotUseLegalizationViewForUnlinkedReciboDeCaja(): void
    {
        $invoice = new Invoice(['document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA]);
        $this->assertFalse($invoice->usesLegalizationView());
    }

    public function testDoesNotUseLegalizationViewForFactura(): void
    {
        $invoice = new Invoice(['document_type' => InvoiceConstants::DOCTYPE_FACTURA, 'advance_id' => 5]);
        $this->assertFalse($invoice->usesLegalizationView());
    }
}
