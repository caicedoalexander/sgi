<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\View\Presentation\RefundPresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para RefundPresentation::forRow() — derivación de fila del listado
 * de reintegros. Blinda el mapeo estado→pill anti-drift. Puro, sin BD.
 */
final class RefundPresentationTest extends TestCase
{
    public function testForRowMapsStatusBadgeAndLabel(): void
    {
        $row = RefundPresentation::forRow(new Refund(['status' => RefundConstants::STATUS_CONTABILIDAD]));

        $this->assertSame('pill-primary-soft', $row->statusBadgeClass);
        $this->assertSame(
            RefundConstants::STATUS_LABELS[RefundConstants::STATUS_CONTABILIDAD],
            $row->statusLabel,
        );
    }

    public function testForRowPaidFlag(): void
    {
        $row = RefundPresentation::forRow(new Refund(['status' => RefundConstants::STATUS_PAGADA]));

        $this->assertTrue($row->isPaid);
        $this->assertSame('pill-primary-soft', $row->statusBadgeClass);
    }

    public function testForRowStageIdxAndPipelineLength(): void
    {
        $row = RefundPresentation::forRow(new Refund(['status' => RefundConstants::STATUS_TESORERIA]));

        $this->assertSame(
            array_search(RefundConstants::STATUS_TESORERIA, RefundConstants::STATUSES, true),
            $row->stageIdx,
        );
        $this->assertSame(count(RefundConstants::STATUSES), $row->pipelineLength);
    }

    public function testForRowUnknownStatusFallsBack(): void
    {
        $row = RefundPresentation::forRow(new Refund(['status' => 'estado_inexistente']));

        $this->assertSame('pill-muted', $row->statusBadgeClass);
        $this->assertSame(-1, $row->stageIdx);
        $this->assertSame('estado_inexistente', $row->statusLabel);
    }

    public function testForRowCountsInvoices(): void
    {
        $record = new Refund([
            'status' => RefundConstants::STATUS_AGRUPACION,
            'invoices' => [new stdClass(), new stdClass()],
        ]);

        $this->assertSame(2, RefundPresentation::forRow($record)->invoiceCount);
    }
}
