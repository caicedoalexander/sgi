<?php
declare(strict_types=1);

namespace App\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\PettyCash\PipelineStatus as PettyCashPipelineStatus;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Dto\BulkPaymentView;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\PettyCash\PettyCashPipelineStateRegistry;
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
use App\Service\Pipeline\PettyCash\Policy\PettyCashLockPolicy;
use App\ValueObject\UserContext;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use DateTimeInterface;

class PettyCashPipelineService
{
    private GroupedInvoiceService $grouped;
    private AuthorizationFacade $auth;
    private PettyCashFieldAccessPolicy $fieldPolicy;
    private PettyCashHistoryService $history;
    private PettyCashPipelineStateRegistry $stateRegistry;
    private PettyCashLockPolicy $lockPolicy;

    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service for child invoices.
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     * @param \App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy $fieldPolicy Field access policy.
     * @param \App\Service\PettyCashHistoryService|null $history Audit trail for the petty cash record itself.
     * @param \App\Service\Pipeline\PettyCash\PettyCashPipelineStateRegistry|null $stateRegistry State registry.
     * @param \App\Service\Pipeline\PettyCash\Policy\PettyCashLockPolicy|null $lockPolicy Regression lock policy (mirror de InvoiceLockPolicy).
     */
    public function __construct(
        HistoryServiceInterface $historyService,
        AuthorizationFacade $auth,
        PettyCashFieldAccessPolicy $fieldPolicy,
        ?PettyCashHistoryService $history = null,
        ?PettyCashPipelineStateRegistry $stateRegistry = null,
        ?PettyCashLockPolicy $lockPolicy = null,
    ) {
        $this->grouped = new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
            fkField: 'petty_cash_record_id',
            recordTableName: 'PettyCashRecords',
            fkLabel: 'Caja Menor',
            historyService: $historyService,
        );
        $this->auth = $auth;
        $this->fieldPolicy = $fieldPolicy;
        $this->history = $history ?? new PettyCashHistoryService();
        $this->stateRegistry = $stateRegistry ?? new PettyCashPipelineStateRegistry();
        $this->lockPolicy = $lockPolicy ?? new PettyCashLockPolicy();
    }

    /**
     * Get petty-cash statuses visible to a role in "Mis Registros".
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
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
     * Registra en el historial del record padre la vinculación de un lote de
     * facturas (entrada resumen única con los invoice_number).
     *
     * @param int $recordId Record padre.
     * @param array<int> $invoiceIds Ids de las facturas vinculadas.
     * @param int $userId Usuario que vincula.
     */
    private function _recordInvoicesLinked(int $recordId, array $invoiceIds, int $userId): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        $numbers = TableRegistry::getTableLocator()->get('Invoices')->find()
            ->select(['invoice_number'])
            ->where(['id IN' => $invoiceIds])
            ->all()
            ->extract('invoice_number')
            ->toList();

        $clean = array_values(array_filter(array_map('strval', $numbers), fn($n) => $n !== ''));
        if (empty($clean)) {
            return;
        }

        $summary = count($clean) === 1
            ? $clean[0]
            : sprintf('%d facturas (%s)', count($clean), implode(', ', $clean));

        $this->history->recordFieldChange($recordId, 'invoices_linked', null, $summary, $userId);
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
        $filtered = $this->fieldPolicy->filterEntityData($data, $roleId, (string)$record->status);
        if ($filtered->hasErrors()) {
            return ServiceResult::fail($filtered->errors);
        }

        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');

        if (!empty($filtered->patch)) {
            // patchEntity muta $record en sitio, así que snapshoteamos el estado
            // original (solo los campos auditables) ANTES de aplicar el patch
            // para poder registrar el diff campo a campo tras el save.
            $original = new PettyCashRecord([
                'id' => $record->id,
                'notes' => $record->notes,
                'accrued' => $record->accrued,
                'accrual_date' => $record->accrual_date,
                'ready_for_payment' => $record->ready_for_payment,
            ]);

            $record = $recordsTable->patchEntity($record, $filtered->patch);
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

            $this->history->recordChanges($original, $record, $userId);
        }

        $linkWarnings = [];
        if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
            $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
            if (!empty($invoiceIds)) {
                $linkWarnings = $this->addInvoices($record, $invoiceIds);
                if (empty($linkWarnings)) {
                    $this->_recordInvoicesLinked((int)$record->id, $invoiceIds, $userId);
                }
            }
        }

        $advanced = false;
        $nextStatus = null;
        $advanceWarning = null;
        if (!$record->isPagada() && $this->denialReasonForAdvance($record, $roleId) === null) {
            $advanceResult = $this->advance($record, $roleId, $userId);
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

    /**
     * Retorna el motivo por el que el registro no puede avanzar, o null si puede.
     */
    public function denialReasonForAdvance(PettyCashRecord $record, int $roleId): ?DenialReason
    {
        $currentEnum = PettyCashPipelineStatus::tryFrom($record->status);
        if ($currentEnum === null) {
            return DenialReason::TERMINAL_STATE;
        }

        $state = $this->stateRegistry->get($currentEnum);
        if ($state->getNextStatus() === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                $record->status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * @param \App\Model\Entity\PettyCashRecord $record Record.
     * @param int $roleId Role ID of the caller (for pipeline authorization).
     * @param int $userId User ID.
     * @return \App\Service\ServiceResult on success: data = ['nextStatus' => string]
     */
    public function advance(
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

        $advanceDenial = $this->denialReasonForAdvance($record, $roleId);
        if ($advanceDenial !== null) {
            return ServiceResult::fail($advanceDenial->message());
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
    public function validateTransitionRequirements(PettyCashRecord $record): array
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
     * Returns the next pipeline status, or null if the current state is terminal.
     *
     * Delega al State pattern: cada estado declara su sucesor.
     */
    public function getNextStatus(string $currentStatus): ?string
    {
        $currentEnum = PettyCashPipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        return $this->stateRegistry->get($currentEnum)->getNextStatus()?->value;
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

    /**
     * Retorna el motivo por el que el registro no puede regresar, o null si puede.
     */
    public function denialReasonForRegress(PettyCashRecord $record, int $roleId): ?DenialReason
    {
        if ($this->getPreviousStatus($record->status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PETTY_CASH,
                $record->status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Returns a human-readable lock message preventing regression, or null if allowed.
     */
    public function getRegressionLockMessage(PettyCashRecord $record): ?string
    {
        return $this->lockPolicy->getRegressionLockMessage($record);
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

        $regressDenial = $this->denialReasonForRegress($record, $roleId);
        if ($regressDenial !== null) {
            $error = $regressDenial === DenialReason::TERMINAL_STATE
                ? 'Este registro ya está en el primer paso del flujo.'
                : $regressDenial->message();

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
                $this->history->recordFieldChange($record->id, 'regression_reason', null, $reason, $userId);

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
}
