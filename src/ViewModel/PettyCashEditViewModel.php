<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Dto\GroupReadinessReport;
use App\View\Presentation\InvoicePresentation;
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
    /**
     * @var array<string,string>
     */
    public array $statusLabels;
    /**
     * @var array<string,string>
     */
    public array $statusBadgeMap;
    /**
     * @var array{0:string,1:string}
     */
    public array $currentStatusBadge;
    /**
     * @var array<string,string>
     */
    public array $readyForPaymentOptions;
    /**
     * @var array<string,string>
     */
    public array $paymentStatusOptions;
    public int $statusIndex;
    public bool $showAccounting;
    public bool $showTreasury;
    public bool $canEditAccounting;
    public bool $canEditTreasury;
    public bool $canSave;
    /**
     * HTML pre-renderizado (ícono + texto). El template lo imprime raw.
     */
    public string $submitButtonHtml;
    public string $submitButtonClass;
    public int $invoiceCount;
    /**
     * @var list<\App\View\Presentation\GroupedInvoiceRowView>
     */
    public array $groupedRows;

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Caja menor a editar.
     * @param string $currentStatus Estado actual del pipeline.
     * @param string $roleName Rol del usuario actual.
     * @param bool $canDeleteDocuments Puede eliminar documentos del pipeline.
     * @param bool $canRegisterPayment Puede registrar un nuevo pago.
     * @param bool $canAuthorizePayment Puede autorizar un pago pendiente.
     * @param bool $canConfirmPayment Puede confirmar la ejecución del pago.
     * @param bool $canRegress Puede regresar la caja menor al paso anterior.
     * @param bool $canAdvance Puede avanzar la caja menor al siguiente paso.
     * @param array $advanceErrors Errores que bloquean el avance.
     * @param string|null $nextStatus Siguiente estado del pipeline, o null.
     * @param string|null $previousStatus Estado anterior del pipeline, o null.
     * @param string|null $regressLockMessage Motivo por el que no se puede regresar, o null.
     * @param array $pipelineLabels Etiquetas ES por estado del pipeline.
     * @param array $syntheticPayments Pagos sintéticos derivados de las columnas del record.
     * @param mixed $availableInvoices Facturas candidatas a vincular.
     * @param mixed $operationCenters Listado de centros de operación.
     * @param mixed $providers Listado de proveedores.
     * @param mixed $bankingEntities Listado de entidades bancarias.
     * @param array $groupFilters Filtros aplicados a las facturas candidatas.
     * @param \App\Service\Dto\GroupReadinessReport|null $readiness Requisitos DIAN/soporte pendientes de las hijas.
     * @param bool $canUploadSupport Puede subir soporte a las facturas hijas.
     */
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
        public ?GroupReadinessReport $readiness = null,
        public bool $canUploadSupport = false,
    ) {
        $this->pageTitle    = 'Editar Caja Menor ' . ($record->code ?? '#' . $record->id);
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

        $groupedRows = [];
        foreach ($record->invoices ?? [] as $inv) {
            // Caja Menor: las hijas viven en `contabilidad`; canResolveDian es
            // irrelevante (forGroupedRow solo activa el <select> en `aprobacion`).
            $groupedRows[] = InvoicePresentation::forGroupedRow($inv, canResolveDian: false);
        }
        $this->groupedRows = $groupedRows;
    }
}
