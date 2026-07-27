<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\PettyCash\Guard;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\PettyCash\Guard\PettyCashGuard;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use Cake\TestSuite\TestCase;

final class PettyCashGuardTest extends TestCase
{
    public function testChildRequirementsReportsSupportMissingButNeverDian(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        $pending = InvoiceFactory::new([
            'petty_cash_record_id' => $record->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'F-PEND',
        ])->save();

        $report = (new PettyCashGuard())->childRequirements((int)$record->id);

        // includeDian: false — el DIAN pendiente de la hija nunca aparece.
        $this->assertSame([], $report->dianPending);
        $this->assertSame([$pending->id => 'F-PEND'], $report->supportMissing);
    }

    public function testChildRequirementsEmptyWhenChildHasDocumentDespiteDianPending(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        $child = InvoiceFactory::new([
            'petty_cash_record_id' => $record->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'F-READY',
        ])->save();
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();

        $report = (new PettyCashGuard())->childRequirements((int)$record->id);

        $this->assertFalse($report->isBlocked());
    }
}
