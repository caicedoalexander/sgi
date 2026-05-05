<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Interface\HistoryServiceInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class PettyCashService
{
    // Active petty-cash statuses (excludes pagado — terminal para "Mis Registros").
    private const ACTIVE_STATUSES = [
        PettyCashConstants::STATUS_AGRUPACION,
        PettyCashConstants::STATUS_CONTABILIDAD,
        PettyCashConstants::STATUS_TESORERIA,
        PettyCashConstants::STATUS_AUT_PAGO,
    ];

    // Which petty-cash statuses each role sees in "Mis Registros".
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION  => [PettyCashConstants::STATUS_AGRUPACION],
        RoleConstants::CONTABILIDAD       => [PettyCashConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA          => [
            PettyCashConstants::STATUS_TESORERIA,
            PettyCashConstants::STATUS_AUT_PAGO,
        ],
        RoleConstants::CONTADOR           => [PettyCashConstants::STATUS_AUT_PAGO],
        RoleConstants::AUXILIAR_PERSONAL  => self::ACTIVE_STATUSES,
        RoleConstants::ASISTENTE_PERSONAL => self::ACTIVE_STATUSES,
        RoleConstants::COORDINADOR_ADMIN  => self::ACTIVE_STATUSES,
        RoleConstants::ADMIN              => self::ACTIVE_STATUSES,
    ];

    private GroupedInvoiceService $grouped;
    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service.
     * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth
     */
    public function __construct(
        HistoryServiceInterface $historyService,
        ?PipelineAuthorizationService $pipelineAuth = null,
    ) {
        $this->grouped = new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
            fkField: 'petty_cash_record_id',
            recordTableName: 'PettyCashRecords',
            fkLabel: 'Caja Menor',
            historyService: $historyService,
        );
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }

    /**
     * Get petty-cash statuses visible to a role in "Mis Registros".
     */
    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    /**
     * @param array $invoiceIds Invoice IDs to validate.
     * @return array
     */
    public function validateGrouping(array $invoiceIds): array
    {
        return $this->grouped->validateGrouping($invoiceIds);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param array $invoiceIds Invoice IDs.
     * @return array
     */
    public function addInvoices(PettyCashRecord $record, array $invoiceIds): array
    {
        return $this->grouped->addInvoices($record, $invoiceIds);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param int $invoiceId Invoice ID.
     * @return bool
     */
    public function removeInvoice(PettyCashRecord $record, int $invoiceId): bool
    {
        return $this->grouped->removeInvoice($record, $invoiceId);
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return void
     */
    public function calculateAndSaveTotal(PettyCashRecord $record): void
    {
        $this->grouped->calculateAndSaveTotal($record);
    }

    /**
     * @param array $filters Filters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function getAvailableInvoices(array $filters = []): SelectQuery
    {
        return $this->grouped->getAvailableInvoices($filters);
    }

    /**
     * Returns true if the role can advance the record from the current status.
     *
     * @param int $roleId Role ID.
     * @param string $roleName Role name (kept for compat with PipelineAuthorizationService API).
     * @param string $currentStatus Current pipeline status.
     * @return bool
     */
    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    {
        if (!isset(PettyCashConstants::TRANSITIONS[$currentStatus])) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $currentStatus,
        );
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param string $roleName Role name of the caller (for pipeline authorization).
     * @param int $userId User ID.
     * @return array
     */
    public function advanceStatus(
        PettyCashRecord $record,
        int $roleId,
        string $roleName,
        int $userId,
    ): array {
        $currentStatus = $record->status;
        $nextStatus = PettyCashConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        if (!$this->canAdvance($roleId, $roleName, $currentStatus)) {
            return ['success' => false, 'error' => 'No tiene permisos para avanzar este registro.'];
        }

        if ($nextStatus === PettyCashConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'Debe registrar un pago para avanzar desde Tesorería.'];
        }

        if ($currentStatus === PettyCashConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'La autorización de pago se gestiona desde la sección de pagos.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $fkField = $this->grouped->getFkField();
        $invoices = $invoicesTable->find()
            ->where([$fkField => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['success' => false, 'error' => 'El registro debe tener al menos una factura agrupada.'];
        }

        $validationErrors = $this->_validateTransition($currentStatus, $record);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'No se puede avanzar. ' . implode('. ', $validationErrors),
            ];
        }

        $connection = $invoicesTable->getConnection();

        return $connection->transactional(function () use ($record, $nextStatus, $invoicesTable, $fkField, $userId) {
            $today = date('Y-m-d');
            $updateData = [];

            if ($nextStatus === PettyCashConstants::STATUS_CONTABILIDAD) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                ];
            } elseif ($nextStatus === PettyCashConstants::STATUS_TESORERIA) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
                    'accrued' => (bool)$record->accrued,
                    'accrual_date' => $record->accrual_date ?? $today,
                    'ready_for_payment' => $record->ready_for_payment,
                ];
            }

            $invoicesBefore = $invoicesTable->find()
                ->select(['id', 'pipeline_status'])
                ->where([$fkField => $record->id])
                ->all()
                ->toArray();

            if (!empty($updateData)) {
                $invoicesTable->updateAll(
                    $updateData,
                    [$fkField => $record->id],
                );

                $newPipelineStatus = $updateData['pipeline_status'] ?? null;
                if ($newPipelineStatus) {
                    $this->grouped->recordBulkHistory($record->id, $invoicesBefore, $newPipelineStatus, $userId);
                }
            }

            $table = TableRegistry::getTableLocator()->get('PettyCashRecords');
            $record->status = $nextStatus;
            $table->save($record);

            return [
                'success' => true,
                'nextStatus' => $nextStatus,
            ];
        });
    }

    /**
     * Get transition validation errors for the current record (used by views).
     *
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return array
     */
    public function getTransitionErrors(PettyCashRecord $record): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where([$this->grouped->getFkField() => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['El registro debe tener al menos una factura agrupada.'];
        }

        return $this->_validateTransition($record->status, $record);
    }

    /**
     * Validate petty cash specific transition requirements.
     *
     * Solo `contabilidad → tesoreria` tiene preconditions de campos. Las demás
     * transiciones (`agrupacion → contabilidad`, `tesoreria → aut_pago`)
     * dependen únicamente de invariantes de pipeline que se validan fuera.
     *
     * @param string $fromStatus Current status.
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return array
     */
    private function _validateTransition(string $fromStatus, PettyCashRecord $record): array
    {
        $errors = [];

        if ($fromStatus === PettyCashConstants::STATUS_CONTABILIDAD) {
            if (empty($record->accrued)) {
                $errors[] = 'El registro debe estar marcado como Causado.';
            }
            if (empty($record->ready_for_payment)) {
                $errors[] = 'Debe seleccionar "Lista para Pago".';
            }
        }

        return $errors;
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return bool
     */
    public function canDelete(PettyCashRecord $record): bool
    {
        return $record->isAgrupacion();
    }

    /**
     * Delete a petty cash record and unlink its child invoices atomically.
     * Both operations run in a single transaction so a partial failure cannot
     * leave invoices unlinked from a still-existing record.
     *
     * @param \App\Model\Entity\PettyCashRecord $record Record to delete.
     * @return \App\Service\ServiceResult
     */
    public function deleteRecord(PettyCashRecord $record): ServiceResult
    {
        if (!$this->canDelete($record)) {
            return ServiceResult::fail('Solo se pueden eliminar registros en estado Agrupación.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $fkField = $this->grouped->getFkField();

        $ok = $recordsTable->getConnection()->transactional(
            function () use ($recordsTable, $invoicesTable, $record, $fkField): bool {
                $invoicesTable->updateAll(
                    [$fkField => null],
                    [$fkField => $record->id],
                );

                return (bool)$recordsTable->delete($record);
            },
        );

        if (!$ok) {
            return ServiceResult::fail('No se pudo eliminar el registro.');
        }

        return ServiceResult::ok('Registro de Caja Menor eliminado.');
    }

    /**
     * Register a pending payment for a petty cash record in Tesorería.
     * Moves the record to Aut. Pago.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param string $roleName Role name of the caller (for pipeline authorization).
     * @param array $data Payment data (banking_entity_id, payment_amount, payment_date).
     * @param int $createdBy User ID registering the payment.
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(
        int $recordId,
        int $roleId,
        string $roleName,
        array $data,
        int $createdBy,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_TESORERIA,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para registrar pagos en este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        if (!empty($record->banking_entity_id)) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        if ((float)$record->total_amount <= 0) {
            return ServiceResult::fail('El registro no tiene un monto total válido.');
        }

        // El pago de Caja Menor siempre cubre el total del registro consolidado.
        // Forzamos payment_amount = total_amount para evitar manipulación del POST
        // y mantener consistencia con la materialización en invoice_payments.
        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => $data['banking_entity_id'] ?? null,
            'payment_amount' => $record->total_amount,
            'payment_date' => $data['payment_date'] ?? null,
            'payment_created_by' => $createdBy,
            'payment_rejection_reason' => null,
            'status' => PettyCashConstants::STATUS_AUT_PAGO,
        ]);

        if (!$recordsTable->save($record)) {
            $errors = [];
            foreach ($record->getErrors() as $field => $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = "$field: $msg";
                }
            }

            $msg = 'No se pudo registrar el pago.';
            if (!empty($errors)) {
                $msg .= ' ' . implode(', ', $errors);
            }

            return ServiceResult::fail($msg);
        }

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }

    /**
     * Authorize a pending petty cash payment.
     * Materializes invoice_payments for child invoices and moves record to Pagado.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param string $roleName Role name of the caller (for pipeline authorization).
     * @param int $authorizedBy User ID authorizing.
     * @return \App\Service\ServiceResult
     */
    public function authorizePayment(
        int $recordId,
        int $roleId,
        string $roleName,
        int $authorizedBy,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUT_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para autorizar pagos de este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUT_PAGO) {
            return ServiceResult::fail('El registro no está en estado Autorización de Pago.');
        }

        if (empty($record->banking_entity_id)) {
            return ServiceResult::fail('No hay un pago pendiente para autorizar.');
        }

        $connection = $recordsTable->getConnection();

        $ok = $connection->transactional(function () use (
            $record,
            $authorizedBy,
            $recordsTable,
            $invoicesTable,
            $invoicePaymentsTable,
        ) {
            $childInvoices = $invoicesTable->find()
                ->where(['petty_cash_record_id' => $record->id])
                ->all();

            foreach ($childInvoices as $invoice) {
                // Materializar invoice_payment solo cuando hay monto efectivo.
                // Facturas con amount = 0 (caso atípico pero posible) se marcan
                // pagada sin crear un pago de cero pesos.
                if ((float)$invoice->amount > 0) {
                    $invoicePayment = $invoicePaymentsTable->newEntity([
                        'invoice_id' => $invoice->id,
                        'banking_entity_id' => $record->banking_entity_id,
                        'amount' => $invoice->amount,
                        'payment_date' => $record->payment_date,
                        'petty_cash_record_id' => $record->id,
                        'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                        'authorized' => true,
                        'authorized_by' => $authorizedBy,
                        'authorized_date' => date('Y-m-d'),
                        'created_by' => $record->payment_created_by,
                    ]);

                    if (!$invoicePaymentsTable->save($invoicePayment)) {
                        return false;
                    }
                }

                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $record->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
            }

            $record->status = PettyCashConstants::STATUS_PAGADO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_authorized_by = $authorizedBy;
            $record->payment_authorized_date = date('Y-m-d');

            return (bool)$recordsTable->save($record);
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo autorizar el pago.');
        }

        return ServiceResult::ok('Pago autorizado. Registro marcado como Pagado.');
    }

    /**
     * Reject a pending petty cash payment.
     * Clears pending fields and returns record to Tesorería with rejection reason.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param string $roleName Role name of the caller (for pipeline authorization).
     * @param int $rejectedBy User ID rejecting (currently unused, reserved for audit).
     * @param string $reason Rejection reason (required).
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(
        int $recordId,
        int $roleId,
        string $roleName,
        int $rejectedBy,
        string $reason,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUT_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para rechazar pagos de este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUT_PAGO) {
            return ServiceResult::fail('Solo se pueden rechazar pagos en estado Autorización de Pago.');
        }

        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => null,
            'payment_amount' => null,
            'payment_date' => null,
            'payment_created_by' => null,
            'payment_rejection_reason' => $reason,
            'status' => PettyCashConstants::STATUS_TESORERIA,
        ]);

        if (!$recordsTable->save($record)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }

    /**
     * Returns the previous pipeline status, or null if no predecessor exists
     * or the state is excluded from regression.
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PettyCashConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    /**
     * Returns true if the role can regress the record from the current status.
     */
    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $currentStatus,
        );
    }

    /**
     * Returns a human-readable lock message preventing regression, or null if allowed.
     */
    public function getRegressionLockMessage(PettyCashRecord $record): ?string
    {
        // Único bloqueo: tesoreria → contabilidad con pago pendiente registrado.
        if (
            $record->status === PettyCashConstants::STATUS_TESORERIA
            && !empty($record->payment_amount)
        ) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado. Anule o reasigne el pago primero.';
        }

        return null;
    }

    /**
     * Regress the record to its previous pipeline status.
     * Propagates the change to child invoices for contabilidad and tesoreria,
     * and stores the reason as a typed observation.
     *
     * @return array{success: bool, error: ?string, previousStatus: ?string}
     */
    public function regress(
        PettyCashRecord $record,
        int $roleId,
        string $roleName,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $record->status;

        if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Este registro ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar este registro.';

            return ['success' => false, 'error' => $error, 'previousStatus' => null];
        }

        $lock = $this->getRegressionLockMessage($record);
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
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('PettyCashObservations');
        $fkField = $this->grouped->getFkField();

        // Map child invoice status. agrupacion no aplica (hijas sin pipeline alineado).
        // aut_pago tampoco aplica (en avance tesoreria→aut_pago las hijas no cambiaron).
        $childPipelineMap = [
            PettyCashConstants::STATUS_CONTABILIDAD => InvoiceConstants::STATUS_CONTABILIDAD,
            PettyCashConstants::STATUS_TESORERIA => InvoiceConstants::STATUS_TESORERIA,
        ];

        $ok = $invoicesTable->getConnection()->transactional(
            function () use (
                $recordsTable,
                $invoicesTable,
                $observationsTable,
                $record,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
                $fkField,
                $childPipelineMap,
            ): bool {
                $record->status = $previousStatus;
                if (!$recordsTable->save($record)) {
                    return false;
                }

                if (isset($childPipelineMap[$previousStatus])) {
                    $newPipelineStatus = $childPipelineMap[$previousStatus];
                    $invoicesBefore = $invoicesTable->find()
                        ->select(['id', 'pipeline_status'])
                        ->where([$fkField => $record->id])
                        ->all()
                        ->toArray();

                    $invoicesTable->updateAll(
                        ['pipeline_status' => $newPipelineStatus],
                        [$fkField => $record->id],
                    );

                    $this->grouped->recordBulkHistory(
                        $record->id,
                        $invoicesBefore,
                        $newPipelineStatus,
                        $userId,
                    );
                }

                $observation = $observationsTable->newEntity([
                    'petty_cash_record_id' => $record->id,
                    'user_id' => $userId,
                    'type' => PettyCashConstants::OBSERVATION_TYPE_REGRESSION,
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
                'error' => 'No se pudo regresar el registro. Intente de nuevo.',
                'previousStatus' => null,
            ];
        }

        return ['success' => true, 'error' => null, 'previousStatus' => $previousStatus];
    }
}
