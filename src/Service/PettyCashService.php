<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\Domain\PettyCash\PipelineStatus as PettyCashPipelineStatus;
use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Event\InvoicePaidEvent;
use App\Model\Entity\PettyCashRecord;
use App\Service\Dto\BulkPaymentView;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\PettyCash\PettyCashPipelineStateRegistry;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use DateTimeInterface;
use InvalidArgumentException;

class PettyCashService
{
    private GroupedInvoiceService $grouped;
    private PipelineAuthorizationService $pipelineAuth;
    private PettyCashHistoryService $history;
    private PettyCashPipelineStateRegistry $stateRegistry;
    private HistoryServiceInterface $invoiceHistory;
    private ?EventManagerInterface $events;

    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service for child invoices.
     * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth
     * @param \App\Service\PettyCashHistoryService|null $history Audit trail for the petty cash record itself.
     * @param \App\Service\Pipeline\PettyCash\PettyCashPipelineStateRegistry|null $stateRegistry
     */
    public function __construct(
        HistoryServiceInterface $historyService,
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?PettyCashHistoryService $history = null,
        ?PettyCashPipelineStateRegistry $stateRegistry = null,
        ?EventManagerInterface $events = null,
    ) {
        $this->grouped = new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
            fkField: 'petty_cash_record_id',
            recordTableName: 'PettyCashRecords',
            fkLabel: 'Caja Menor',
            historyService: $historyService,
        );
        $this->invoiceHistory = $historyService;
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->history = $history ?? new PettyCashHistoryService();
        $this->stateRegistry = $stateRegistry ?? new PettyCashPipelineStateRegistry();
        $this->events = $events;
    }

    /**
     * Get petty-cash statuses visible to a role in "Mis Registros".
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->pipelineAuth->getOperableSteps(
            $roleId,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
        );
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
     * Filter raw POST data into editable fields per current status, and detect
     * inline validation errors that block the save (e.g. accrual_date required
     * when accrued is true).
     *
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param array $data Raw request data.
     * @return array{patch: array, errors: array<string>}
     */
    private function _filterEditPatch(PettyCashRecord $record, array $data): array
    {
        $patch = [];
        $errors = [];

        if ($record->isAgrupacion() || $record->isContabilidad()) {
            $patch['notes'] = $data['notes'] ?? $record->notes;
        }

        if ($record->isContabilidad()) {
            $isAccrued = !empty($data['accrued']);
            $patch['accrued'] = $isAccrued;
            if ($isAccrued) {
                $submittedDate = !empty($data['accrual_date']) ? $data['accrual_date'] : null;
                if (empty($submittedDate)) {
                    $errors[] = 'La fecha de causación es requerida cuando el registro está marcado como causado.';
                }
                $patch['accrual_date'] = $submittedDate;
            } else {
                $patch['accrual_date'] = null;
            }
            $patch['ready_for_payment'] = $data['ready_for_payment'] ?? null;
        }

        return ['patch' => $patch, 'errors' => $errors];
    }

    /**
     * Persist editable fields, link any new invoices, and try to advance the
     * pipeline in a single coordinated operation. Mirrors the save+advance
     * pattern of `InvoicePipelineService::saveAndAdvance`.
     *
     * @return \App\Service\ServiceResult on success: data = [
     *   'advanced' => bool,
     *   'nextStatus' => ?string,
     *   'advanceWarning' => ?string,
     *   'linkWarnings' => string[],
     * ]
     */
    public function saveAndAdvance(
        PettyCashRecord $record,
        int $roleId,
        int $userId,
        array $data,
    ): ServiceResult {
        $filtered = $this->_filterEditPatch($record, $data);
        if (!empty($filtered['errors'])) {
            return ServiceResult::fail($filtered['errors']);
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');

        if (!empty($filtered['patch'])) {
            $record = $recordsTable->patchEntity($record, $filtered['patch']);
            if (!$recordsTable->save($record)) {
                $messages = [];
                foreach ($record->getErrors() as $fieldErrors) {
                    foreach ($fieldErrors as $msg) {
                        if (is_string($msg) && $msg !== '') {
                            $messages[] = $msg;
                        }
                    }
                }

                return ServiceResult::fail(
                    !empty($messages) ? $messages : ['No se pudo guardar el registro.'],
                );
            }
        }

        $linkWarnings = [];
        if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
            $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
            if (!empty($invoiceIds)) {
                $linkWarnings = $this->addInvoices($record, $invoiceIds);
            }
        }

        $advanced = false;
        $nextStatus = null;
        $advanceWarning = null;
        if (!$record->isPagada() && $this->canAdvance($roleId, $record->status)) {
            $advanceResult = $this->advanceStatus($record, $roleId, $userId);
            if ($advanceResult->success) {
                $advanced = true;
                $nextStatus = $advanceResult->data['nextStatus'] ?? null;
            } else {
                $advanceWarning = $advanceResult->firstError();
            }
        }

        return ServiceResult::ok([
            'advanced' => $advanced,
            'nextStatus' => $nextStatus,
            'advanceWarning' => $advanceWarning,
            'linkWarnings' => $linkWarnings,
        ]);
    }

    /**
     * Returns true if the role can advance the record from the current status.
     *
     * @param int $roleId Role ID.
     * @param string $currentStatus Current pipeline status.
     * @return bool
     */
    public function canAdvance(int $roleId, string $currentStatus): bool
    {
        $currentEnum = PettyCashPipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return false;
        }

        $state = $this->stateRegistry->get($currentEnum);
        if ($state->getNextStatus() === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            $currentStatus,
        );
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param int $userId User ID.
     * @return \App\Service\ServiceResult on success: data = ['nextStatus' => string]
     */
    public function advanceStatus(
        PettyCashRecord $record,
        int $roleId,
        int $userId,
    ): ServiceResult {
        $currentStatus = $record->status;

        $currentEnum = PettyCashPipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return ServiceResult::fail("Estado inválido: {$currentStatus}");
        }
        $state = $this->stateRegistry->get($currentEnum);
        $nextStatus = $state->getNextStatus()?->value;

        if ($nextStatus === null) {
            return ServiceResult::fail('Este registro ya está en su estado final.');
        }

        if (!$this->canAdvance($roleId, $currentStatus)) {
            return ServiceResult::fail('No tiene permisos para avanzar este registro.');
        }

        if ($nextStatus === PettyCashConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('Debe registrar un pago para avanzar desde Tesorería.');
        }

        if ($currentStatus === PettyCashConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('La autorización de pago se gestiona desde la sección de pagos.');
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $fkField = $this->grouped->getFkField();
        $invoices = $invoicesTable->find()
            ->where([$fkField => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ServiceResult::fail('El registro debe tener al menos una factura agrupada.');
        }

        $validationErrors = $this->_validateTransition($currentStatus, $record);
        if (!empty($validationErrors)) {
            return ServiceResult::fail(
                'No se puede avanzar. ' . implode('. ', $validationErrors),
            );
        }

        $connection = $invoicesTable->getConnection();

        $advanced = $connection->transactional(
            function () use ($record, $currentStatus, $nextStatus, $invoicesTable, $fkField, $userId): bool {
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
                        $this->grouped->recordBulkHistory(
                            $record->id,
                            $invoicesBefore,
                            $newPipelineStatus,
                            $userId,
                        );
                    }
                }

                $table = TableRegistry::getTableLocator()->get('PettyCashRecords');
                $record->status = $nextStatus;

                if (!$table->save($record)) {
                    return false;
                }

                $this->history->recordStatusChange($record->id, $currentStatus, $nextStatus, $userId);

                return true;
            },
        );

        if (!$advanced) {
            return ServiceResult::fail('No se pudo avanzar el registro.');
        }

        return ServiceResult::ok(['nextStatus' => $nextStatus]);
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
     * Delega al State pattern: cada estado declara sus propios requisitos
     * de avance vía PettyCashPipelineState::validateAdvance.
     *
     * @param string $fromStatus Current status.
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @return array
     */
    private function _validateTransition(string $fromStatus, PettyCashRecord $record): array
    {
        $fromEnum = PettyCashPipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return ["Estado inválido: {$fromStatus}"];
        }

        return $this->stateRegistry->get($fromEnum)->validateAdvance($record);
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
     * @param array $data Payment data (banking_entity_id, payment_amount, payment_date).
     * @param int $createdBy User ID registering the payment.
     * @return \App\Service\ServiceResult
     */
    public function registerPayment(
        int $recordId,
        int $roleId,
        array $data,
        int $createdBy,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
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
        $previousStatus = $record->status;
        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => $data['banking_entity_id'] ?? null,
            'payment_amount' => $record->total_amount,
            'payment_date' => $data['payment_date'] ?? null,
            'payment_created_by' => $createdBy,
            'payment_rejection_reason' => null,
            'status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO,
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

        $this->history->recordStatusChange($record->id, $previousStatus, $record->status, $createdBy);

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }

    /**
     * Authorize a pending petty cash payment.
     * Materializes invoice_payments for child invoices and moves record to Pagado.
     *
     * @param int $recordId Record ID.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param int $authorizedBy User ID authorizing.
     * @return \App\Service\ServiceResult
     */
    public function authorizePayment(
        int $recordId,
        int $roleId,
        int $authorizedBy,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para autorizar pagos de este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUTORIZACION_PAGO) {
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

                $invoice->pipeline_status = InvoiceConstants::STATUS_VERIFICACION_PAGO;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $record->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
            }

            $previousStatus = $record->status;
            $record->status = PettyCashConstants::STATUS_VERIFICACION_PAGO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_authorized_by = $authorizedBy;
            $record->payment_authorized_date = date('Y-m-d');

            if (!$recordsTable->save($record)) {
                return false;
            }

            $this->history->recordStatusChange(
                $record->id,
                $previousStatus,
                $record->status,
                $authorizedBy,
            );

            return true;
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
     * @param int $rejectedBy User ID rejecting (currently unused, reserved for audit).
     * @param string $reason Rejection reason (required).
     * @return \App\Service\ServiceResult
     */
    public function rejectPayment(
        int $recordId,
        int $roleId,
        int $rejectedBy,
        string $reason,
    ): ServiceResult {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                PettyCashConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            return ServiceResult::fail('No tiene permisos para rechazar pagos de este registro.');
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUTORIZACION_PAGO) {
            return ServiceResult::fail('Solo se pueden rechazar pagos en estado Autorización de Pago.');
        }

        $previousStatus = $record->status;
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

        $this->history->recordStatusChange($record->id, $previousStatus, $record->status, $rejectedBy);

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }

    /**
     * Returns the previous pipeline status, or null if no predecessor exists
     * or the state is excluded from regression.
     *
     * Delega al State pattern: cada estado declara su predecesor.
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        $currentEnum = PettyCashPipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        return $this->stateRegistry->get($currentEnum)->getPreviousStatus()?->value;
    }

    /**
     * Returns true if the role can regress the record from the current status.
     */
    public function canRegress(int $roleId, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
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
     * @return \App\Service\ServiceResult on success: data = ['previousStatus' => string]
     */
    public function regress(
        PettyCashRecord $record,
        int $roleId,
        int $userId,
        string $reason,
    ): ServiceResult {
        $reason = trim($reason);
        $currentStatus = $record->status;

        if (!$this->canRegress($roleId, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Este registro ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar este registro.';

            return ServiceResult::fail($error);
        }

        $lock = $this->getRegressionLockMessage($record);
        if ($lock !== null) {
            return ServiceResult::fail($lock);
        }

        // La table permite vacío (campo opcional en flujo normal); aquí validamos que
        // el motivo de regresión sea suficientemente descriptivo.
        if (mb_strlen($reason) < 10) {
            return ServiceResult::fail('El motivo es obligatorio (mínimo 10 caracteres).');
        }
        if (mb_strlen($reason) > 500) {
            return ServiceResult::fail('El motivo no puede superar 500 caracteres.');
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

                // verificacion_pago → autorizacion_pago: deshacer la materialización
                // que hizo authorizePayment para que el Contador pueda re-autorizar.
                if (
                    $currentStatus === PettyCashConstants::STATUS_VERIFICACION_PAGO
                    && $previousStatus === PettyCashConstants::STATUS_AUTORIZACION_PAGO
                ) {
                    $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
                    $invoicePaymentsTable->deleteAll(['petty_cash_record_id' => $record->id]);

                    $invoicesTable->updateAll(
                        [
                            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
                            'payment_status' => null,
                            'full_payment_date' => null,
                        ],
                        [$fkField => $record->id],
                    );

                    $record->payment_status = null;
                    $record->payment_authorized_by = null;
                    $record->payment_authorized_date = null;
                    if (!$recordsTable->save($record)) {
                        return false;
                    }
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

                if (!$observationsTable->save($observation)) {
                    return false;
                }

                $this->history->recordStatusChange($record->id, $currentStatus, $previousStatus, $userId);

                return true;
            },
        );

        if (!$ok) {
            return ServiceResult::fail('No se pudo regresar el registro. Intente de nuevo.');
        }

        return ServiceResult::ok(['previousStatus' => $previousStatus]);
    }

    /**
     * Construye una representación uniforme del pago bulk del registro para
     * que la vista pueda reusar el element compartido `payment_section`.
     *
     * Caja Menor guarda un único pago como columnas en la tabla `petty_cash_records`
     * (no tiene tabla de pagos propia); este método materializa esas columnas en
     * la forma que espera el element.
     *
     * @return array<int, \App\Service\Dto\BulkPaymentView> 0 o 1 elementos.
     */
    public function buildSyntheticPayments(PettyCashRecord $record): array
    {
        if (empty($record->banking_entity_id)) {
            return [];
        }

        $isAuthorized = $record->isPagada();

        return [
            new BulkPaymentView(
                id: $record->id,
                banking_entity: $record->banking_entity ?? null,
                amount: $record->payment_amount !== null ? (float)$record->payment_amount : null,
                payment_date: self::_normalizeDate($record->payment_date),
                status: $isAuthorized
                    ? InvoiceConstants::PAYMENT_RECORD_AUTHORIZED
                    : InvoiceConstants::PAYMENT_RECORD_PENDING,
                authorized: $isAuthorized,
                authorized_by_user: $record->payment_authorized_by_user ?? null,
                authorized_date: self::_normalizeDate($record->payment_authorized_date),
                created_by_user: $record->payment_created_by_user ?? null,
                rejection_reason: null,
            ),
        ];
    }

    /**
     * Normaliza un valor de fecha a un DateTimeInterface o null.
     */
    private static function _normalizeDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return new Date($value);
        }

        return null;
    }

    /**
     * Tesorería confirma que el pago del record de caja menor ya se ejecutó.
     * Avanza record y todas sus facturas hijas de verificacion_pago → pagada,
     * registra historial y dispara InvoicePaidEvent por cada hija.
     */
    public function confirmPayment(int $recordId, int $confirmedBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $record = $recordsTable->get($recordId);
        if ($record->status !== PettyCashConstants::STATUS_VERIFICACION_PAGO) {
            return ServiceResult::fail('El registro no está en verificación de pago.');
        }

        $connection = $recordsTable->getConnection();
        $ok = $connection->transactional(function () use ($recordsTable, $invoicesTable, $record, $confirmedBy) {
            $previousStatus = $record->status;
            $record->status = PettyCashConstants::STATUS_PAGADA;
            if (!$recordsTable->save($record)) {
                return false;
            }

            $this->history->recordStatusChange(
                $record->id,
                $previousStatus,
                $record->status,
                $confirmedBy,
            );

            $childInvoices = $invoicesTable->find()
                ->where([
                    'petty_cash_record_id' => $record->id,
                    'pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO,
                ])
                ->all();

            foreach ($childInvoices as $invoice) {
                $invoicePreviousStatus = $invoice->pipeline_status;
                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
                $this->invoiceHistory->recordStatusChange(
                    $invoice->id,
                    $invoicePreviousStatus,
                    InvoiceConstants::STATUS_PAGADA,
                    $confirmedBy,
                );
                if ($this->events !== null) {
                    $this->events->dispatch(new Event(
                        'Invoice.paid',
                        null,
                        ['payload' => new InvoicePaidEvent($invoice, $confirmedBy)],
                    ));
                }
            }

            return true;
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo confirmar el pago.');
        }

        return ServiceResult::ok('Pago confirmado. El registro y sus facturas quedaron como pagados.');
    }
}
