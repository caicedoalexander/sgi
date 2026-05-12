<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Event\InvoicePaidEvent;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineStateRegistry;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;

class PaymentSchedulingService
{
    private PipelineAuthorizationService $pipelineAuth;
    private PaymentSchedulingPipelineStateRegistry $stateRegistry;
    private InvoiceHistoryService $historyService;
    private ?EventManagerInterface $events;

    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?PaymentSchedulingPipelineStateRegistry $stateRegistry = null,
        ?InvoiceHistoryService $historyService = null,
        ?EventManagerInterface $events = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->stateRegistry = $stateRegistry ?? new PaymentSchedulingPipelineStateRegistry();
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->events = $events;
    }

    public function getVisibleStatuses(int $roleId): array
    {
        return $this->pipelineAuth->getOperableSteps(
            $roleId,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
        );
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::FORWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function canAdvance(int $roleId, string $currentStatus): bool
    {
        $stub = new PaymentScheduling(['pipeline_status' => $currentStatus]);
        $stub->setNew(false);

        return $this->denialReasonForAdvance($stub, $roleId) === null;
    }

    public function canReject(int $roleId, string $currentStatus): bool
    {
        if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function canRegress(int $roleId, string $currentStatus): bool
    {
        $stub = new PaymentScheduling(['pipeline_status' => $currentStatus]);
        $stub->setNew(false);

        return $this->denialReasonForRegress($stub, $roleId) === null;
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
            !$this->pipelineAuth->canOperate(
                $roleId,
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
            !$this->pipelineAuth->canOperate(
                $roleId,
                PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
                $scheduling->pipeline_status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    public function validateTransitionRequirements(PaymentScheduling $scheduling, string $fromStatus): array
    {
        $fromEnum = PipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return ["Estado de origen inválido: {$fromStatus}"];
        }

        return $this->stateRegistry->get($fromEnum)->validateAdvance($scheduling);
    }

    public function getRegressionLockMessage(PaymentScheduling $scheduling): ?string
    {
        return null;
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

        if (!$this->canRegress($roleId, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta programación ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta programación.';

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
     * Vincula items validados a una programación.
     */
    public function linkItems(int $schedulingId, array $validItems): bool
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

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
    }

    /**
     * Aplica los pagos de una programación autorizada.
     */
    public function applyPayments(int $schedulingId, int $authorizedBy): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

        $scheduling = $schedulingsTable->get($schedulingId);
        $items = $itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all();

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $items,
            $paymentsTable,
            $invoicesTable,
            $scheduling,
            $schedulingId,
            $authorizedBy,
        ) {
            $appliedInvoiceIds = [];
            $errors = [];

            foreach ($items as $item) {
                $payment = $paymentsTable->newEntity([
                    'invoice_id' => $item->invoice_id,
                    'banking_entity_id' => $item->banking_entity_id,
                    'amount' => $item->amount,
                    'payment_date' => date('Y-m-d'),
                    'payment_scheduling_id' => $schedulingId,
                    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                    'authorized' => true,
                    'authorized_by' => $authorizedBy,
                    'authorized_date' => date('Y-m-d'),
                    'created_by' => $scheduling->created_by,
                ]);

                if (!$paymentsTable->save($payment)) {
                    $errors[] = "No se pudo crear pago para factura ID {$item->invoice_id}";
                    continue;
                }

                $appliedInvoiceIds[] = $item->invoice_id;
            }

            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors, 'advanced_to_pagada' => [], 'partial_payment' => []];
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

            return [
                'success' => true,
                'errors' => [],
                'advanced_to_pagada' => $advanced,
                'partial_payment' => $partial,
            ];
        });
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
