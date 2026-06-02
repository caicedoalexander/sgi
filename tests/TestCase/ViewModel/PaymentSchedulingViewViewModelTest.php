<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\View\Presentation\PaymentSchedulingPresentation;
use App\ViewModel\PaymentSchedulingViewViewModel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para PaymentSchedulingViewViewModel — derivación de la vista de
 * detalle de programación de pagos (badge anti-drift, terminalidad, conteos,
 * registro y documentos). Puro.
 */
final class PaymentSchedulingViewViewModelTest extends TestCase
{
    public function testStatusBadgeAndPageTitle(): void
    {
        $vm = new PaymentSchedulingViewViewModel(
            new PaymentScheduling(['id' => 1, 'code' => 'PG-1', 'pipeline_status' => PaymentSchedulingConstants::STATUS_TESORERIA]),
            0.0,
        );

        $this->assertSame('Programación PG-1', $vm->pageTitle);
        $this->assertSame('PG-1', $vm->code);
        $this->assertSame(
            [
                PaymentSchedulingConstants::STATUS_LABELS[PaymentSchedulingConstants::STATUS_TESORERIA],
                PaymentSchedulingPresentation::STATUS_BADGES[PaymentSchedulingConstants::STATUS_TESORERIA],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testIsTerminalWhenPaid(): void
    {
        $vm = new PaymentSchedulingViewViewModel(
            new PaymentScheduling(['id' => 1, 'code' => 'PG-1', 'pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA]),
            0.0,
        );

        $this->assertTrue($vm->isTerminal);
    }

    public function testItemCountAndTotalAndDocuments(): void
    {
        $vm = new PaymentSchedulingViewViewModel(
            new PaymentScheduling([
                'id' => 1,
                'code' => 'PG-1',
                'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR,
                'payment_scheduling_items' => [new stdClass(), new stdClass()],
                'payment_scheduling_documents' => [new stdClass()],
            ]),
            7500.0,
        );

        $this->assertSame(2, $vm->itemCount);
        $this->assertSame(7500.0, $vm->total);
        $this->assertSame(1, $vm->totalDocs);
        $this->assertCount(1, $vm->documentRows);
    }

    public function testRegistryLinesFromTimestamps(): void
    {
        $record = new PaymentScheduling([
            'id' => 1,
            'code' => 'PG-1',
            'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR,
            'created' => new DateTimeImmutable('2026-06-01 09:00'),
            'modified' => new DateTimeImmutable('2026-06-02 09:00'),
        ]);

        $vm = new PaymentSchedulingViewViewModel($record, 0.0);

        $this->assertCount(2, $vm->registryLines);
        $this->assertStringContainsString('Creado', $vm->registryLines[0]['html']);
    }
}
