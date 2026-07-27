<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\GroupReadinessQuery;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use Cake\TestSuite\TestCase;

final class GroupReadinessQueryTest extends TestCase
{
    public function testReportFlagsDianAndSupport(): void
    {
        $refund = RefundFactory::new()->save();
        // Sin DIAN y sin soporte → aparece en ambos.
        $bad = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'F-BAD',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // DIAN ok y con soporte → limpia.
        $good = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'F-GOOD',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $good->id])->save();

        $report = GroupReadinessQuery::report(['refund_id' => $refund->id]);

        $this->assertSame([$bad->id => 'F-BAD'], $report->dianPending);
        $this->assertSame([$bad->id => 'F-BAD'], $report->supportMissing);
        $this->assertTrue($report->isBlocked());
        $this->assertCount(2, $report->toMessages());
    }

    public function testDianExemptDoctypeIsIgnoredForDianButNotSupport(): void
    {
        $refund = RefundFactory::new()->save();
        $rc = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'RC-1',
        ])->reciboDeCaja()->save();

        $report = GroupReadinessQuery::report(['refund_id' => $refund->id]);

        $this->assertSame([], $report->dianPending);
        $this->assertSame([$rc->id => 'RC-1'], $report->supportMissing);
    }

    public function testIncludeDianFalseSkipsDianEntirely(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->save();

        $report = GroupReadinessQuery::report(['refund_id' => $refund->id], includeDian: false);
        $this->assertSame([], $report->dianPending);
    }
}
