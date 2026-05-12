<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\View\Presentation\PettyCashPresentation;
use App\ViewModel\Support\PaymentOptions;
use App\ViewModel\Support\PipelineEditFlags;
use App\ViewModel\Support\SubmitButton;

/**
 * Datos inmutables de vista para PettyCashRecordsController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class PettyCashEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public readonly string $pageTitle;
    /** @var array<string,string> */
    public readonly array $statusLabels;
    /** @var array<string,string> */
    public readonly array $statusBadgeMap;
    /** @var array{0:string,1:string} */
    public readonly array $currentStatusBadge;
    /** @var array<string,string> */
    public readonly array $readyForPaymentOptions;
    /** @var array<string,string> */
    public readonly array $paymentStatusOptions;
    public readonly int $statusIndex;
    public readonly bool $showAccounting;
    public readonly bool $showTreasury;
    public readonly bool $canEditAccounting;
    public readonly bool $canEditTreasury;
    public readonly bool $canSave;
    /** HTML pre-renderizado (ícono + texto). El template lo imprime raw. */
    public readonly string $submitButtonHtml;
    public readonly string $submitButtonClass;
    public readonly int $invoiceCount;

    public function __construct(
        // Entidad principal
        public readonly PettyCashRecord $record,
        public readonly string $currentStatus,
        public readonly string $roleName,
        // Permisos del pipeline
        public readonly bool $canDeleteDocuments,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
        public readonly bool $canConfirmPayment,
        public readonly bool $canRegress,
        public readonly bool $canAdvance,
        // Avance / retroceso
        public readonly array $advanceErrors,
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,
        // Pipeline visual
        public readonly array $pipelineLabels,
        // Pagos sintéticos (CM almacena el pago como columnas en el record)
        public readonly array $syntheticPayments,
        // Dropdowns / listados del formulario
        public readonly mixed $availableInvoices,
        public readonly mixed $operationCenters,
        public readonly mixed $providers,
        public readonly mixed $bankingEntities,
        public readonly array $groupFilters,
    ) {
        $this->pageTitle    = 'Editar Caja Menor ' . ($record->code ?? ('#' . $record->id));
        $this->statusLabels = PettyCashConstants::STATUS_LABELS;

        $this->statusBadgeMap = PettyCashPresentation::STATUS_BADGES;
        $this->currentStatusBadge = [
            $this->statusLabels[$currentStatus]  ?? 'Desconocido',
            $this->statusBadgeMap[$currentStatus] ?? 'bg-dark',
        ];

        $this->readyForPaymentOptions = PaymentOptions::readyForPayment();
        $this->paymentStatusOptions   = PaymentOptions::paymentStatus();

        // Visibilidad y permisos de edición por estado (lógica compartida con Refunds/edit).
        $flags = PipelineEditFlags::fromRecord(
            currentStatus: $record->status,
            statuses: PettyCashConstants::STATUSES,
            isAgrupacion: $record->isAgrupacion(),
            isContabilidad: $record->isContabilidad(),
            isTesoreria: $record->isTesoreria(),
        );
        $this->statusIndex       = $flags->statusIndex;
        $this->showAccounting    = $flags->showAccounting;
        $this->showTreasury      = $flags->showTreasury;
        $this->canEditAccounting = $flags->canEditAccounting;
        $this->canEditTreasury   = $flags->canEditTreasury;
        $this->canSave           = $flags->canSave;
        $this->invoiceCount      = count($record->invoices ?? []);

        // Botón de submit (mismo patrón que Invoices/edit y Refunds/edit).
        $this->submitButtonClass = 'btn btn-primary';
        $this->submitButtonHtml = SubmitButton::decide(
            canAdvance: $canAdvance,
            advanceErrors: $advanceErrors,
            nextStatusLabel: $nextStatus !== null ? ($this->statusLabels[$nextStatus] ?? $nextStatus) : null,
        );
    }
}
