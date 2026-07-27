<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Test\Factory\AdvanceLegalizationApprovalFactory;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationApprovalGuardTest extends TestCase
{
    public function testAllApprovedTrueWhenEveryActiveIsApproved(): void
    {
        $leg = AdvanceLegalizationFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u1->id])->approved()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u2->id])->approved()->save();

        $this->assertTrue((new AdvanceLegalizationApprovalGuard())->allApproved((int)$leg->id));
    }

    public function testAllApprovedFalseWithPending(): void
    {
        $leg = AdvanceLegalizationFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u1->id])->approved()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u2->id])->save();

        $this->assertFalse((new AdvanceLegalizationApprovalGuard())->allApproved((int)$leg->id));
    }

    public function testAllApprovedFalseWithNoApprovers(): void
    {
        $leg = AdvanceLegalizationFactory::new()->save();
        $this->assertFalse((new AdvanceLegalizationApprovalGuard())->allApproved((int)$leg->id));
    }

    public function testChildRequirementsReportsDianAndSupport(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        $ok = InvoiceFactory::new([
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'L-OK',
        ])->legalizacion()->save();
        InvoiceDocumentFactory::new(['invoice_id' => $ok->id])->save();
        $pending = InvoiceFactory::new([
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'L-PEND',
        ])->legalizacion()->save();

        $report = (new AdvanceLegalizationApprovalGuard())->childRequirements((int)$anticipo->id);

        $this->assertSame([$pending->id => 'L-PEND'], $report->dianPending);
        $this->assertSame([$pending->id => 'L-PEND'], $report->supportMissing);
    }

    /** El Recibo de Caja está exento de DIAN (policy), pero no de soporte. */
    public function testChildRequirementsExemptsReciboDeCajaFromDian(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        $rc = InvoiceFactory::new([
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'RC-1',
        ])->reciboDeCaja()->save();

        $report = (new AdvanceLegalizationApprovalGuard())->childRequirements((int)$anticipo->id);

        $this->assertSame([], $report->dianPending);
        $this->assertSame([$rc->id => 'RC-1'], $report->supportMissing);
    }
}
