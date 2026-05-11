<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\ViewModel\Support\PaymentOptions;

/**
 * Datos pre-calculados que el template `templates/Refunds/edit.php` necesita.
 * Construido por `RefundsController::_buildEditViewModel()` y pasado como `$viewModel`.
 */
final class RefundEditViewModel
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
    /** @var array<int,string> id → label */
    public readonly array $invoiceOptions;
    public readonly bool $canEditAccounting;
    public readonly bool $canEditTreasury;
    public readonly bool $canSave;
    public readonly bool $canAdvance;
    public readonly string $submitButtonLabel;
    public readonly string $submitButtonClass;
    public readonly int $invoiceCount;

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
        public readonly Refund $record,
        public readonly string $currentStatus,
        public readonly array $employees,
        public readonly array $providers,
        public readonly iterable $operationCenters,
        public readonly array $bankingEntities,
        public readonly iterable $availableInvoices,
        public readonly array $groupFilters,
        public readonly ?string $nextStatus,
        public readonly array $advanceErrors,
        public readonly bool $canRegress,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
        public readonly bool $canConfirmPayment,
        public readonly array $syntheticPayments,
        public readonly string $roleName,
        public readonly array $pipelineLabels,
    ) {
        $this->pageTitle    = 'Editar Reintegro ' . ($record->code ?? ('#' . $record->id));
        $this->statusLabels = RefundConstants::STATUS_LABELS;

        // Badges específicos del header del edit (NO consolidar con
        // RefundPresentation::STATUS_BADGES — los colores son distintos
        // y reflejan el énfasis visual del formulario de edición).
        $this->statusBadgeMap = [
            'agrupacion'        => 'bg-info text-dark',
            'contabilidad'      => 'bg-primary',
            'tesoreria'         => 'bg-warning text-dark',
            'autorizacion_pago' => 'bg-secondary',
            'pagada'            => 'bg-success',
        ];
        $this->currentStatusBadge = [
            $this->statusLabels[$currentStatus]  ?? 'Desconocido',
            $this->statusBadgeMap[$currentStatus] ?? 'bg-dark',
        ];

        $this->readyForPaymentOptions = PaymentOptions::readyForPayment();
        $this->paymentStatusOptions   = PaymentOptions::paymentStatus();

        // Secciones visibles por estado.
        $idx = array_search($record->status, RefundConstants::STATUSES, true);
        $this->statusIndex   = $idx === false ? 0 : (int)$idx;
        $this->showAccounting = $this->statusIndex >= 1; // contabilidad+
        $this->showTreasury   = $this->statusIndex >= 2; // tesoreria+

        // Opciones del dropdown "Agrupar factura".
        $opts = [];
        foreach ($availableInvoices as $inv) {
            $opts[$inv->id] = ($inv->invoice_number ?? '#' . $inv->id)
                . ' - ' . ($inv->provider->name ?? 'Sin proveedor')
                . ' - ' . ($inv->operation_center->name ?? '')
                . ' - $' . number_format((float)$inv->amount, 0, ',', '.')
                . ' (' . ($inv->issue_date?->format('d/m/Y') ?? '') . ')';
        }
        $this->invoiceOptions = $opts;

        // Permisos de edición por estado.
        $this->canEditAccounting = $record->isContabilidad();
        $this->canEditTreasury   = $record->isTesoreria();
        $this->canSave           = $record->isAgrupacion() || $record->isContabilidad() || $record->isTesoreria();
        $this->invoiceCount      = count($record->invoices ?? []);

        // Botón de submit (mismo patrón que Invoices/edit).
        $this->canAdvance        = $nextStatus !== null;
        $this->submitButtonClass = 'btn btn-primary';
        if ($this->canAdvance && empty($advanceErrors) && $nextStatus !== null) {
            $nextLabel = $this->statusLabels[$nextStatus] ?? $nextStatus;
            $this->submitButtonLabel = '<i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar a: '
                . htmlspecialchars($nextLabel, ENT_QUOTES, 'UTF-8');
        } else {
            $this->submitButtonLabel = '<i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Cambios';
        }
    }
}
