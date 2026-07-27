<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\ApprovalInboxConstants;
use App\Service\Dto\ApprovalInboxItem;
use App\View\Presentation\ApprovalInboxPresentation;
use App\View\Presentation\ApprovalInboxRowView;
use Cake\I18n\DateTime;
use PHPUnit\Framework\TestCase;

final class ApprovalInboxPresentationTest extends TestCase
{
    private function item(string $module, string $status): ApprovalInboxItem
    {
        return new ApprovalInboxItem(
            module: $module,
            entityId: 1,
            code: 'X',
            counterparty: 'Y',
            summary: 'Z',
            status: $status,
            assignedAt: new DateTime('2026-06-01 10:00:00'),
            respondedAt: null,
            observations: null,
        );
    }

    public function testForRowDerivesStatusPillAndLabel(): void
    {
        $row = ApprovalInboxPresentation::forRow(
            $this->item(ApprovalInboxConstants::MODULE_INVOICE, ApprovalInboxConstants::STATUS_PENDING),
        );

        $this->assertInstanceOf(ApprovalInboxRowView::class, $row);
        $this->assertSame('Pendiente', $row->statusLabel);
        $this->assertSame('pill-warning-soft', $row->statusBadgeClass);
        $this->assertSame('Factura', $row->moduleLabel);
        $this->assertSame('pill-info-soft', $row->moduleBadgeClass);
        $this->assertSame('01/06/2026', $row->assignedAtLabel);
    }

    public function testForRowDerivesNoveltyApprovedBadge(): void
    {
        $row = ApprovalInboxPresentation::forRow(
            $this->item(ApprovalInboxConstants::MODULE_NOVELTY, ApprovalInboxConstants::STATUS_APPROVED),
        );

        $this->assertSame('Aprobada', $row->statusLabel);
        $this->assertSame('pill-primary-soft', $row->statusBadgeClass);
        $this->assertSame('Novedad', $row->moduleLabel);
    }

    public function testForRowDerivesRejectedBadge(): void
    {
        $row = ApprovalInboxPresentation::forRow(
            $this->item(ApprovalInboxConstants::MODULE_INVOICE, ApprovalInboxConstants::STATUS_REJECTED),
        );

        $this->assertSame('Rechazada', $row->statusLabel);
        $this->assertSame('pill-danger-soft', $row->statusBadgeClass);
    }
}
