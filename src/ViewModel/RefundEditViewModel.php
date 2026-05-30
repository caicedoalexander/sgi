<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\View\Presentation\RefundPresentation;
use App\ViewModel\Support\PaymentOptions;
use App\ViewModel\Support\PipelineEditFlags;
use App\ViewModel\Support\SubmitButton;

/**
 * Datos inmutables de vista para RefundsController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final readonly class RefundEditViewModel implements EditViewModelInterface
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

    /**
     * @param \App\Model\Entity\Refund $record Refund cargado con todos sus contains.
     * @param string $currentStatus Estado actual del refund.
     * @param array<int, string> $employees Lista [id => nombre completo].
     * @param array<int, string> $providers Lista [id => name].
     * @param iterable $operationCenters Centros de operación (find('codeList')).
     * @param array<int, string> $bankingEntities Lista [id => name].
     * @param iterable $availableInvoices Facturas elegibles para agrupar.
     * @param array $groupFilters Filtros aplicados al listado de facturas disponibles.
     * @param string|null $nextStatus Próximo estado del pipeline si aplica.
     * @param array<string> $advanceErrors Errores que impiden avanzar (calculados con el State).
     * @param bool $canRegress True si el rol puede regresar el registro.
     * @param string|null $previousStatus Estado anterior si la regresión está disponible.
     * @param string|null $regressLockMessage Mensaje de bloqueo de regresión, null si está permitida.
     * @param bool $canRegisterPayment True si el rol puede registrar pagos en este registro.
     * @param bool $canAuthorizePayment True si el rol puede autorizar pagos.
     * @param array<int, \App\Service\Dto\BulkPaymentView> $syntheticPayments Vista del pago bulk (0 o 1 items).
     * @param string $roleName Nombre del rol del usuario actual.
     * @param array<string, string> $pipelineLabels Mapa estado => label.
     */
    public function __construct(
        public Refund $record,
        public string $currentStatus,
        public array $employees,
        public array $providers,
        public iterable $operationCenters,
        public array $bankingEntities,
        public iterable $availableInvoices,
        public array $groupFilters,
        public ?string $nextStatus,
        public array $advanceErrors,
        public bool $canAdvance,
        public bool $canRegress,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public bool $canConfirmPayment,
        public array $syntheticPayments,
        public string $roleName,
        public array $pipelineLabels,
    ) {
        $this->pageTitle    = 'Editar Reintegro ' . ($record->code ?? ('#' . $record->id));
        $this->statusLabels = RefundConstants::STATUS_LABELS;

        $this->statusBadgeMap = RefundPresentation::STATUS_BADGES;
        $this->currentStatusBadge = [
            $this->statusLabels[$currentStatus]  ?? 'Desconocido',
            $this->statusBadgeMap[$currentStatus] ?? 'pill-muted',
        ];

        $this->readyForPaymentOptions = PaymentOptions::readyForPayment();
        $this->paymentStatusOptions   = PaymentOptions::paymentStatus();

        // Visibilidad y permisos de edición por estado (lógica compartida con PettyCash/edit).
        $flags = PipelineEditFlags::fromRecord(
            currentStatus: $record->status,
            statuses: RefundConstants::STATUSES,
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

        // Botón de submit (mismo patrón que Invoices/edit y PettyCash/edit).
        $this->submitButtonClass = 'btn btn-primary';
        $this->submitButtonHtml = SubmitButton::decide(
            canAdvance: $canAdvance,
            advanceErrors: $advanceErrors,
            nextStatusLabel: $nextStatus !== null ? ($this->statusLabels[$nextStatus] ?? $nextStatus) : null,
        );
    }
}
