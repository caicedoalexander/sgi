<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use Cake\ORM\TableRegistry;

class InvoicePipelineService
{
    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService Audit trail recorder.
     * @param \App\Service\InvoicePaymentService $paymentService Resolves payment balances.
     * @param \App\Service\InvoiceFieldAccessPolicy $fieldPolicy Editable fields per role/state.
     * @param \App\Service\AdvanceLegalizationService $advanceLegalizationService Legalization cross-link.
     * @param \App\Service\InvoiceLockPolicy $lockPolicy Edit/regression lock policy.
     * @param \App\Service\InvoiceTransitionValidator $transitionValidator Pipeline transition validator.
     */
    public function __construct(
        private readonly HistoryServiceInterface $historyService,
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
        private readonly AdvanceLegalizationService $advanceLegalizationService,
        private readonly InvoiceLockPolicy $lockPolicy,
        private readonly InvoiceTransitionValidator $transitionValidator,
    ) {
    }

    // Pipeline statuses in order (flujo normal de facturas).
    public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;

    // Todos los estados válidos para almacenar en invoices.pipeline_status,
    // incluyendo el terminal `legalizada` exclusivo de document_type = Legalización.
    public const ALL_STATUSES = [
        InvoiceConstants::STATUS_APROBACION,
        InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        InvoiceConstants::STATUS_PAGADA,
        InvoiceConstants::STATUS_LEGALIZADA,
    ];

    public const STATUS_LABELS = [
        InvoiceConstants::STATUS_APROBACION        => 'Aprobación',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
        InvoiceConstants::STATUS_TESORERIA         => 'Tesorería',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
        InvoiceConstants::STATUS_PAGADA            => 'Pagada',
        InvoiceConstants::STATUS_LEGALIZADA        => 'Legalizada',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];

    // Which statuses each role can see/work with
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
        RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::CONTADOR          => [InvoiceConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::ADMIN             => self::ALL_STATUSES,
    ];

    // Active advance statuses (excludes pagada and legalizada — terminales para "Mis Anticipos").
    private const ADVANCE_ACTIVE_STATUSES = [
        InvoiceConstants::STATUS_APROBACION,
        InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO,
    ];

    // Which advance statuses each role sees in "Mis Anticipos".
    private const ADVANCE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION  => [InvoiceConstants::STATUS_APROBACION],
        RoleConstants::CONTABILIDAD       => [InvoiceConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA          => [InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::CONTADOR           => [InvoiceConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::AUXILIAR_PERSONAL  => self::ADVANCE_ACTIVE_STATUSES,
        RoleConstants::ASISTENTE_PERSONAL => self::ADVANCE_ACTIVE_STATUSES,
        RoleConstants::COORDINADOR_ADMIN  => self::ADVANCE_ACTIVE_STATUSES,
        RoleConstants::ADMIN              => self::ADVANCE_ACTIVE_STATUSES,
    ];

    // Next status transitions
    public const TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION        => InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_CONTABILIDAD       => InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_TESORERIA          => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO  => InvoiceConstants::STATUS_PAGADA,
        InvoiceConstants::STATUS_PAGADA             => null,
        InvoiceConstants::STATUS_LEGALIZADA         => null,
    ];

    // Backward transitions (counterpart of TRANSITIONS for the regress operation).
    public const BACKWARD_TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION         => null,
        InvoiceConstants::STATUS_CONTABILIDAD       => InvoiceConstants::STATUS_APROBACION,
        InvoiceConstants::STATUS_TESORERIA          => InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO  => InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_PAGADA             => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        InvoiceConstants::STATUS_LEGALIZADA         => null,
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    /**
     * Get advance statuses visible to a role in "Mis Anticipos".
     */
    public function getVisibleAdvanceStatuses(string $roleName): array
    {
        return self::ADVANCE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    /**
     * Returns the pipeline statuses to render visually for an invoice, depending on document_type.
     * Legalizaciones tienen un pipeline corto: aprobacion → contabilidad → legalizada.
     *
     * @return array<string>
     */
    public function getPipelineStatusesFor(?string $documentType = null): array
    {
        if ($documentType === InvoiceConstants::DOCTYPE_LEGALIZACION) {
            return InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION;
        }

        return InvoiceConstants::PIPELINE_STATUSES;
    }

    public function getEditableFields(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getEditableFields($roleName, $status);
    }

    public function getVisibleSections(string $roleName, string $status, ?string $documentType = null): array
    {
        return $this->fieldPolicy->getVisibleSections($roleName, $status, $documentType);
    }

    public function getCollapsibleSections(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getCollapsibleSections($roleName, $status);
    }

    /**
     * Returns true if the invoice has been rejected in the revision step.
     */
    public function isRejected(object $invoice): bool
    {
        if ($invoice instanceof Invoice) {
            return $invoice->isRejected();
        }

        return ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    /**
     * Delegates to InvoiceLockPolicy.
     */
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        return $this->lockPolicy->isLockedByPaidScheduling($invoiceId);
    }

    /**
     * Delegates to InvoiceLockPolicy.
     */
    public function isLockedByPettyCash(object $invoice): bool
    {
        return $this->lockPolicy->isLockedByPettyCash($invoice);
    }

    /**
     * Delegates to InvoiceLockPolicy.
     */
    public function getEditLockMessage(object $invoice): ?string
    {
        return $this->lockPolicy->getEditLockMessage($invoice);
    }

    /**
     * Delegates to InvoiceTransitionValidator.
     */
    public function validateTransitionRequirements(object $invoice, string $fromStatus): array
    {
        return $this->transitionValidator->validateAdvance($invoice, $fromStatus);
    }

    /**
     * Delegates to InvoiceTransitionValidator.
     *
     * @return array<int, array{field:string, label:string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        return $this->transitionValidator->getTransitionRules($fromStatus);
    }

    /**
     * Delegates to InvoiceTransitionValidator.
     *
     * @param array<string> $errors error messages aligned positionally with $rules
     * @param array<int, array{field:string, label:string}> $rules
     * @return array<string>
     */
    public function filterAdvanceErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        return $this->transitionValidator->filterErrorsForRole($errors, $rules, $roleName, $status);
    }

    public function canAdvance(string $roleName, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        $visibleStatuses = $this->getVisibleStatuses($roleName);

        return in_array($currentStatus, $visibleStatuses, true);
    }

    public function getNextStatus(string $currentStatus, ?string $documentType = null): ?string
    {
        // Legalizaciones no avanzan manualmente desde contabilidad.
        // El cierre lo dispara AdvanceLegalizationService cuando el Anticipo padre se legaliza.
        if (
            $documentType === InvoiceConstants::DOCTYPE_LEGALIZACION
            && $currentStatus === InvoiceConstants::STATUS_CONTABILIDAD
        ) {
            return null;
        }

        return self::TRANSITIONS[$currentStatus] ?? null;
    }

    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        return $this->fieldPolicy->filterEntityData($data, $roleName, $status);
    }

    public function getStatusIndex(string $status): int
    {
        $index = array_search($status, self::STATUSES);

        return $index !== false ? $index : 0;
    }

    /**
     * Save invoice fields, optionally advance the pipeline, and record history.
     *
     * Returns an associative array:
     *   - 'saved'          => bool
     *   - 'advanced'       => bool
     *   - 'nextStatus'     => ?string
     *   - 'advanceErrors'  => string[]   (warnings when save succeeded but advance did not)
     */
    public function saveAndAdvance(
        Invoice $invoice,
        array $data,
        string $roleName,
        int $userId,
        ?string $baseUrl = null,
    ): array {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $currentStatus = $invoice->pipeline_status;
        $filteredData = $this->filterEntityData($data, $roleName, $currentStatus);

        // Auto-set area_approval_date when area_approval changes to Aprobada or Rechazada
        if (array_key_exists('area_approval', $filteredData)) {
            $newApproval = $filteredData['area_approval'] ?? '';
            $oldApproval = $invoice->area_approval ?? '';
            if ($newApproval !== $oldApproval && in_array($newApproval, [InvoiceConstants::APPROVAL_APPROVED, InvoiceConstants::APPROVAL_REJECTED])) {
                $invoice->area_approval_date = date('Y-m-d');
            }
        }

        $canAdvance = $this->canAdvance($roleName, $currentStatus, $invoice->document_type ?? null);
        $isRejected = $this->isRejected($invoice);

        // Determine if we can advance with submitted data
        $advanceNextStatus = null;
        $postAdvanceErrors = [];
        if ($canAdvance && !$isRejected) {
            $testEntity = $invoicesTable->patchEntity(clone $invoice, $filteredData);
            $postAdvanceErrors = $this->validateTransitionRequirements($testEntity, $currentStatus);
            if (empty($postAdvanceErrors)) {
                $advanceNextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
            }
        }

        $original = clone $invoice;

        $saved = $invoicesTable->getConnection()->transactional(
            function () use ($invoicesTable, &$invoice, $filteredData, $advanceNextStatus, $currentStatus, $userId, $original) {
                $invoice = $invoicesTable->patchEntity($invoice, $filteredData);

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordChanges($original, $invoice, $userId);

                if ($advanceNextStatus) {
                    $invoice->pipeline_status = $advanceNextStatus;
                    if (!$invoicesTable->save($invoice)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $invoice->id,
                        $currentStatus,
                        $advanceNextStatus,
                        $userId,
                    );

                    // After advancing from autorizacion_pago: check payment_status
                    // If partial, regress to tesoreria for more payments
                    if ($currentStatus === InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
                        $this->paymentService->recalculatePaymentStatus($invoice->id);
                        $refreshed = $invoicesTable->get($invoice->id);

                        if ($refreshed->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
                            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
                            $advanceNextStatus = InvoiceConstants::STATUS_TESORERIA;
                            $invoicesTable->save($invoice);
                            $this->historyService->recordStatusChange(
                                $invoice->id,
                                InvoiceConstants::STATUS_PAGADA,
                                InvoiceConstants::STATUS_TESORERIA,
                                $userId,
                            );
                        }
                    }

                    // Anticipo → Legalización auto-init (idempotent).
                    if (
                        $invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
                        && ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
                    ) {
                        $this->advanceLegalizationService->initialize($invoice, $userId);
                    }
                }

                return true;
            },
        );

        return [
            'saved' => (bool)$saved,
            'advanced' => (bool)$advanceNextStatus && (bool)$saved,
            'nextStatus' => $advanceNextStatus,
            'advanceErrors' => $postAdvanceErrors,
        ];
    }

    /**
     * Standalone advance (without field edits). Used by the legacy advanceStatus route.
     *
     * Returns an associative array:
     *   - 'success' => bool
     *   - 'error'   => ?string
     *   - 'nextStatus' => ?string
     */
    public function advance(Invoice $invoice, string $roleName, int $userId): array
    {
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canAdvance($roleName, $currentStatus, $invoice->document_type ?? null)) {
            return ['success' => false, 'error' => 'No tiene permisos para avanzar esta factura.', 'nextStatus' => null];
        }

        if ($this->isRejected($invoice)) {
            return ['success' => false, 'error' => 'La factura fue rechazada. El flujo ha terminado.', 'nextStatus' => null];
        }

        $errors = $this->validateTransitionRequirements($invoice, $currentStatus);
        if (!empty($errors)) {
            return ['success' => false, 'error' => implode(' ', $errors), 'nextStatus' => null];
        }

        $nextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
        if (!$nextStatus) {
            return ['success' => false, 'error' => 'Esta factura ya está en el estado final.', 'nextStatus' => null];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice->pipeline_status = $nextStatus;

        if (!$invoicesTable->save($invoice)) {
            return ['success' => false, 'error' => 'No se pudo avanzar el estado.', 'nextStatus' => null];
        }

        $this->historyService->recordStatusChange($invoice->id, $currentStatus, $nextStatus, $userId);

        return [
            'success' => true,
            'error' => null,
            'nextStatus' => $nextStatus,
        ];
    }

    public function getPreviousStatus(string $currentStatus): ?string
    {
        return self::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function canRegress(string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return in_array($currentStatus, $this->getVisibleStatuses($roleName), true);
    }

    /**
     * Returns a human-readable reason if the invoice cannot be regressed,
     * or null if regression is allowed (independent of role).
     */
    public function getRegressionLockMessage(object $invoice): ?string
    {
        $lockMsg = $this->lockPolicy->getRegressionLockMessage($invoice);
        if ($lockMsg !== null) {
            return $lockMsg;
        }

        // Bloqueo cross-aggregate por Anticipo con legalización iniciada.
        // Plan 4 lo deja aquí; Task 5 lo moverá a DocumentTypePolicy.
        if (
            ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
            && !empty($invoice->id)
            && $this->advanceLegalizationService->hasLegalization((int)$invoice->id)
        ) {
            return 'No se puede regresar: la legalización del anticipo ya fue iniciada.';
        }

        return null;
    }

    /**
     * Regress the invoice to its previous pipeline status (cold regression).
     * Records a status change in invoice_histories and stores the reason in
     * invoice_observations as a regression-typed observation.
     *
     * @return array{success: bool, error: ?string, previousStatus: ?string}
     */
    public function regress(
        Invoice $invoice,
        string $roleName,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canRegress($roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta factura ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta factura.';

            return ['success' => false, 'error' => $error, 'previousStatus' => null];
        }

        $lock = $this->getRegressionLockMessage($invoice);
        if ($lock !== null) {
            return ['success' => false, 'error' => $lock, 'previousStatus' => null];
        }

        if (mb_strlen($reason) < 10) {
            return [
                'success' => false,
                'error' => 'El motivo es obligatorio (mínimo 10 caracteres).',
                'previousStatus' => null,
            ];
        }
        if (mb_strlen($reason) > 500) {
            return [
                'success' => false,
                'error' => 'El motivo no puede superar 500 caracteres.',
                'previousStatus' => null,
            ];
        }

        $previousStatus = $this->getPreviousStatus($currentStatus);
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');

        $ok = $invoicesTable->getConnection()->transactional(
            function () use (
                $invoicesTable,
                $observationsTable,
                $invoice,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
            ): bool {
                $invoice->pipeline_status = $previousStatus;
                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordStatusChange(
                    $invoice->id,
                    $currentStatus,
                    $previousStatus,
                    $userId,
                );

                $observation = $observationsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'user_id' => $userId,
                    'type' => InvoiceConstants::OBSERVATION_TYPE_REGRESSION,
                    'message' => $reason,
                    'metadata' => [
                        'from_status' => $currentStatus,
                        'to_status' => $previousStatus,
                    ],
                ]);

                return (bool)$observationsTable->save($observation);
            },
        );

        if (!$ok) {
            return [
                'success' => false,
                'error' => 'No se pudo regresar la factura. Intente de nuevo.',
                'previousStatus' => null,
            ];
        }

        return ['success' => true, 'error' => null, 'previousStatus' => $previousStatus];
    }

    /**
     * Promueve a `legalizada` todas las facturas tipo Legalización vinculadas al
     * Anticipo dado que estén actualmente en `contabilidad`. Disparado por
     * AdvanceLegalizationService cuando el Anticipo padre llega a STATUS_LEGALIZADA.
     *
     * @return int Cantidad de facturas promovidas.
     */
    public function legalizeLinkedInvoices(int $advanceInvoiceId, int $userId): int
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $linked = $invoicesTable->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            ])
            ->all();

        if ($linked->isEmpty()) {
            return 0;
        }

        $count = 0;
        $invoicesTable->getConnection()->transactional(
            function () use ($linked, $userId, &$count, $invoicesTable): bool {
                foreach ($linked as $inv) {
                    $from = $inv->pipeline_status;
                    $inv->pipeline_status = InvoiceConstants::STATUS_LEGALIZADA;
                    if (!$invoicesTable->save($inv)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $inv->id,
                        $from,
                        InvoiceConstants::STATUS_LEGALIZADA,
                        $userId,
                    );
                    $count++;
                }

                return true;
            },
        );

        return $count;
    }
}
