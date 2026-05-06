<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineStateRegistry;
use Cake\ORM\TableRegistry;

class PaymentSchedulingService
{
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::TESORERIA => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::CONTADOR => [
            PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::ADMIN => PaymentSchedulingConstants::PIPELINE_STATUSES,
    ];

    private PipelineAuthorizationService $pipelineAuth;
    private PaymentSchedulingPipelineStateRegistry $stateRegistry;

    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?PaymentSchedulingPipelineStateRegistry $stateRegistry = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->stateRegistry = $stateRegistry ?? new PaymentSchedulingPipelineStateRegistry();
    }

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::FORWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getNextStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function canReject(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function validateTransitionRequirements(PaymentScheduling $scheduling, string $fromStatus): array
    {
        return $this->stateRegistry->get($fromStatus)->validateAdvance($scheduling);
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
        string $roleName,
        int $userId,
        string $reason,
    ): ServiceResult {
        $reason = trim($reason);
        $currentStatus = $scheduling->pipeline_status;

        if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
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
                    $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
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
}
