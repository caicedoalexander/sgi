<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\ApprovalInboxConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\Invoice;
use App\Model\Entity\Provider;
use App\ViewModel\ApprovalViewViewModel;
use App\ViewModel\ViewViewModelInterface;
use PHPUnit\Framework\TestCase;

final class ApprovalViewViewModelTest extends TestCase
{
    public function testBuildsFromInvoiceEntity(): void
    {
        $invoice = new Invoice([
            'id' => 5,
            'invoice_number' => 'FAC-5',
            'amount' => 200000,
            'area_approval' => InvoiceConstants::APPROVAL_PENDING,
            'provider' => new Provider(['name' => 'ACME']),
        ]);

        $vm = new ApprovalViewViewModel(
            module: ApprovalInboxConstants::MODULE_INVOICE,
            entity: $invoice,
            status: ApprovalInboxConstants::STATUS_PENDING,
            observations: 'pendiente de revisión',
        );

        $this->assertInstanceOf(ViewViewModelInterface::class, $vm);
        $this->assertSame('Factura FAC-5', $vm->pageTitle);
        $this->assertSame('pending', $vm->currentStatus);
        $this->assertSame('Pendiente', $vm->currentStatusBadge[0]);
        $this->assertSame('pill-warning-soft', $vm->currentStatusBadge[1]);
        $this->assertSame('invoice', $vm->module);
        $this->assertSame('pendiente de revisión', $vm->observations);
        $this->assertSame($invoice, $vm->entity);
    }

    public function testBuildsFromNoveltyEntity(): void
    {
        $novelty = new EmployeeNovelty(['id' => 8]);

        $vm = new ApprovalViewViewModel(
            module: ApprovalInboxConstants::MODULE_NOVELTY,
            entity: $novelty,
            status: ApprovalInboxConstants::STATUS_APPROVED,
            observations: null,
        );

        $this->assertSame('Novedad #8', $vm->pageTitle);
        $this->assertSame('approved', $vm->currentStatus);
        $this->assertSame('Aprobada', $vm->currentStatusBadge[0]);
        $this->assertSame('novelty', $vm->module);
    }
}
