<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\ViewModel\AdvanceLegalizationViewModel;
use PHPUnit\Framework\TestCase;

class AdvanceLegalizationViewModelApprovalTest extends TestCase
{
    public function testIsAprobacionAndBadgeInAprobacion(): void
    {
        $invoice = new Invoice(['id' => 1, 'invoice_number' => 'ANT-1', 'amount' => 100.0]);
        $leg = new AdvanceLegalization(['id' => 9, 'advance_invoice_id' => 1]);
        $leg->status = AdvanceConstants::STATUS_APROBACION;

        $vm = new AdvanceLegalizationViewModel(
            invoice: $invoice,
            leg: $leg,
            roleName: 'Contabilidad',
            linkedInvoices: [],
            bankingEntities: [],
            surplusPayment: null,
        );

        $this->assertTrue($vm->isAprobacion);
        $this->assertSame('Aprobación', $vm->currentStatusBadge[0]);
        $this->assertSame('pill-warning-soft', $vm->currentStatusBadge[1]);
    }
}
