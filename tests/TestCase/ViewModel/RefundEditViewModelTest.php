<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\View\Presentation\RefundPresentation;
use App\ViewModel\RefundEditViewModel;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para RefundEditViewModel — derivaciones de la vista de edición.
 *
 * Foco anti-drift: currentStatusBadge sale de RefundPresentation::STATUS_BADGES;
 * los flags de visibilidad/edición se delegan a PipelineEditFlags. También cubre
 * título, botón de submit y conteo de facturas. Puro, sin BD.
 */
final class RefundEditViewModelTest extends TestCase
{
    private function buildVm(
        Refund $record,
        ?string $currentStatus = null,
        ?string $nextStatus = null,
        array $advanceErrors = [],
        bool $canAdvance = false,
    ): RefundEditViewModel {
        return new RefundEditViewModel(
            record: $record,
            currentStatus: $currentStatus ?? $record->status,
            employees: [],
            providers: [],
            operationCenters: [],
            bankingEntities: [],
            availableInvoices: [],
            groupFilters: [],
            nextStatus: $nextStatus,
            advanceErrors: $advanceErrors,
            canAdvance: $canAdvance,
            canRegress: false,
            previousStatus: null,
            regressLockMessage: null,
            canRegisterPayment: false,
            canAuthorizePayment: false,
            canConfirmPayment: false,
            syntheticPayments: [],
            roleName: 'Contabilidad',
            pipelineLabels: RefundConstants::STATUS_LABELS,
        );
    }

    public function testCurrentStatusBadgeFromPresentation(): void
    {
        $record = new Refund(['id' => 1, 'code' => 'RE-1', 'status' => RefundConstants::STATUS_CONTABILIDAD]);

        $vm = $this->buildVm($record);

        $this->assertSame(
            [
                RefundConstants::STATUS_LABELS[RefundConstants::STATUS_CONTABILIDAD],
                RefundPresentation::STATUS_BADGES[RefundConstants::STATUS_CONTABILIDAD],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testCurrentStatusBadgeFallback(): void
    {
        $record = new Refund(['id' => 1, 'status' => RefundConstants::STATUS_AGRUPACION]);

        $vm = $this->buildVm($record, currentStatus: 'estado_inexistente');

        $this->assertSame(['Desconocido', 'pill-muted'], $vm->currentStatusBadge);
    }

    public function testPageTitleUsesCodeOrFallsBackToId(): void
    {
        $withCode = $this->buildVm(new Refund(['id' => 9, 'code' => 'RE-9', 'status' => RefundConstants::STATUS_AGRUPACION]));
        $withoutCode = $this->buildVm(new Refund(['id' => 9, 'status' => RefundConstants::STATUS_AGRUPACION]));

        $this->assertSame('Editar Reintegro RE-9', $withCode->pageTitle);
        $this->assertSame('Editar Reintegro #9', $withoutCode->pageTitle);
    }

    public function testPipelineFlagsAtContabilidad(): void
    {
        $record = new Refund(['id' => 1, 'status' => RefundConstants::STATUS_CONTABILIDAD]);

        $vm = $this->buildVm($record);

        $this->assertSame(
            array_search(RefundConstants::STATUS_CONTABILIDAD, RefundConstants::STATUSES, true),
            $vm->statusIndex,
        );
        $this->assertTrue($vm->showAccounting);
        $this->assertFalse($vm->showTreasury);
        $this->assertTrue($vm->canEditAccounting);
        $this->assertTrue($vm->canSave);
    }

    public function testSubmitButtonAdvancesWhenAllowed(): void
    {
        $record = new Refund(['id' => 1, 'status' => RefundConstants::STATUS_CONTABILIDAD]);

        $vm = $this->buildVm($record, nextStatus: RefundConstants::STATUS_TESORERIA, canAdvance: true);

        $this->assertStringContainsString('Guardar y Avanzar a:', $vm->submitButtonHtml);
    }

    public function testInvoiceCount(): void
    {
        $record = new Refund([
            'id' => 1,
            'status' => RefundConstants::STATUS_AGRUPACION,
            'invoices' => [new stdClass(), new stdClass()],
        ]);

        $this->assertSame(2, $this->buildVm($record)->invoiceCount);
    }
}
