<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\View\Presentation\PaymentSchedulingPresentation;
use App\ViewModel\PaymentSchedulingEditViewModel;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para PaymentSchedulingEditViewModel — badge anti-drift, título,
 * conteo de items y flags borrador/pagada. Puro, sin BD.
 */
final class PaymentSchedulingEditViewModelTest extends TestCase
{
    private function buildVm(PaymentScheduling $record, ?string $currentStatus = null): PaymentSchedulingEditViewModel
    {
        return new PaymentSchedulingEditViewModel(
            record: $record,
            roleName: 'Tesorería',
            currentStatus: $currentStatus ?? $record->pipeline_status,
            canAdvance: false,
            canReject: false,
            canRegress: false,
            canConfirmPayment: false,
            nextStatus: null,
            previousStatus: null,
            regressLockMessage: null,
            advanceErrors: [],
            total: 0.0,
            pipelineLabels: PaymentSchedulingConstants::STATUS_LABELS,
            bankingEntities: [],
        );
    }

    public function testCurrentStatusBadgeFromPresentation(): void
    {
        $record = new PaymentScheduling(['id' => 1, 'code' => 'PG-1', 'pipeline_status' => PaymentSchedulingConstants::STATUS_TESORERIA]);

        $vm = $this->buildVm($record);

        $this->assertSame(
            [
                PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_TESORERIA],
                PaymentSchedulingPresentation::STATUS_BADGES[PaymentSchedulingConstants::STATUS_TESORERIA],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testPageTitleUsesCodeOrId(): void
    {
        $withCode = $this->buildVm(new PaymentScheduling(['id' => 9, 'code' => 'PG-9', 'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR]));
        $withoutCode = $this->buildVm(new PaymentScheduling(['id' => 9, 'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR]));

        $this->assertSame('Programación PG-9', $withCode->pageTitle);
        $this->assertSame('Programación #9', $withoutCode->pageTitle);
    }

    public function testBorradorAndPagadaFlags(): void
    {
        $borrador = $this->buildVm(new PaymentScheduling(['id' => 1, 'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR]));
        $pagada = $this->buildVm(new PaymentScheduling(['id' => 1, 'pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA]));

        $this->assertTrue($borrador->isBorrador);
        $this->assertFalse($borrador->isPagada);
        $this->assertTrue($pagada->isPagada);
        $this->assertFalse($pagada->isBorrador);
    }

    public function testItemCount(): void
    {
        $record = new PaymentScheduling([
            'id' => 1,
            'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR,
            'payment_scheduling_items' => [new stdClass(), new stdClass(), new stdClass()],
        ]);

        $this->assertSame(3, $this->buildVm($record)->itemCount);
    }
}
