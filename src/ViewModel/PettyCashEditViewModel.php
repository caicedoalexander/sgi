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
final readonly class PettyCashEditViewModel implements EditViewModelInterface
{
    // ── Propiedades derivadas (calculadas en el constructor) ────────────
    public string $pageTitle;
    /** @var array<string,string> */
    public array $statusLabels;
    /** @var array<string,string> */
    public array $statusBadgeMap;
    /** @var array{0:string,1:string} */
    public array $currentStatusBadge;
    /** @var array<string,string> */
    public array $readyForPaymentOptions;
    /** @var array<string,string> */
    public array $paymentStatusOptions;
    public int $statusIndex;
    public bool $showAccounting;
    public bool $showTreasury;
    public bool $canEditAccounting;
    public bool $canEditTreasury;
    public bool $canSave;
    /** HTML pre-renderizado (ícono + texto). El template lo imprime raw. */
    public string $submitButtonHtml;
    public string $submitButtonClass;
    public int $invoiceCount;

    public function __construct(
        // Entidad principal
        public PettyCashRecord $record,
        public string $currentStatus,
        public string $roleName,
        // Permisos del pipeline
        public bool $canDeleteDocuments,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public bool $canConfirmPayment,
        public bool $canRegress,
        public bool $canAdvance,
        // Avance / retroceso
        public array $advanceErrors,
        public ?string $nextStatus,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        // Pipeline visual
        public array $pipelineLabels,
        // Pagos sintéticos (CM almacena el pago como columnas en el record)
        public array $syntheticPayments,
        // Dropdowns / listados del formulario
        public mixed $availableInvoices,
        public mixed $operationCenters,
        public mixed $providers,
        public mixed $bankingEntities,
        public array $groupFilters,
    ) {
        $this->pageTitle    = 'Editar Caja Menor ' . ($record->code ?? ('#' . $record->id));
        $this->statusLabels = PettyCashConstants::STATUS_LABELS;

        $this->statusBadgeMap = PettyCashPresentation::STATUS_BADGES;
        $this->currentStatusBadge = [
            $this->statusLabels[$currentStatus]  ?? 'Desconocido',
            $this->statusBadgeMap[$currentStatus] ?? 'pill-muted',
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
