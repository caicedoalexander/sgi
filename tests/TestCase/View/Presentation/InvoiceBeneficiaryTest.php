<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Employee;
use App\Model\Entity\Invoice;
use App\Model\Entity\Provider;
use App\View\Presentation\InvoiceBeneficiary;
use PHPUnit\Framework\TestCase;

final class InvoiceBeneficiaryTest extends TestCase
{
    private function invoice(array $fields): Invoice
    {
        // guard=false: los micro-tests setean campos/asociaciones sin lidiar con accessibility.
        return new Invoice($fields, ['guard' => false]);
    }

    public function testProviderNameForRegularInvoice(): void
    {
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'provider' => new Provider(['name' => 'ACME'], ['guard' => false]),
        ]);

        $this->assertSame('ACME', InvoiceBeneficiary::label($invoice));
    }

    public function testEmployeeFallbackForRegularInvoiceWithoutProvider(): void
    {
        // No-RC sin proveedor pero con empleado: conserva el fallback del element
        // genérico compartido (Refunds/PettyCash). full_name es virtual → first_name/last_name1.
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'employee' => new Employee(['first_name' => 'Ana', 'last_name1' => 'Pérez'], ['guard' => false]),
        ]);

        $this->assertSame('Ana Pérez', InvoiceBeneficiary::label($invoice));
    }

    public function testManualHolderForReciboDeCaja(): void
    {
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'equivalent_holder_type' => InvoiceConstants::HOLDER_TYPE_MANUAL,
            'manual_document_number' => 'CC-123',
        ]);

        $this->assertSame('CC-123', InvoiceBeneficiary::label($invoice));
    }

    public function testEmployeeHolderForReciboDeCaja(): void
    {
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'equivalent_holder_type' => InvoiceConstants::HOLDER_TYPE_EMPLOYEE,
            'employee' => new Employee(['first_name' => 'Ana', 'last_name1' => 'Pérez'], ['guard' => false]),
        ]);

        $this->assertSame('Ana Pérez', InvoiceBeneficiary::label($invoice));
    }

    public function testDashWhenNothingResolves(): void
    {
        $invoice = $this->invoice(['document_type' => InvoiceConstants::DOCTYPE_FACTURA]);
        $this->assertSame('—', InvoiceBeneficiary::label($invoice));
    }
}
