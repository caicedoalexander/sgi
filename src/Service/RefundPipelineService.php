<?php
declare(strict_types=1);

namespace App\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\Domain\Refund\PipelineStatus as RefundPipelineStatus;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Dto\BulkPaymentView;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\Refund\Policy\RefundFieldAccessPolicy;
use App\Service\Pipeline\Refund\Policy\RefundLockPolicy;
use App\Service\Pipeline\Refund\Policy\RefundTransitionValidator;
use App\Service\Pipeline\Refund\RefundPipelineStateRegistry;
use App\ValueObject\UserContext;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use DateTimeInterface;

class RefundPipelineService
{
    private GroupedInvoiceService $grouped;
    private RefundHistoryService $refundHistory;
    private RefundPipelineStateRegistry $stateRegistry;
    private RefundLockPolicy $lockPolicy;
    private RefundTransitionValidator $transitionValidator;
    private AuthorizationFacade $auth;
    private RefundFieldAccessPolicy $fieldPolicy;

    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service for child invoices.
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     * @param \App\Service\RefundHistoryService|null $refundHistory Refund-specific audit trail.
     * @param \App\Service\Pipeline\Refund\RefundPipelineStateRegistry|null $stateRegistry Pipeline states.
     * @param \App\Service\Pipeline\Refund\Policy\RefundLockPolicy|null $lockPolicy Regression lock policy (mirror de InvoiceLockPolicy).
     * @param \App\Service\Pipeline\Refund\Policy\RefundTransitionValidator|null $transitionValidator Advance requirements validator.
     * @param \App\Service\Pipeline\Refund\Policy\RefundFieldAccessPolicy|null $fieldPolicy Rol-aware field filter.
     */
    public function __construct(
        HistoryServiceInterface $historyService,
        AuthorizationFacade $auth,
        ?RefundHistoryService $refundHistory = null,
        ?RefundPipelineStateRegistry $stateRegistry = null,
        ?RefundLockPolicy $lockPolicy = null,
        ?RefundTransitionValidator $transitionValidator = null,
        ?RefundFieldAccessPolicy $fieldPolicy = null,
    ) {
        $this->grouped = new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
            fkField: 'refund_id',
            recordTableName: 'Refunds',
            fkLabel: 'Reintegro',
            historyService: $historyService,
            linkableStatus: InvoiceConstants::STATUS_APROBACION,
        );
        $this->auth = $auth;
        $this->fieldPolicy = $fieldPolicy ?? new RefundFieldAccessPolicy($this->auth);
        $this->refundHistory = $refundHistory ?? new RefundHistoryService();
        $this->stateRegistry = $stateRegistry ?? new RefundPipelineStateRegistry();
        $this->lockPolicy = $lockPolicy ?? new RefundLockPolicy();
        $this->transitionValidator = $transitionValidator ?? new RefundTransitionValidator($this->stateRegistry);
    }

    /**
     * Get refund statuses visible to a role in "Mis Registros".
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_REFUNDS,
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
     * @param \App\Model\Entity\Refund $record Record.
     * @param array $invoiceIds Invoice IDs.
     * @return array
     */
    public function addInvoices(Refund $record, array $invoiceIds): array
    {
        return $this->grouped->addInvoices($record, $invoiceIds);
    }

    /**
     * @param \App\Model\Entity\Refund $record Record.
     * @param int $invoiceId Invoice ID.
     * @return bool
     */
    public function removeInvoice(Refund $record, int $invoiceId): bool
    {
        return $this->grouped->removeInvoice($record, $invoiceId);
    }

    /**
     * @param \App\Model\Entity\Refund $record Record.
     * @return void
     */
    public function calculateAndSaveTotal(Refund $record): void
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
     * Guardar campos editables, vincular facturas nuevas e intentar avanzar el
     * pipeline en una operación coordinada. Espejo de
     * PettyCashPipelineService::saveAndAdvance / InvoicePipelineService::saveAndAdvance:
     * saca la orquestación del controller. Como en PettyCash, recordChanges y
     * advance() abren cada uno su transacción (no hay transacción raíz única).
     *
     * @param \App\Model\Entity\Refund $record Reintegro.
     * @param int $roleId Rol que opera.
     * @param int $userId Usuario que opera.
     * @param array $data Datos crudos del POST.
     * @return \App\Service\ServiceResult on success: data = [
     *   'advanced' => bool,
     *   'nextStatus' => ?string,
     *   'advanceWarning' => ?string,
     *   'linkWarnings' => string[],
     * ]
     */
    public function saveAndAdvance(
        Refund $record,
        int $roleId,
        int $userId,
        array $data,
    ): ServiceResult {
        $filtered = $this->fieldPolicy->filterEntityData($data, $roleId, (string)$record->status);
        if ($filtered->hasErrors()) {
            return ServiceResult::fail($filtered->errors);
        }

        if (!empty($filtered->patch)) {
            $recordsTable = TableRegistry::getTableLocator()->get('Refunds');

            // patchEntity muta $record en sitio, así que snapshoteamos el estado
            // original (solo los campos auditables) ANTES de aplicar el patch
            // para poder registrar el diff campo a campo tras el save.
            $original = new Refund([
                'id' => $record->id,
                'beneficiary_type' => $record->beneficiary_type,
                'beneficiary_employee_id' => $record->beneficiary_employee_id,
                'beneficiary_provider_id' => $record->beneficiary_provider_id,
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

            $this->refundHistory->recordChanges($original, $record, $userId);
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
     * Registra en el historial el lote de facturas vinculadas. Copia privada del
     * servicio (espejo de PettyCashPipelineService); el helper homónimo del
     * controller conserva otro caller (addInvoicesToGroup).
     *
     * @param int $refundId Reintegro padre.
     * @param array<int> $invoiceIds Ids de las facturas vinculadas.
     * @param int $userId Usuario que vincula.
     */
    private function _recordInvoicesLinked(int $refundId, array $invoiceIds, int $userId): void
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

        $this->refundHistory->recordFieldChange($refundId, 'invoices_linked', null, $summary, $userId);
    }

    /**
     * Returns the next pipeline status, or null if the current state is terminal.
     */
    public function getNextStatus(string $currentStatus): ?string
    {
        $currentEnum = RefundPipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        return $this->stateRegistry->get($currentEnum)->getNextStatus()?->value;
    }

    /**
     * Retorna el motivo por el que el reintegro no puede avanzar, o null si puede.
     *
     * Mapea las 4 condiciones de denial estructurales (sin contar validaciones
     * de campos del state, que se manejan separadamente en validateAdvance):
     * - TERMINAL_STATE: no hay transición forward desde el estado actual.
     * - REQUIRES_PAYMENT: el avance desde Tesorería requiere registrar un pago.
     * - MANAGED_ELSEWHERE: el avance desde Autorización de pago se gestiona desde pagos.
     * - UNAUTHORIZED: el rol no tiene permiso para operar el paso actual.
     */
    public function denialReasonForAdvance(Refund $refund, int $roleId): ?DenialReason
    {
        $currentStatus = $refund->status;
        $nextStatus = $this->getNextStatus($currentStatus);

        if ($nextStatus === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if ($nextStatus === RefundConstants::STATUS_AUTORIZACION_PAGO) {
            return DenialReason::REQUIRES_PAYMENT;
        }

        if ($currentStatus === RefundConstants::STATUS_AUTORIZACION_PAGO) {
            return DenialReason::MANAGED_ELSEWHERE;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_REFUNDS,
                $currentStatus,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Avanza el reintegro al siguiente estado del pipeline, propagando el estado a
     * las facturas hijas y registrando historial dentro de una transacción con lock.
     *
     * @param \App\Model\Entity\Refund $record Record.
     * @param int $userId User ID.
     * @param int $roleId Role ID (para enforcement RBAC).
     * @return \App\Service\ServiceResult data{nextStatus: string}
     */
    public function advance(Refund $record, int $roleId, int $userId): ServiceResult
    {
        $currentStatus = $record->status;

        $denial = $this->denialReasonForAdvance($record, $roleId);
        if ($denial !== null) {
            return ServiceResult::fail([$denial->message()]);
        }

        // Garantizado no-null por denialReasonForAdvance (TERMINAL_STATE descartado).
        $nextStatus = $this->getNextStatus($currentStatus);

        $validationErrors = $this->transitionValidator->validateAdvance($record);
        if (!empty($validationErrors)) {
            return ServiceResult::fail(['No se puede avanzar. ' . implode('. ', $validationErrors)]);
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $recordsTable = TableRegistry::getTableLocator()->get('Refunds');
        $fkField = $this->grouped->getFkField();
        $connection = $invoicesTable->getConnection();

        // Capturado por referencia: la closure retorna `false` para forzar
        // rollback en caso de error y dejamos el resultado final aquí.
        $finalResult = null;

        $connection->transactional(function () use (
            $record,
            $currentStatus,
            $nextStatus,
            $invoicesTable,
            $recordsTable,
            $fkField,
            $userId,
            &$finalResult,
        ): bool {
            // Lock pesimista + revalidación TOCTOU.
            $locked = $recordsTable->find()
                ->where(['id' => $record->id])
                ->epilog('FOR UPDATE')
                ->first();
            if ($locked === null || $locked->status !== $currentStatus) {
                $finalResult = ServiceResult::fail(
                    ['El registro fue modificado por otro usuario. Recargue la página.'],
                );

                return false;
            }

            // Conteo de facturas hijas DENTRO del lock — evita TOCTOU si una
            // request concurrente desvinculó la última factura entre el chequeo
            // inicial y el FOR UPDATE.
            $hasInvoices = $invoicesTable->find()
                ->where([$fkField => $record->id])
                ->count() > 0;
            if (!$hasInvoices) {
                $finalResult = ServiceResult::fail(
                    ['El registro debe tener al menos una factura agrupada.'],
                );

                return false;
            }

            $today = date('Y-m-d');
            $updateData = [];

            if ($nextStatus === RefundConstants::STATUS_CONTABILIDAD) {
                // Self-heal: replica el efecto de RefundApprovalService::onAllApproved
                // sobre area_approval/area_approval_date. contabilidad solo se
                // alcanza desde aprobacion, así que esto siempre es correcto aquí
                // aunque, por una condición de carrera rara, el gate de aprobación
                // (RefundApprovalGuard::allApproved, sobre filas) y el efecto de
                // onAllApproved hubieran divergido.
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                    'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
                    'area_approval_date' => new DateTime(),
                ];
            } elseif ($nextStatus === RefundConstants::STATUS_TESORERIA) {
                // Snapshot bajo lock — evita propagar valores stale si otro
                // proceso editó accrued/accrual_date/ready_for_payment entre
                // el get() inicial y el FOR UPDATE.
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
                    'accrued' => (bool)$locked->accrued,
                    'accrual_date' => $locked->accrual_date ?? $today,
                    'ready_for_payment' => $locked->ready_for_payment,
                ];
            }

            if (!empty($updateData)) {
                $invoicesBefore = $invoicesTable->find()
                    ->select(['id', 'pipeline_status'])
                    ->where([$fkField => $record->id])
                    ->all()
                    ->toArray();

                $invoicesTable->updateAll(
                    $updateData,
                    [$fkField => $record->id],
                );

                $newPipelineStatus = $updateData['pipeline_status'] ?? null;
                if ($newPipelineStatus) {
                    $this->grouped->recordBulkHistory($record->id, $invoicesBefore, $newPipelineStatus, $userId);
                }
            }

            $locked->status = $nextStatus;
            if (!$recordsTable->save($locked)) {
                $finalResult = ServiceResult::fail([
                    self::_buildSaveErrorMessage(
                        'No se pudo guardar el avance del registro.',
                        $locked->getErrors(),
                    ),
                ]);

                return false;
            }
            $record->status = $nextStatus;
            $this->refundHistory->recordStatusChange($record->id, $currentStatus, $nextStatus, $userId);

            $finalResult = ServiceResult::ok(['nextStatus' => $nextStatus]);

            return true;
        });

        return $finalResult ?? ServiceResult::fail(['No se pudo guardar el avance del registro.']);
    }

    /**
     * Construye una representación uniforme del pago bulk del registro para
     * que la vista pueda reusar el element compartido `payment_section`.
     *
     * Reintegro guarda un único pago como columnas en la tabla `refunds` (no
     * tiene tabla de pagos propia); este método materializa esas columnas en
     * la forma que espera el element.
     *
     * @return array<int, \App\Service\Dto\BulkPaymentView> 0 o 1 elementos.
     */
    public function buildSyntheticPayments(Refund $record): array
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
     * Normaliza un valor de fecha a un DateTimeInterface o null. Acepta
     * instancias DateTimeInterface (passthrough) o strings ISO no vacíos.
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
     * Get transition validation errors for the current record (used by views).
     *
     * @param \App\Model\Entity\Refund $record Record.
     * @return array
     */
    public function validateTransitionRequirements(Refund $record): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $hasInvoices = $invoicesTable->find()
            ->where([$this->grouped->getFkField() => $record->id])
            ->count() > 0;

        if (!$hasInvoices) {
            return ['El registro debe tener al menos una factura agrupada.'];
        }

        return $this->transitionValidator->validateAdvance($record);
    }

    /**
     * Returns the previous pipeline status, or null if no predecessor exists
     * or the state is excluded from regression.
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        $currentEnum = RefundPipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        return $this->stateRegistry->get($currentEnum)->getPreviousStatus()?->value;
    }

    /**
     * Retorna el motivo por el que el reintegro no puede regresar, o null si puede.
     */
    public function denialReasonForRegress(Refund $refund, int $roleId): ?DenialReason
    {
        if ($this->getPreviousStatus($refund->status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_REFUNDS,
                $refund->status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Returns a human-readable lock message preventing regression, or null if allowed.
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        return $this->lockPolicy->getRegressionLockMessage($record);
    }

    /**
     * Regress the record to its previous pipeline status.
     * Propagates the change to child invoices for contabilidad and tesoreria,
     * and stores the reason as a typed observation.
     *
     * @return \App\Service\ServiceResult data{previousStatus: string}
     */
    public function regress(
        Refund $record,
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

            return ServiceResult::fail([$error]);
        }

        $lock = $this->getRegressionLockMessage($record);
        if ($lock !== null) {
            return ServiceResult::fail([$lock]);
        }

        if (mb_strlen($reason) < 10) {
            return ServiceResult::fail(['El motivo es obligatorio (mínimo 10 caracteres).']);
        }
        if (mb_strlen($reason) > 500) {
            return ServiceResult::fail(['El motivo no puede superar 500 caracteres.']);
        }

        $previousStatus = $this->getPreviousStatus($currentStatus);
        $recordsTable = TableRegistry::getTableLocator()->get('Refunds');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('RefundObservations');
        $fkField = $this->grouped->getFkField();

        // Map child invoice status. agrupacion no aplica (hijas sin pipeline alineado).
        // aut_pago tampoco aplica (en avance tesoreria→aut_pago las hijas no cambiaron).
        $childPipelineMap = [
            RefundConstants::STATUS_APROBACION => InvoiceConstants::STATUS_APROBACION,
            RefundConstants::STATUS_CONTABILIDAD => InvoiceConstants::STATUS_CONTABILIDAD,
            RefundConstants::STATUS_TESORERIA => InvoiceConstants::STATUS_TESORERIA,
        ];

        $finalResult = null;

        $invoicesTable->getConnection()->transactional(
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
                &$finalResult,
            ): bool {
                // Lock pesimista + revalidación TOCTOU.
                $locked = $recordsTable->find()
                    ->where(['id' => $record->id])
                    ->epilog('FOR UPDATE')
                    ->first();
                if ($locked === null || $locked->status !== $currentStatus) {
                    $finalResult = ServiceResult::fail(
                        ['El registro fue modificado por otro usuario. Recargue la página.'],
                    );

                    return false;
                }
                $locked->status = $previousStatus;
                if (!$recordsTable->save($locked)) {
                    $finalResult = ServiceResult::fail([
                        self::_buildSaveErrorMessage(
                            'No se pudo regresar el registro.',
                            $locked->getErrors(),
                        ),
                    ]);

                    return false;
                }
                $record->status = $previousStatus;
                $this->refundHistory->recordStatusChange($record->id, $currentStatus, $previousStatus, $userId);
                $this->refundHistory->recordFieldChange($record->id, 'regression_reason', null, $reason, $userId);

                // verificacion_pago → autorizacion_pago: deshacer la materialización
                // que hizo RefundPaymentService::authorizePayment para que el Contador
                // pueda re-revisar y re-autorizar.
                if (
                    $currentStatus === RefundConstants::STATUS_VERIFICACION_PAGO
                    && $previousStatus === RefundConstants::STATUS_AUTORIZACION_PAGO
                ) {
                    $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
                    $invoicePaymentsTable->deleteAll(['refund_id' => $record->id]);

                    $invoicesTable->updateAll(
                        [
                            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
                            'payment_status' => null,
                            'full_payment_date' => null,
                        ],
                        [$fkField => $record->id],
                    );

                    $locked->payment_status = null;
                    $locked->payment_authorized_by = null;
                    $locked->payment_authorized_date = null;
                    if (!$recordsTable->save($locked)) {
                        $finalResult = ServiceResult::fail([
                            self::_buildSaveErrorMessage(
                                'No se pudo revertir la autorización del pago.',
                                $locked->getErrors(),
                            ),
                        ]);

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
                    'refund_id' => $record->id,
                    'user_id' => $userId,
                    'type' => RefundConstants::OBSERVATION_TYPE_REGRESSION,
                    'message' => $reason,
                    'metadata' => [
                        'from_status' => $currentStatus,
                        'to_status' => $previousStatus,
                    ],
                ]);

                if (!$observationsTable->save($observation)) {
                    $finalResult = ServiceResult::fail(
                        ['No se pudo registrar la observación de regresión.'],
                    );

                    return false;
                }

                $finalResult = ServiceResult::ok(['previousStatus' => $previousStatus]);

                return true;
            },
        );

        return $finalResult ?? ServiceResult::fail(
            ['No se pudo regresar el registro. Intente de nuevo.'],
        );
    }

    /**
     * Builds a readable error message including entity validation details.
     *
     * @param string $base Base message.
     * @param array $entityErrors Errors returned by `Entity::getErrors()`.
     */
    private static function _buildSaveErrorMessage(string $base, array $entityErrors): string
    {
        $details = [];
        foreach ($entityErrors as $field => $fieldErrors) {
            foreach ((array)$fieldErrors as $msg) {
                if (is_string($msg) && $msg !== '') {
                    $details[] = sprintf('%s: %s', $field, $msg);
                }
            }
        }

        return empty($details) ? $base : ($base . ' ' . implode(', ', $details));
    }
}
