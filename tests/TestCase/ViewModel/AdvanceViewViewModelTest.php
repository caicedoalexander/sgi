<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\AdvanceViewViewModel;
use Cake\ORM\Entity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para AdvanceViewViewModel — el anticipo ES una factura, el estado se
 * deriva sobre InvoicePresentation/InvoiceConstants. Puro. Se setea `provider`
 * para evitar acceso a asociación nula.
 */
final class AdvanceViewViewModelTest extends TestCase
{
    private function advance(array $data): Invoice
    {
        $inv = new Invoice($data + ['id' => 7, 'pipeline_status' => InvoiceConstants::STATUS_TESORERIA]);
        $inv->set('provider', new Entity(['name' => 'ACME']));

        return $inv;
    }

    public function testBadgeAndPageTitle(): void
    {
        $vm = new AdvanceViewViewModel($this->advance(['invoice_number' => 'ANT-1']));

        $this->assertSame('ANT-1', $vm->pageTitle);
        $this->assertSame('ANT-1', $vm->idLabel);
        $this->assertSame(
            [
                InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_TESORERIA],
                InvoicePresentation::STATUS_BADGES[InvoiceConstants::STATUS_TESORERIA],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testIdLabelFallsBackToId(): void
    {
        $vm = new AdvanceViewViewModel($this->advance(['invoice_number' => null]));

        $this->assertSame('#7', $vm->idLabel);
    }

    public function testIsTerminalWhenPaid(): void
    {
        $vm = new AdvanceViewViewModel($this->advance(['pipeline_status' => InvoiceConstants::STATUS_PAGADA]));

        $this->assertTrue($vm->isTerminal);
    }

    public function testBeneficiaryType(): void
    {
        $provider = new AdvanceViewViewModel($this->advance(['provider_id' => 5]));
        $employee = new AdvanceViewViewModel($this->advance(['employee_id' => 3]));
        $none = new AdvanceViewViewModel($this->advance([]));

        $this->assertSame('Proveedor', $provider->beneficiaryType);
        $this->assertSame('Empleado', $employee->beneficiaryType);
        $this->assertSame('—', $none->beneficiaryType);
    }

    public function testRegistryLinesFromTimestamps(): void
    {
        $vm = new AdvanceViewViewModel($this->advance([
            'created' => new DateTimeImmutable('2026-06-01 10:00'),
            'modified' => new DateTimeImmutable('2026-06-02 12:00'),
        ]));

        $this->assertCount(2, $vm->registryLines);
        $this->assertStringContainsString('Creado', $vm->registryLines[0]['html']);
    }
}
