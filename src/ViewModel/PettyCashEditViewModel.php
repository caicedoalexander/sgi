<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\View\Presentation\PettyCashPresentation;
use App\ViewModel\Support\PaymentOptions;
use App\ViewModel\Support\SubmitButton;

/**
 * Datos inmutables de vista para PettyCashRecordsController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class PettyCashEditViewModel
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
    public readonly bool $canAdvance;
    public readonly string $submitButtonLabel;
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

        // Secciones visibles por estado.
        $idx = array_search($record->status, PettyCashConstants::STATUSES, true);
        $this->statusIndex    = $idx === false ? 0 : (int)$idx;
        $this->showAccounting = $this->statusIndex >= 1; // contabilidad+
        $this->showTreasury   = $this->statusIndex >= 2; // tesoreria+

        // Permisos de edición por estado.
        $this->canEditAccounting = $record->isContabilidad();
        $this->canEditTreasury   = $record->isTesoreria();
        $this->canSave           = $record->isAgrupacion() || $record->isContabilidad() || $record->isTesoreria();
        $this->invoiceCount      = count($record->invoices ?? []);

        // Botón de submit (mismo patrón que Invoices/edit y Refunds/edit).
        $this->canAdvance        = $nextStatus !== null;
        $this->submitButtonClass = 'btn btn-primary';
        if ($this->canAdvance && empty($advanceErrors) && $nextStatus !== null) {
            $this->submitButtonLabel = SubmitButton::forAdvance($this->statusLabels[$nextStatus] ?? $nextStatus);
        } else {
            $this->submitButtonLabel = SubmitButton::forSave();
        }
    }
}
