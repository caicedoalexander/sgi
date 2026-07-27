<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\RefundConstants;
use App\Model\Entity\Invoice;
use App\Model\Entity\Refund;
use App\View\Presentation\RefundPresentation;
use App\ViewModel\RefundViewViewModel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para RefundViewViewModel — derivación de la vista de detalle de
 * reintegros (badge anti-drift, terminalidad, conteos, registro y documentos). Puro.
 *
 * Nota: se pasa beneficiary_type para evitar la deprecation `array offset null`
 * de RefundViewViewModel.php:52 (BENEFICIARY_TYPES_LABELS[$type] con type null).
 */
final class RefundViewViewModelTest extends TestCase
{
    private function refund(array $data): Refund
    {
        return new Refund($data + ['beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_PROVIDER]);
    }

    public function testStatusBadgeAndPageTitle(): void
    {
        $vm = new RefundViewViewModel($this->refund([
            'id' => 1,
            'code' => 'RE-1',
            'status' => RefundConstants::STATUS_CONTABILIDAD,
        ]));

        $this->assertSame('Reintegro RE-1', $vm->pageTitle);
        $this->assertSame(
            [
                RefundConstants::STATUS_LABELS[RefundConstants::STATUS_CONTABILIDAD],
                RefundPresentation::STATUS_BADGES[RefundConstants::STATUS_CONTABILIDAD],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testIsTerminalWhenPaid(): void
    {
        $paid = new RefundViewViewModel($this->refund(['id' => 1, 'code' => 'RE-1', 'status' => RefundConstants::STATUS_PAGADA]));
        $open = new RefundViewViewModel($this->refund(['id' => 1, 'code' => 'RE-1', 'status' => RefundConstants::STATUS_CONTABILIDAD]));

        $this->assertTrue($paid->isTerminal);
        $this->assertFalse($open->isTerminal);
    }

    public function testCountsTotalAndBeneficiaryLabel(): void
    {
        // El VM ahora deriva groupedRows vía InvoicePresentation::forGroupedRow(Invoice),
        // así que las hijas deben ser entidades Invoice reales (no stdClass).
        $vm = new RefundViewViewModel($this->refund([
            'id' => 1,
            'code' => 'RE-1',
            'status' => RefundConstants::STATUS_AGRUPACION,
            'total_amount' => 5000,
            'invoices' => [new Invoice(['id' => 1]), new Invoice(['id' => 2])],
            'refund_documents' => [new stdClass()],
        ]));

        $this->assertSame(2, $vm->invoiceCount);
        $this->assertCount(2, $vm->groupedRows);
        $this->assertSame(5000.0, $vm->totalAmount);
        $this->assertSame(1, $vm->totalDocs);
        $this->assertCount(1, $vm->documentRows);
        $this->assertFalse($vm->documentRows[0]['canDelete']);
        $this->assertSame('Proveedor', $vm->beneficiaryLabel);
    }

    public function testRegistryLinesFromTimestamps(): void
    {
        $record = $this->refund([
            'id' => 1,
            'code' => 'RE-1',
            'status' => RefundConstants::STATUS_AGRUPACION,
            'created' => new DateTimeImmutable('2026-06-01 10:00'),
            'modified' => new DateTimeImmutable('2026-06-02 12:00'),
        ]);

        $vm = new RefundViewViewModel($record);

        $this->assertCount(2, $vm->registryLines);
        $this->assertStringContainsString('Creado', $vm->registryLines[0]['html']);
        $this->assertStringContainsString('Modificado', $vm->registryLines[1]['html']);
    }
}
