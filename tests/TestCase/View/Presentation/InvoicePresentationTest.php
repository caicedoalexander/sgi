<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoicePresentation::forRow() — derivación de fila del listado.
 *
 * Blinda la regla anti-drift (CLAUDE.md): el mapeo estado→pill/icono y todas las
 * derivaciones de fila (stageIdx, pills, pipeline-mini, flags) viven SOLO aquí,
 * no inline en index.php. Es 100% puro: recibe un Invoice + un `$today` inyectable
 * que hace determinista el cálculo de vencimiento.
 */
final class InvoicePresentationTest extends TestCase
{
    private function invoice(array $data): Invoice
    {
        return new Invoice($data);
    }

    public function testForRowMapsStatusToLabelAndBadge(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
        ]));

        $this->assertSame(
            InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_APROBACION],
            $row->statusLabel,
        );
        $this->assertSame('pill-warning-soft', $row->statusBadgeClass);
        $this->assertSame('pill-warning-soft', $row->pillClass);
        $this->assertFalse($row->isRejected);
    }

    public function testForRowRejectedOverridesPillAndVariant(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ]));

        $this->assertTrue($row->isRejected);
        $this->assertSame('pill-danger-soft', $row->pillClass);
        $this->assertSame('is-danger', $row->pipelineVariant);
        // El badge del estado se conserva (independiente del pill rechazado).
        $this->assertSame('pill-orange-soft', $row->statusBadgeClass);
    }

    public function testForRowApprovedFlag(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]));

        $this->assertTrue($row->isApproved);
    }

    public function testForRowPartialPayFlag(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'payment_status' => InvoiceConstants::PAYMENT_PARTIAL,
        ]));

        $this->assertTrue($row->isPartialPay);
    }

    public function testForRowPaidFlagAndBadge(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
        ]));

        $this->assertTrue($row->isPaid);
        $this->assertSame('pill-primary-soft', $row->statusBadgeClass);
    }

    public function testForRowReadyForPayFlag(): void
    {
        $ready = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'ready_for_payment' => 'Sí',
        ]));
        $notReady = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'ready_for_payment' => 'No',
        ]));

        $this->assertTrue($ready->isReadyForPay);
        $this->assertFalse($notReady->isReadyForPay);
    }

    public function testForRowOverdueWhenDuePastAndNotPaidNorRejected(): void
    {
        $row = InvoicePresentation::forRow(
            $this->invoice([
                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                'due_date' => new DateTimeImmutable('2026-06-01'),
            ]),
            new DateTimeImmutable('2026-06-02'),
        );

        $this->assertTrue($row->isOverdue);
    }

    public function testForRowNotOverdueWhenPaid(): void
    {
        $row = InvoicePresentation::forRow(
            $this->invoice([
                'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
                'due_date' => new DateTimeImmutable('2026-06-01'),
            ]),
            new DateTimeImmutable('2026-06-02'),
        );

        $this->assertFalse($row->isOverdue);
    }

    public function testForRowLegalizationUsesShortPipeline(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
        ]));

        $this->assertTrue($row->isLegalization);
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION, $row->pipelineSteps);
        // Contabilidad es el índice 1 del pipeline reducido de legalización
        // (aprobacion=0, contabilidad=1, legalizada=2). Literal a propósito:
        // si el orden del pipeline cambia, este test debe fallar (anti-drift).
        $this->assertSame(1, $row->stageIdx);
    }

    public function testForRowLinkedReciboDeCajaUsesShortPipeline(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'advance_id' => 77,
        ]));

        $this->assertTrue($row->isLegalization);
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION, $row->pipelineSteps);
    }

    public function testForRowUnlinkedReciboDeCajaUsesFullPipeline(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
        ]));

        $this->assertFalse($row->isLegalization);
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
    }

    public function testForRowStandardPipelineStageIdx(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
        ]));

        $this->assertFalse($row->isLegalization);
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
        // Tesorería es el índice 2 del pipeline estándar de 6 estados
        // (aprobacion=0, contabilidad=1, tesoreria=2, ...). Literal a propósito:
        // si el orden del pipeline cambia, este test debe fallar (anti-drift).
        $this->assertSame(2, $row->stageIdx);
    }

    public function testForRowUnknownStatusFallsBack(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => 'estado_inexistente',
        ]));

        $this->assertSame('Desconocido', $row->statusLabel);
        $this->assertSame('pill-muted', $row->statusBadgeClass);
        $this->assertSame(-1, $row->stageIdx);
    }

    public function testForRowPipelineVariantMapping(): void
    {
        $orange = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
        ]));
        $warning = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
        ]));
        $primary = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
        ]));

        $this->assertSame('is-orange', $orange->pipelineVariant);
        $this->assertSame('is-warning', $warning->pipelineVariant);
        $this->assertSame('', $primary->pipelineVariant);
    }
}
