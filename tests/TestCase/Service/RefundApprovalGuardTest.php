<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\RefundApprovalGuard;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundApprovalFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

class RefundApprovalGuardTest extends TestCase
{
    public function testAllApprovedTrueWhenEveryActiveIsApproved(): void
    {
        $refund = RefundFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u1->id])->approved()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u2->id])->approved()->save();

        $this->assertTrue((new RefundApprovalGuard())->allApproved((int)$refund->id));
    }

    public function testAllApprovedFalseWithPending(): void
    {
        $refund = RefundFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u1->id])->approved()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u2->id])->save(); // pendiente

        $this->assertFalse((new RefundApprovalGuard())->allApproved((int)$refund->id));
    }

    public function testAllApprovedFalseWithNoApprovers(): void
    {
        $refund = RefundFactory::new()->save();
        $this->assertFalse((new RefundApprovalGuard())->allApproved((int)$refund->id));
    }

    public function testChildRequirementsReportsDianAndSupport(): void
    {
        $refund = RefundFactory::new()->save();
        $ok = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'F-OK',
        ])->save();
        InvoiceDocumentFactory::new(['invoice_id' => $ok->id])->save();
        $pending = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'F-PEND',
        ])->save();

        $report = (new RefundApprovalGuard())->childRequirements((int)$refund->id);

        $this->assertSame([$pending->id => 'F-PEND'], $report->dianPending);
        $this->assertSame([$pending->id => 'F-PEND'], $report->supportMissing);
    }

    public function testChildRequirementsEmptyWhenAllChildrenReady(): void
    {
        $refund = RefundFactory::new()->save();
        $child = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'F-READY',
        ])->save();
        InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save();

        $report = (new RefundApprovalGuard())->childRequirements((int)$refund->id);

        $this->assertFalse($report->isBlocked());
    }
}
