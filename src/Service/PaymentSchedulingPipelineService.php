<?php
declare(strict_types=1);

namespace App\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Event\InvoicePaidEvent;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineStateRegistry;
use App\ValueObject\UserContext;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;

class PaymentSchedulingPipelineService
{
    private AuthorizationFacade $auth;
    private PaymentSchedulingPipelineStateRegistry $stateRegistry;
    private InvoiceHistoryService $historyService;
    private PaymentSchedulingHistoryService $schedulingHistory;
    private ?EventManagerInterface $events;

    /**
     * @param \App\Service\InvoicePaymentService $paymentService Servicio de pagos de facturas.
     * @param \App\Authorization\AuthorizationFacade $auth Fachada de autorización de pasos de pipeline.
     * @param \App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineStateRegistry|null $stateRegistry Registro de estados del pipeline.
     * @param \App\Service\InvoiceHistoryService|null $historyService Servicio de auditoría de facturas.
     * @param \Cake\Event\EventManagerInterface|null $events Event manager para publicar eventos.
     * @param \App\Service\PaymentSchedulingHistoryService|null $schedulingHistory Servicio de auditoría de programaciones.
     */
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        AuthorizationFacade $auth,
        ?PaymentSchedulingPipelineStateRegistry $stateRegistry = null,
        ?InvoiceHistoryService $historyService = null,
        ?EventManagerInterface $events = null,
        ?PaymentSchedulingHistoryService $schedulingHistory = null,
    ) {
        $this->auth = $auth;
        $this->stateRegistry = $stateRegistry ?? new PaymentSchedulingPipelineStateRegistry();
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->schedulingHistory = $schedulingHistory ?? new PaymentSchedulingHistoryService();
        $this->events = $events ?? EventManager::instance();
    }

    /**
     * Retorna los estados del pipeline que el rol puede operar.
     *
     * @param int $roleId Id del rol.
     * @return array
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
        );
    }

    /**
     * Resuelve el siguiente estado del pipeline, o null si es terminal.
     *
     * @param string $currentStatus Estado actual.
     * @return string|null
     */
    public function getNextStatus(string $currentStatus): ?string
    {
        return PipelineStatus::tryFrom($currentStatus)?->next()?->value;
    }

    /**
     * Resuelve el estado anterior del pipeline, o null si es el primero.
     *
     * @param string $currentStatus Estado actual.
     * @return string|null
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PipelineStatus::tryFrom($currentStatus)?->previous()?->value;
    }

    /**
     * Retorna el motivo por el que la programación no puede avanzar, o null si puede.
     */
    public function denialReasonForAdvance(PaymentScheduling $scheduling, int $roleId): ?DenialReason
    {
        if ($this->getNextStatus($scheduling->pipeline_status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
                $scheduling->pipeline_status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Retorna el motivo por el que la programación no puede regresar, o null si puede.
     */
    public function denialReasonForRegress(PaymentScheduling $scheduling, int $roleId): ?DenialReason
    {
        if ($this->getPreviousStatus($scheduling->pipeline_status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
                $scheduling->pipeline_status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Valida los requisitos de avance de la programación desde un estado.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pago.
     * @param string $fromStatus Estado de origen.
     * @return array
     */
    public function validateTransitionRequirements(PaymentScheduling $scheduling, string $fromStatus): array
    {
        $fromEnum = PipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return ["Estado de origen inválido: {$fromStatus}"];
        }

        return $this->stateRegistry->get($fromEnum)->validateAdvance($scheduling);
    }

    /**
     * Retorna el mensaje de bloqueo de regresión, o null si puede regresar.
     *
     * @param \App\Model\Entity\PaymentScheduling $scheduling Programación de pago.
     * @return string|null
     */
    public function getRegressionLockMessage(PaymentScheduling $scheduling): ?string
    {
        return null;
    }

    /**
     * Avanza la programación al siguiente estado del pipeline.
     *
     * Si el avance materializa pagos (autorizacion_pago → verificacion_pago), crea
     * los invoice_payments, mueve las facturas hijas, guarda el nuevo estado y
     * registra el historial — TODO en una sola transacción. Antes esta orquestación
     * vivía en el controller con el save del estado FUERA del transactional de
     * applyPayments, dejando una ventana de inconsistencia.
     *
     * @return \App\Service\ServiceResult data{nextStatus: string, advanced: list<int>, partial: list<int>}
     */
    public function advance(PaymentScheduling $scheduling, int $roleId, int $userId): ServiceResult
    {
        $advanceDenial = $this->denialReasonForAdvance($scheduling, $roleId);
        if ($advanceDenial !== null) {
            return ServiceResult::fail([$advanceDenial->message()]);
        }

        $currentStatus = $scheduling->pipeline_status;
        $errors = $this->validateTransitionRequirements($scheduling, $currentStatus);
        if (!empty($errors)) {
            return ServiceResult::fail($errors);
        }

        $nextStatus = $this->getNextStatus($currentStatus);
        if ($nextStatus === null) {
            return ServiceResult::fail(['La programación ya está en el último paso del flujo.']);
        }

        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

        $advanced = [];
        $partial = [];
        $applyErrors = [];

        $ok = $schedulingsTable->getConnection()->transactional(function () use (
            $schedulingsTable,
            $scheduling,
            $currentStatus,
            $nextStatus,
            $userId,
            &$advanced,
            &$partial,
            &$applyErrors,
        ): bool {
            // Si avanza a verificacion_pago (Contador autoriza), materializar pagos
            // y mover las facturas hijas dentro de ESTA transacción.
            if ($nextStatus === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO) {
                $result = $this->_applyPayments($scheduling, $userId);
                if (!empty($result['errors'])) {
                    $applyErrors = $result['errors'];

                    return false;
                }
                $advanced = $result['advanced'];
                $partial = $result['partial'];
            }

            $scheduling->pipeline_status = $nextStatus;
            if (!$schedulingsTable->save($scheduling)) {
                return false;
            }

            $this->schedulingHistory->recordStatusChange(
                $scheduling->id,
                $currentStatus,
                $nextStatus,
                $userId,
            );

            return true;
        });

        if (!$ok) {
            return ServiceResult::fail($applyErrors ?: ['No se pudo avanzar la programación.']);
        }

        return ServiceResult::ok([
            'nextStatus' => $nextStatus,
            'advanced' => $advanced,
            'partial' => $partial,
        ]);
    }

    /**
     * Cold regression — only changes pipeline_status, doesn't touch items or payments.
     */
    public function regress(
        PaymentScheduling $scheduling,
        int $roleId,
        int $userId,
        string $reason,
    ): ServiceResult {
        $reason = trim($reason);
        $currentStatus = $scheduling->pipeline_status;

        $regressDenial = $this->denialReasonForRegress($scheduling, $roleId);
        if ($regressDenial !== null) {
            $error = $regressDenial === DenialReason::TERMINAL_STATE
                ? 'Esta programación ya está en el primer paso del flujo.'
                : $regressDenial->message();

            return ServiceResult::fail([$error]);
        }

        $lock = $this->getRegressionLockMessage($scheduling);
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
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');
        $observationsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingObservations');

        $ok = $schedulingsTable->getConnection()->transactional(
            function () use (
                $schedulingsTable,
                $observationsTable,
                $scheduling,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
            ): bool {
                $scheduling->pipeline_status = $previousStatus;
                if (!$schedulingsTable->save($scheduling)) {
                    return false;
                }

                $this->schedulingHistory->recordStatusChange(
                    $scheduling->id,
                    $currentStatus,
                    $previousStatus,
                    $userId,
                );
                $this->schedulingHistory->recordFieldChange(
                    $scheduling->id,
                    'regression_reason',
                    null,
                    $reason,
                    $userId,
                );

                // verificacion_pago → aut_pago: deshacer applyPayments para que el
                // siguiente avance vuelva a generar invoice_payments y mover hijas.
                if (
                    $currentStatus === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO
                    && $previousStatus === PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO
                ) {
                    $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
                    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
                    $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

                    $childInvoiceIds = $itemsTable->find()
                        ->where(['payment_scheduling_id' => $scheduling->id])
                        ->all()
                        ->extract('invoice_id')
                        ->toList();

                    $invoicePaymentsTable->deleteAll(['payment_scheduling_id' => $scheduling->id]);

                    if (!empty($childInvoiceIds)) {
                        foreach (array_unique($childInvoiceIds) as $invoiceId) {
                            $this->paymentService->recalculatePaymentStatus((int)$invoiceId);
                            $invoice = $invoicesTable->get($invoiceId);
                            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
                            if (!$invoicesTable->save($invoice)) {
                                return false;
                            }
                        }
                    }
                }

                $observation = $observationsTable->newEntity([
                    'payment_scheduling_id' => $scheduling->id,
                    'user_id' => $userId,
                    'type' => PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION,
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
            return ServiceResult::fail(['No se pudo regresar la programación. Intente de nuevo.']);
        }

        return ServiceResult::ok(['previousStatus' => $previousStatus]);
    }

    /**
     * Rechazo del Contador: devuelve la programación a Tesorería para corrección.
     *
     * Transición atómica (estado + auditoría) en una sola transacción. La
     * autorización (canReject) la gatea el controller vía la ActionPolicy,
     * igual que confirmPayment.
     */
    public function reject(PaymentScheduling $scheduling, int $userId): ServiceResult
    {
        $fromStatus = $scheduling->pipeline_status;
        $toStatus = PaymentSchedulingConstants::REJECTION_TARGET;
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

        $ok = $schedulingsTable->getConnection()->transactional(
            function () use ($schedulingsTable, $scheduling, $fromStatus, $toStatus, $userId): bool {
                $scheduling->pipeline_status = $toStatus;
                if (!$schedulingsTable->save($scheduling)) {
                    return false;
                }

                $this->schedulingHistory->recordStatusChange(
                    $scheduling->id,
                    $fromStatus,
                    $toStatus,
                    $userId,
                );

                return true;
            },
        );

        if (!$ok) {
            return ServiceResult::fail(['No se pudo rechazar la programación.']);
        }

        return ServiceResult::ok(['fromStatus' => $fromStatus, 'toStatus' => $toStatus]);
    }

    /**
     * Vincula items validados a una programación, all-or-nothing.
     *
     * @param array<int, array{invoice_id: int, banking_entity_id: int, amount: mixed}> $validItems
     * @return \App\Service\ServiceResult data{linked: int}
     */
    public function linkItems(int $schedulingId, array $validItems): ServiceResult
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        $ok = $itemsTable->getConnection()->transactional(
            function () use ($itemsTable, $schedulingId, $validItems): bool {
                foreach ($validItems as $item) {
                    $entity = $itemsTable->newEntity([
                        'payment_scheduling_id' => $schedulingId,
                        'invoice_id' => $item['invoice_id'],
                        'banking_entity_id' => $item['banking_entity_id'],
                        'amount' => $item['amount'],
                    ]);

                    if (!$itemsTable->save($entity)) {
                        return false;
                    }
                }

                return true;
            },
        );

        if (!$ok) {
            return ServiceResult::fail(['No se pudieron vincular todas las facturas.']);
        }

        return ServiceResult::ok(['linked' => count($validItems)]);
    }

    /**
     * Materializa los pagos autorizados de una programación y mueve las facturas
     * hijas (a verificacion_pago si quedan full, o las deja en tesorería si parcial).
     *
     * DEBE invocarse dentro de una transacción (no abre la suya): cualquier fallo
     * retorna un error y deja que la transacción del caller — advance() — revierta.
     *
     * @return array{errors: list<string>, advanced: list<int>, partial: list<int>}
     */
    private function _applyPayments(PaymentScheduling $scheduling, int $authorizedBy): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $items = $itemsTable->find()
            ->where(['payment_scheduling_id' => $scheduling->id])
            ->all();

        $appliedInvoiceIds = [];
        foreach ($items as $item) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $item->invoice_id,
                'banking_entity_id' => $item->banking_entity_id,
                'amount' => $item->amount,
                'payment_date' => date('Y-m-d'),
                'payment_scheduling_id' => $scheduling->id,
                'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                'authorized' => true,
                'authorized_by' => $authorizedBy,
                'authorized_date' => date('Y-m-d'),
                'created_by' => $scheduling->created_by,
            ]);

            if (!$paymentsTable->save($payment)) {
                return [
                    'errors' => ["No se pudo crear pago para factura ID {$item->invoice_id}"],
                    'advanced' => [],
                    'partial' => [],
                ];
            }

            $appliedInvoiceIds[] = $item->invoice_id;
        }

        $advanced = [];
        $partial = [];
        foreach (array_unique($appliedInvoiceIds) as $invoiceId) {
            $this->paymentService->recalculatePaymentStatus($invoiceId);

            $invoice = $invoicesTable->get($invoiceId);
            if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
                $invoice->pipeline_status = InvoiceConstants::STATUS_VERIFICACION_PAGO;
                $invoicesTable->save($invoice);
                $advanced[] = $invoiceId;
            } else {
                $partial[] = $invoiceId;
            }
        }

        return ['errors' => [], 'advanced' => $advanced, 'partial' => $partial];
    }

    /**
     * Calcula el monto total de una programación.
     */
    public function calculateTotal(int $schedulingId): float
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        return (float)$itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all()
            ->sumOf('amount');
    }

    /**
     * Confirma que los pagos de la programación efectivamente se ejecutaron.
     * Avanza el scheduling y todas sus facturas hijas de verificacion_pago → pagada,
     * recalcula payment_status, registra historial y dispara InvoicePaidEvent por
     * cada hija. Todo en una sola transacción.
     */
    public function confirmPayment(int $schedulingId, int $confirmedBy): ServiceResult
    {
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $scheduling = $schedulingsTable->get($schedulingId);
        if ($scheduling->pipeline_status !== PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO) {
            return ServiceResult::fail('La programación no está en verificación de pago.');
        }

        $skipped = [];
        $connection = $schedulingsTable->getConnection();
        $ok = $connection->transactional(function () use (
            $schedulingsTable,
            $invoicesTable,
            $paymentsTable,
            $scheduling,
            $schedulingId,
            $confirmedBy,
            &$skipped,
        ) {
            $scheduling->pipeline_status = PaymentSchedulingConstants::STATUS_PAGADA;
            if (!$schedulingsTable->save($scheduling)) {
                return false;
            }

            $this->schedulingHistory->recordStatusChange(
                $schedulingId,
                PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,
                PaymentSchedulingConstants::STATUS_PAGADA,
                $confirmedBy,
            );

            $childIds = $paymentsTable->find()
                ->select(['invoice_id'])
                ->where(['payment_scheduling_id' => $schedulingId])
                ->distinct(['invoice_id'])
                ->all()
                ->extract('invoice_id')
                ->toList();

            foreach ($childIds as $invoiceId) {
                if (!$this->paymentService->recalculatePaymentStatus((int)$invoiceId)) {
                    return false;
                }
                $refreshed = $invoicesTable->get($invoiceId);
                if ($refreshed->pipeline_status !== InvoiceConstants::STATUS_VERIFICACION_PAGO) {
                    // Inconsistencia: la hija no está donde la dejó applyPayments.
                    // Posibles causas: pago rechazado entre tanto, o intervención
                    // manual. Preservamos el estado actual y reportamos al caller.
                    $skipped[] = (int)$invoiceId;
                    continue;
                }
                $previousStatus = $refreshed->pipeline_status;
                $refreshed->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                if (!$invoicesTable->save($refreshed)) {
                    return false;
                }
                $this->historyService->recordStatusChange(
                    $refreshed->id,
                    $previousStatus,
                    InvoiceConstants::STATUS_PAGADA,
                    $confirmedBy,
                );
                if ($this->events !== null) {
                    $this->events->dispatch(new Event(
                        'Invoice.paid',
                        null,
                        ['payload' => new InvoicePaidEvent($refreshed, $confirmedBy)],
                    ));
                }
            }

            return true;
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo confirmar la programación.');
        }

        if (!empty($skipped)) {
            $list = implode(', ', array_map(static fn(int $id): string => '#' . $id, $skipped));

            return ServiceResult::ok(sprintf(
                'Programación confirmada. Atención: %d factura(s) (%s) no estaban en verificación de pago y se conservaron en su estado actual.',
                count($skipped),
                $list,
            ));
        }

        return ServiceResult::ok('Programación confirmada. Las facturas quedaron como pagadas.');
    }
}
