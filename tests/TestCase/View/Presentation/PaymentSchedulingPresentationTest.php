<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\View\Presentation\PaymentSchedulingPresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para PaymentSchedulingPresentation::forRow() — derivación de fila
 * del listado de programación de pagos. Blinda el mapeo estado→pill anti-drift. Puro.
 */
final class PaymentSchedulingPresentationTest extends TestCase
{
    public function testForRowMapsStatusBadgeAndLabel(): void
    {
        $row = PaymentSchedulingPresentation::forRow(
            new PaymentScheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_TESORERIA]),
        );

        $this->assertSame('pill-info-soft', $row->statusBadgeClass);
        $this->assertSame(
            PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_TESORERIA],
            $row->statusLabel,
        );
    }

    public function testForRowPaidFlag(): void
    {
        $row = PaymentSchedulingPresentation::forRow(
            new PaymentScheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA]),
        );

        $this->assertTrue($row->isPaid);
    }

    public function testForRowUnknownStatusFallsBack(): void
    {
        $row = PaymentSchedulingPresentation::forRow(
            new PaymentScheduling(['pipeline_status' => 'estado_inexistente']),
        );

        $this->assertSame('pill-muted', $row->statusBadgeClass);
        $this->assertSame(-1, $row->stageIdx);
    }

    public function testForRowCountsItems(): void
    {
        $record = new PaymentScheduling([
            'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR,
            'payment_scheduling_items' => [new stdClass(), new stdClass()],
        ]);

        $this->assertSame(2, PaymentSchedulingPresentation::forRow($record)->itemCount);
    }
}
