<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Refund;
use App\Service\Dto\RefundSyntheticPayment;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Trait\RefundPipelineHelpersTrait;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use DateTimeInterface;

class RefundService
{
    use RefundPipelineHelpersTrait;

    // Active refund statuses (excludes pagado — terminal para "Mis Registros").
    private const ACTIVE_STATUSES = [
        RefundConstants::STATUS_AGRUPACION,
        RefundConstants::STATUS_CONTABILIDAD,
        RefundConstants::STATUS_TESORERIA,
        RefundConstants::STATUS_AUT_PAGO,
    ];

    // Which refund statuses each role sees in "Mis Registros".
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION => [RefundConstants::STATUS_AGRUPACION],
        RoleConstants::CONTABILIDAD => [RefundConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA => [
            RefundConstants::STATUS_TESORERIA,
            RefundConstants::STATUS_AUT_PAGO,
        ],
        RoleConstants::CONTADOR => [RefundConstants::STATUS_AUT_PAGO],
        RoleConstants::AUXILIAR_PERSONAL => self::ACTIVE_STATUSES,
        RoleConstants::ASISTENTE_PERSONAL => self::ACTIVE_STATUSES,
        RoleConstants::COORDINADOR_ADMIN => self::ACTIVE_STATUSES,
        RoleConstants::ADMIN => self::ACTIVE_STATUSES,
    ];

    private GroupedInvoiceService $grouped;
    private RefundHistoryService $refundHistory;

    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService History service for child invoices.
     * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth Pipeline authorization service.
     * @param \App\Service\RefundHistoryService|null $refundHistory Refund-specific audit trail.
     */
    public function __construct(
        HistoryServiceInterface $historyService,
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?RefundHistoryService $refundHistory = null,
    ) {
        $this->grouped = new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
            fkField: 'refund_id',
            recordTableName: 'Refunds',
            fkLabel: 'Reintegro',
            historyService: $historyService,
        );
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->refundHistory = $refundHistory ?? new RefundHistoryService();
    }

    /**
     * Get refund statuses visible to a role in "Mis Registros".
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
     * @param \App\Model\Entity\Refund $record Record.
     * @param int $userId User ID.
     * @param int $roleId Role ID (para enforcement RBAC).
     * @return array{success: bool, error?: string, nextStatus?: string}
     */
    public function advanceStatus(Refund $record, int $userId, int $roleId): array
    {
        $currentStatus = $record->status;
        $nextStatus = RefundConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        if ($nextStatus === RefundConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'Debe registrar un pago para avanzar desde Tesorería.'];
        }

        if ($currentStatus === RefundConstants::STATUS_AUT_PAGO) {
            return ['success' => false, 'error' => 'La autorización de pago se gestiona desde la sección de pagos.'];
        }

        if (!$this->_canOperate($roleId, $currentStatus)) {
            return ['success' => false, 'error' => 'No tiene permisos para avanzar este registro.'];
        }

        $validationErrors = $this->_validateTransition($currentStatus, $record);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'No se puede avanzar. ' . implode('. ', $validationErrors),
            ];
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
                $finalResult = [
                    'success' => false,
                    'error' => 'El registro fue modificado por otro usuario. Recargue la página.',
                ];

                return false;
            }

            // Conteo de facturas hijas DENTRO del lock — evita TOCTOU si una
            // request concurrente desvinculó la última factura entre el chequeo
            // inicial y el FOR UPDATE.
            $hasInvoices = $invoicesTable->find()
                ->where([$fkField => $record->id])
                ->count() > 0;
            if (!$hasInvoices) {
                $finalResult = [
                    'success' => false,
                    'error' => 'El registro debe tener al menos una factura agrupada.',
                ];

                return false;
            }

            $today = self::_today();
            $updateData = [];

            if ($nextStatus === RefundConstants::STATUS_CONTABILIDAD) {
                $updateData = [
                    'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
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
                $finalResult = [
                    'success' => false,
                    'error' => self::_buildSaveErrorMessage(
                        'No se pudo guardar el avance del registro.',
                        $locked->getErrors(),
                    ),
                ];

                return false;
            }
            $record->status = $nextStatus;
            $this->refundHistory->recordStatusChange($record->id, $currentStatus, $nextStatus, $userId);

            $finalResult = ['success' => true, 'nextStatus' => $nextStatus];

            return true;
        });

        return $finalResult ?? ['success' => false, 'error' => 'No se pudo guardar el avance del registro.'];
    }

    /**
     * Construye una representación uniforme del pago bulk del registro para
     * que la vista pueda reusar el element compartido `payment_section`.
     *
     * Reintegro guarda un único pago como columnas en la tabla `refunds` (no
     * tiene tabla de pagos propia); este método materializa esas columnas en
     * la forma que espera el element.
     *
     * @return array<int, \App\Service\Dto\RefundSyntheticPayment> 0 o 1 elementos.
     */
    public function buildSyntheticPayments(Refund $record): array
    {
        if (empty($record->banking_entity_id)) {
            return [];
        }

        $isAuthorized = $record->isPagado();

        return [
            new RefundSyntheticPayment(
                id: $record->id,
                banking_entity: $record->banking_entity ?? null,
                amount: $record->payment_amount,
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
    public function getTransitionErrors(Refund $record): array
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
     * Validate refund specific transition requirements.
     *
     * @param string $fromStatus Current status.
     * @param \App\Model\Entity\Refund $record Record.
     * @return array
     */
    private function _validateTransition(string $fromStatus, Refund $record): array
    {
        $errors = [];

        switch ($fromStatus) {
            case RefundConstants::STATUS_AGRUPACION:
                $type = $record->beneficiary_type;
                if ($type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
                    if (empty($record->beneficiary_employee_id)) {
                        $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
                    }
                } elseif ($type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
                    if (empty($record->beneficiary_provider_id)) {
                        $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
                    }
                } else {
                    $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
                }
                break;

            case RefundConstants::STATUS_CONTABILIDAD:
                if (empty($record->accrued)) {
                    $errors[] = 'El registro debe estar marcado como Causado.';
                }
                if (empty($record->ready_for_payment)) {
                    $errors[] = 'Debe seleccionar "Lista para Pago".';
                }
                break;

            case RefundConstants::STATUS_TESORERIA:
                break;
        }

        return $errors;
    }

    /**
     * Returns the previous pipeline status, or null if no predecessor exists
     * or the state is excluded from regression.
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return RefundConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    /**
     * Returns true if the role can regress the record from the current status.
     */
    public function canRegress(int $roleId, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->_canOperate($roleId, $currentStatus);
    }

    /**
     * Returns a human-readable lock message preventing regression, or null if allowed.
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        // Único bloqueo: tesoreria → contabilidad con pago pendiente registrado.
        if (
            $record->status === RefundConstants::STATUS_TESORERIA
            && !empty($record->payment_amount)
        ) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado.'
                . ' Anule o reasigne el pago primero.';
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
        Refund $record,
        int $roleId,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $record->status;

        if (!$this->canRegress($roleId, $currentStatus)) {
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
        $recordsTable = TableRegistry::getTableLocator()->get('Refunds');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('RefundObservations');
        $fkField = $this->grouped->getFkField();

        // Map child invoice status. agrupacion no aplica (hijas sin pipeline alineado).
        // aut_pago tampoco aplica (en avance tesoreria→aut_pago las hijas no cambiaron).
        $childPipelineMap = [
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
                    $finalResult = [
                        'success' => false,
                        'error' => 'El registro fue modificado por otro usuario. Recargue la página.',
                        'previousStatus' => null,
                    ];

                    return false;
                }
                $locked->status = $previousStatus;
                if (!$recordsTable->save($locked)) {
                    $finalResult = [
                        'success' => false,
                        'error' => self::_buildSaveErrorMessage(
                            'No se pudo regresar el registro.',
                            $locked->getErrors(),
                        ),
                        'previousStatus' => null,
                    ];

                    return false;
                }
                $record->status = $previousStatus;
                $this->refundHistory->recordStatusChange($record->id, $currentStatus, $previousStatus, $userId);

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
                    $finalResult = [
                        'success' => false,
                        'error' => 'No se pudo registrar la observación de regresión.',
                        'previousStatus' => null,
                    ];

                    return false;
                }

                $finalResult = [
                    'success' => true,
                    'error' => null,
                    'previousStatus' => $previousStatus,
                ];

                return true;
            },
        );

        return $finalResult ?? [
            'success' => false,
            'error' => 'No se pudo regresar el registro. Intente de nuevo.',
            'previousStatus' => null,
        ];
    }
}
