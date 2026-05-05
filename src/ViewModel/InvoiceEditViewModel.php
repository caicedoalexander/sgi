<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Invoice;

/**
 * Datos inmutables de vista para InvoicesController::edit().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class InvoiceEditViewModel
{
    public function __construct(
        // Entidad principal
        public readonly Invoice $invoice,
        public readonly string $currentStatus,
        public readonly string $roleName,

        // Permisos y estado del pipeline
        public readonly array $editableFields,
        public readonly bool $canAdvance,
        public readonly bool $canDeleteDocuments,
        public readonly bool $canRegress,
        public readonly bool $isRejected,
        public readonly bool $isApproved,

        // Secciones visibles
        public readonly array $visibleSections,
        public readonly array $collapsibleSections,

        // Avance / retroceso
        public readonly array $advanceErrors,
        public readonly ?string $nextStatus,
        public readonly ?string $previousStatus,
        public readonly ?string $regressLockMessage,

        // Pipeline visual
        public readonly array $pipelineStatuses,
        public readonly array $pipelineLabels,

        // Multi-aprobador
        public readonly array $currentApprovals,
        public readonly bool $hasPendingApprovals,
        public readonly bool $canSendLinks,
        public readonly bool $canModifyApprovers,

        // Pagos
        public readonly float $paymentsTotal,

        // Dropdowns del formulario
        public readonly mixed $providers,
        public readonly mixed $operationCenters,
        public readonly mixed $expenseTypes,
        public readonly mixed $costCenters,
        public readonly mixed $approvers,
        public readonly mixed $employees,
        public readonly mixed $bankingEntities,

        // Email logs
        public readonly array $emailLogs,
    ) {
    }
}
