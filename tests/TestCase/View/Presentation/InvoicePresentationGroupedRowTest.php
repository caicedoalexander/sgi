<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Employee;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoicePresentation::forGroupedRow() — derivación de fila de
 * la tabla de facturas hijas dentro de la vista del padre (grouped invoices).
 *
 * 100% puro: no toca BD (Invoice se construye en memoria). Blinda la regla
 * anti-drift (CLAUDE.md): dianMode/statusPill/supportOk se derivan SOLO aquí,
 * no inline en el template consumidor (T12/T13/T14).
 */
final class InvoicePresentationGroupedRowTest extends TestCase
{
    public function testForGroupedRowSelectModeWhenEditableAndRequired(): void
    {
        $invoice = new Invoice([
            'id' => 7, 'invoice_number' => 'F-7', 'amount' => 100,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_documents' => [],
        ]);

        $row = InvoicePresentation::forGroupedRow($invoice, canResolveDian: true);

        $this->assertSame('select', $row->dianMode);
        $this->assertFalse($row->supportOk);
        $this->assertSame(0, $row->docsCount);
    }

    public function testForGroupedRowNaModeForExemptDoctype(): void
    {
        $invoice = new Invoice([
            'id' => 8, 'invoice_number' => 'RC-8', 'amount' => 100,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
        ]);

        $this->assertSame('na', InvoicePresentation::forGroupedRow($invoice, true)->dianMode);
    }

    public function testForGroupedRowPillModeOutsideAprobacionOrWithoutPermission(): void
    {
        $enContabilidad = new Invoice([
            'id' => 9, 'invoice_number' => 'F-9', 'amount' => 100,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_documents' => [],
        ]);

        $this->assertSame(
            'pill',
            InvoicePresentation::forGroupedRow($enContabilidad, canResolveDian: true)->dianMode,
        );

        $sinPermiso = new Invoice([
            'id' => 10, 'invoice_number' => 'F-10', 'amount' => 100,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_documents' => [],
        ]);

        $this->assertSame(
            'pill',
            InvoicePresentation::forGroupedRow($sinPermiso, canResolveDian: false)->dianMode,
        );
    }

    public function testForGroupedRowResolvesEmployeeBeneficiaryAndExposesDocumentType(): void
    {
        $invoice = new Invoice([
            'id' => 11, 'invoice_number' => 'CM-11', 'amount' => 300,
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'invoice_documents' => [],
        ]);
        // Sin proveedor y con empleado → el beneficiario es el empleado.
        $invoice->set('employee', new Employee([
            'first_name' => 'Ana', 'last_name1' => 'Gomez', 'last_name2' => 'Ruiz',
        ]));

        $row = InvoicePresentation::forGroupedRow($invoice, canResolveDian: false);

        $this->assertSame('Ana Gomez Ruiz', $row->beneficiaryName);
        $this->assertSame(InvoiceConstants::DOCTYPE_CAJA_MENOR, $row->documentType);
    }
}
