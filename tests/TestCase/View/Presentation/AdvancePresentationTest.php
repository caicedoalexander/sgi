<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\AdvancePresentation;
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

        // Tesorería: badge fijo 'pill-info-soft' e índice 2 del pipeline estándar
        // de 6 estados. Literales a propósito: si el mapeo estado→pill o el orden
        // del pipeline cambian, este test debe fallar (anti-drift, CLAUDE.md).
        $this->assertSame('pill-info-soft', $row->statusBadgeClass);
        $this->assertSame(2, $row->pipelineIdx);
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

    public function testStatusBadgesHasWarningPillForAprobacion(): void
    {
        // Anti-drift: 'aprobacion' debe ser 'pill-warning-soft' en todos los
        // módulos (PipelineColorMap), verificado también por
        // PipelineColorConsistencyTest.
        $this->assertSame(
            'pill-warning-soft',
            AdvancePresentation::STATUS_BADGES[AdvanceConstants::STATUS_APROBACION],
        );
    }
}
