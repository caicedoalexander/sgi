<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\View\Presentation\PettyCashPresentation;
use App\ViewModel\PettyCashEditViewModel;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para PettyCashEditViewModel — derivaciones de la vista de edición
 * (gemelo de RefundEditViewModel). Anti-drift del badge + flags por PipelineEditFlags.
 * Puro, sin BD.
 */
final class PettyCashEditViewModelTest extends TestCase
{
    private function buildVm(
        PettyCashRecord $record,
        ?string $currentStatus = null,
        ?string $nextStatus = null,
        array $advanceErrors = [],
        bool $canAdvance = false,
    ): PettyCashEditViewModel {
        return new PettyCashEditViewModel(
            record: $record,
            currentStatus: $currentStatus ?? $record->status,
            roleName: 'Contabilidad',
            canDeleteDocuments: false,
            canRegisterPayment: false,
            canAuthorizePayment: false,
            canConfirmPayment: false,
            canRegress: false,
            canAdvance: $canAdvance,
            advanceErrors: $advanceErrors,
            nextStatus: $nextStatus,
            previousStatus: null,
            regressLockMessage: null,
            pipelineLabels: PettyCashConstants::STATUS_LABELS,
            syntheticPayments: [],
            availableInvoices: [],
            operationCenters: [],
            providers: [],
            bankingEntities: [],
            groupFilters: [],
        );
    }

    public function testCurrentStatusBadgeFromPresentation(): void
    {
        $record = new PettyCashRecord(['id' => 1, 'code' => 'CM-1', 'status' => PettyCashConstants::STATUS_CONTABILIDAD]);

        $vm = $this->buildVm($record);

        $this->assertSame(
            [
                PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_CONTABILIDAD],
                PettyCashPresentation::STATUS_BADGES[PettyCashConstants::STATUS_CONTABILIDAD],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testPageTitleUsesCodeOrFallsBackToId(): void
    {
        $withCode = $this->buildVm(new PettyCashRecord(['id' => 9, 'code' => 'CM-9', 'status' => PettyCashConstants::STATUS_AGRUPACION]));
        $withoutCode = $this->buildVm(new PettyCashRecord(['id' => 9, 'status' => PettyCashConstants::STATUS_AGRUPACION]));

        $this->assertSame('Editar Caja Menor CM-9', $withCode->pageTitle);
        $this->assertSame('Editar Caja Menor #9', $withoutCode->pageTitle);
    }

    public function testPipelineFlagsAtTesoreria(): void
    {
        $record = new PettyCashRecord(['id' => 1, 'status' => PettyCashConstants::STATUS_TESORERIA]);

        $vm = $this->buildVm($record);

        $this->assertSame(
            array_search(PettyCashConstants::STATUS_TESORERIA, PettyCashConstants::STATUSES, true),
            $vm->statusIndex,
        );
        $this->assertTrue($vm->showAccounting);
        $this->assertTrue($vm->showTreasury);
        $this->assertTrue($vm->canEditTreasury);
        $this->assertTrue($vm->canSave);
    }

    public function testSubmitButtonSavesWhenNoAdvance(): void
    {
        $record = new PettyCashRecord(['id' => 1, 'status' => PettyCashConstants::STATUS_CONTABILIDAD]);

        $vm = $this->buildVm($record, canAdvance: false);

        $this->assertStringContainsString('Guardar Cambios', $vm->submitButtonHtml);
    }

    public function testInvoiceCount(): void
    {
        $record = new PettyCashRecord([
            'id' => 1,
            'status' => PettyCashConstants::STATUS_AGRUPACION,
            'invoices' => [new stdClass()],
        ]);

        $this->assertSame(1, $this->buildVm($record)->invoiceCount);
    }
}
