<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PettyCashRecord;

/**
 * Datos inmutables de vista para PettyCashRecordsController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class PettyCashEditViewModel
{
    public function __construct(
        // Entidad principal
        public readonly PettyCashRecord $record,
        public readonly string $currentStatus,
        public readonly string $roleName,
        // Permisos del pipeline
        public readonly bool $canDeleteDocuments,
        public readonly bool $canRegisterPayment,
        public readonly bool $canAuthorizePayment,
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
        public readonly mixed $bankingEntities,
        public readonly array $groupFilters,
    ) {
    }
}
