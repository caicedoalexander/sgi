<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\View\Presentation\PipelineColorMap;
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

        $this->assertSame('pill-orange-soft', $row->statusBadgeClass);
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

        // Tesorería es el índice 3 del pipeline de reintegros de 7 estados
        // (agrupacion=0, aprobacion=1, contabilidad=2, tesoreria=3, ...). Literales
        // a propósito: si el orden o el largo del pipeline cambian, este test debe
        // fallar (anti-drift).
        $this->assertSame(3, $row->stageIdx);
        $this->assertSame(7, $row->pipelineLength);
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

    public function testAprobacionBadgeMatchesColorMap(): void
    {
        $this->assertArrayHasKey(
            RefundConstants::STATUS_APROBACION,
            RefundPresentation::STATUS_BADGES,
        );
        $this->assertSame(
            PipelineColorMap::pill(RefundConstants::STATUS_APROBACION),
            RefundPresentation::STATUS_BADGES[RefundConstants::STATUS_APROBACION],
        );
    }
}
