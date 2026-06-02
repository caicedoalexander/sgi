<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\View\Presentation\InvoicePresentation;
use App\ViewModel\Invoice\InvoiceApprovalState;
use App\ViewModel\Invoice\InvoiceEditPermissions;
use App\ViewModel\Invoice\InvoiceFormDropdowns;
use App\ViewModel\InvoiceEditViewModel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoiceEditViewModel — derivaciones de la vista de edición.
 *
 * Foco anti-drift (CLAUDE.md): currentStatusBadge se deriva de
 * InvoicePresentation::STATUS_BADGES (no recomputado inline), y el orden de
 * render separa secciones editables de read-only. También cubre el título de
 * página (Factura vs Anticipo), el botón de submit (avanzar vs guardar) y los
 * predicados de campo/sección.
 *
 * Suite pura (sin DB): se construye el VM con bundles DTO triviales.
 */
final class InvoiceEditViewModelTest extends TestCase
{
    private function buildVm(
        Invoice $invoice,
        string $currentStatus = InvoiceConstants::STATUS_CONTABILIDAD,
        array $editableFields = [],
        array $visibleSections = [],
        array $advanceErrors = [],
        ?string $nextStatus = null,
        bool $canAdvance = false,
        bool $isRejected = false,
    ): InvoiceEditViewModel {
        return new InvoiceEditViewModel(
            invoice: $invoice,
            currentStatus: $currentStatus,
            roleName: 'Contabilidad',
            editableFields: $editableFields,
            visibleSections: $visibleSections,
            advanceErrors: $advanceErrors,
            nextStatus: $nextStatus,
            previousStatus: null,
            regressLockMessage: null,
            pipelineStatuses: InvoiceConstants::PIPELINE_STATUSES,
            pipelineLabels: InvoiceConstants::STATUS_LABELS,
            paymentsTotal: 0.0,
            emailLogs: [],
            permissions: new InvoiceEditPermissions(
                canAdvance: $canAdvance,
                canDeleteDocuments: false,
                canRegress: false,
                canConfirmPayment: false,
                canRegisterPayment: false,
                canAuthorizePayment: false,
                isRejected: $isRejected,
                isApproved: false,
            ),
            approvalState: new InvoiceApprovalState([], false, false, false),
            dropdowns: new InvoiceFormDropdowns([], [], [], [], [], [], []),
        );
    }

    private function invoice(array $data = []): Invoice
    {
        return new Invoice($data + [
            'id' => 5,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
        ]);
    }

    public function testCurrentStatusBadgeDerivedFromPresentation(): void
    {
        $vm = $this->buildVm($this->invoice(), currentStatus: InvoiceConstants::STATUS_CONTABILIDAD);

        $this->assertSame(
            [
                InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_CONTABILIDAD],
                InvoicePresentation::STATUS_BADGES[InvoiceConstants::STATUS_CONTABILIDAD],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testCurrentStatusBadgeFallbackForUnknownStatus(): void
    {
        $vm = $this->buildVm($this->invoice(), currentStatus: 'estado_inexistente');

        $this->assertSame(['Desconocido', 'pill-muted'], $vm->currentStatusBadge);
    }

    public function testPageTitleForFactura(): void
    {
        $vm = $this->buildVm($this->invoice(['invoice_number' => 'F-100']));

        $this->assertFalse($vm->isAdvance);
        $this->assertSame('Editar Factura F-100', $vm->pageTitle);
    }

    public function testPageTitleForAnticipo(): void
    {
        $vm = $this->buildVm($this->invoice(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO]));

        $this->assertTrue($vm->isAdvance);
        $this->assertSame('Editar Anticipo #5', $vm->pageTitle);
    }

    public function testRenderOrderPutsEditableBeforeReadOnly(): void
    {
        $vm = $this->buildVm(
            $this->invoice(),
            editableFields: ['invoice_number'], // habilita 'general', no 'revision'
            visibleSections: ['revision', 'general'],
        );

        $this->assertSame(['general'], $vm->editableSectionKeys);
        $this->assertSame(['revision'], $vm->readOnlySectionKeys);
        $this->assertSame(['general', 'revision'], $vm->renderOrder);
    }

    public function testFunctionalSectionsAreNeverReadOnly(): void
    {
        $vm = $this->buildVm(
            $this->invoice(),
            editableFields: [],
            visibleSections: ['treasury'],
        );

        // treasury es funcional: editable aunque no tenga campos en el sectionFieldMap.
        $this->assertSame(['treasury'], $vm->editableSectionKeys);
        $this->assertSame([], $vm->readOnlySectionKeys);
    }

    public function testSubmitButtonAdvancesWhenAllowed(): void
    {
        $vm = $this->buildVm(
            $this->invoice(),
            currentStatus: InvoiceConstants::STATUS_CONTABILIDAD,
            nextStatus: InvoiceConstants::STATUS_TESORERIA,
            canAdvance: true,
            isRejected: false,
        );

        $this->assertStringContainsString('Guardar y Avanzar a:', $vm->submitButtonHtml);
    }

    public function testSubmitButtonSavesWhenRejected(): void
    {
        $vm = $this->buildVm(
            $this->invoice(),
            nextStatus: InvoiceConstants::STATUS_TESORERIA,
            canAdvance: true,
            isRejected: true,
        );

        $this->assertStringContainsString('Guardar Cambios', $vm->submitButtonHtml);
    }

    public function testCanEditFieldAndIsReadOnlySection(): void
    {
        $vm = $this->buildVm(
            $this->invoice(),
            editableFields: ['amount'],
            visibleSections: ['revision'], // sin campos editables → read-only
        );

        $this->assertTrue($vm->canEditField('amount'));
        $this->assertFalse($vm->canEditField('detail'));
        $this->assertTrue($vm->isReadOnlySection('revision'));
        $this->assertFalse($vm->isReadOnlySection('general'));
    }
}
