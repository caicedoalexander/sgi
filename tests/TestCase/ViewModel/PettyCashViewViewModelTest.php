<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\View\Presentation\PettyCashPresentation;
use App\ViewModel\PettyCashViewViewModel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para PettyCashViewViewModel — derivación de la vista de detalle de
 * caja menor (badge anti-drift, terminalidad, payment card, conteos). Puro.
 */
final class PettyCashViewViewModelTest extends TestCase
{
    public function testStatusBadgeAndPageTitle(): void
    {
        $vm = new PettyCashViewViewModel(new PettyCashRecord([
            'id' => 1,
            'code' => 'CM-1',
            'status' => PettyCashConstants::STATUS_CONTABILIDAD,
        ]));

        $this->assertSame('Caja Menor CM-1', $vm->pageTitle);
        $this->assertSame(
            [
                PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_CONTABILIDAD],
                PettyCashPresentation::STATUS_BADGES[PettyCashConstants::STATUS_CONTABILIDAD],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testShowPaymentCardFromPaymentStates(): void
    {
        $auth = new PettyCashViewViewModel(new PettyCashRecord(['id' => 1, 'code' => 'CM-1', 'status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO]));
        $accounting = new PettyCashViewViewModel(new PettyCashRecord(['id' => 1, 'code' => 'CM-1', 'status' => PettyCashConstants::STATUS_CONTABILIDAD]));

        $this->assertTrue($auth->showPaymentCard);
        $this->assertFalse($accounting->showPaymentCard);
    }

    public function testAmountExtraHtmlWhenPaidWithDate(): void
    {
        $vm = new PettyCashViewViewModel(new PettyCashRecord([
            'id' => 1,
            'code' => 'CM-1',
            'status' => PettyCashConstants::STATUS_PAGADA,
            'payment_date' => new DateTimeImmutable('2026-06-01'),
        ]));

        $this->assertTrue($vm->isTerminal);
        $this->assertStringContainsString('Pagado', $vm->amountExtraHtml);
    }

    public function testCounts(): void
    {
        $vm = new PettyCashViewViewModel(new PettyCashRecord([
            'id' => 1,
            'code' => 'CM-1',
            'status' => PettyCashConstants::STATUS_AGRUPACION,
            'total_amount' => 1200,
            'invoices' => [new stdClass(), new stdClass(), new stdClass()],
            'petty_cash_documents' => [new stdClass()],
            'petty_cash_observations' => [new stdClass(), new stdClass()],
        ]));

        $this->assertSame(3, $vm->invoiceCount);
        $this->assertSame(1, $vm->totalDocs);
        $this->assertSame(2, $vm->obsCount);
        $this->assertSame(1200.0, $vm->totalAmount);
    }
}
