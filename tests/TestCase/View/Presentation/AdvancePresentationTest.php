<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\InvoicePresentation;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para AdvancePresentation::forRow() — derivación de fila de Anticipos.
 *
 * El anticipo es una factura: el pipeline de pago se deriva sobre
 * InvoiceConstants/InvoicePresentation; la legalización (opcional) sobre
 * AdvanceConstants. Puro. Se setea `provider` para evitar acceso a asociación nula.
 */
final class AdvancePresentationTest extends TestCase
{
    private function advance(array $data = []): Invoice
    {
        $inv = new Invoice($data + [
            'id' => 7,
            'invoice_number' => 'ANT-1',
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'amount' => 1000.0,
        ]);
        $inv->set('provider', new Entity(['name' => 'ACME']));

        return $inv;
    }

    public function testForRowDerivesPaymentPipelineFromInvoicePresentation(): void
    {
        $row = AdvancePresentation::forRow($this->advance());

        $this->assertSame(
            InvoicePresentation::STATUS_BADGES[InvoiceConstants::STATUS_TESORERIA],
            $row->statusBadgeClass,
        );
        $this->assertSame(
            array_search(InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::PIPELINE_STATUSES, true),
            $row->pipelineIdx,
        );
        $this->assertSame('ANT-1', $row->idLabel);
        $this->assertSame('ACME', $row->beneficiaryName);
        $this->assertFalse($row->isPaid);
    }

    public function testForRowWithoutLegalization(): void
    {
        $row = AdvancePresentation::forRow($this->advance());

        $this->assertFalse($row->hasLegalization);
        $this->assertSame(-1, $row->legalizationIdx);
        $this->assertSame('', $row->legalizationLabel);
        $this->assertSame('pill-muted', $row->legalizationBadgeClass);
    }

    public function testForRowIdLabelFallsBackToId(): void
    {
        $row = AdvancePresentation::forRow($this->advance(['invoice_number' => null]));

        $this->assertSame('#7', $row->idLabel);
    }

    public function testForRowPaidFlag(): void
    {
        $row = AdvancePresentation::forRow($this->advance(['pipeline_status' => InvoiceConstants::STATUS_PAGADA]));

        $this->assertTrue($row->isPaid);
    }
}
