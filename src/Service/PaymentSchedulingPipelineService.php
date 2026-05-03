<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\PaymentScheduling;
use Cake\ORM\TableRegistry;

class PaymentSchedulingPipelineService
{
    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth
     */
    public function __construct(?PipelineAuthorizationService $pipelineAuth = null)
    {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }

    public const STATUSES = PaymentSchedulingConstants::PIPELINE_STATUSES;
    public const STATUS_LABELS = PaymentSchedulingConstants::STATUS_LABELS;

    public const TRANSITIONS = [
        PaymentSchedulingConstants::STATUS_BORRADOR => PaymentSchedulingConstants::STATUS_TESORERIA,
        PaymentSchedulingConstants::STATUS_TESORERIA => PaymentSchedulingConstants::STATUS_AUT_PAGO,
        PaymentSchedulingConstants::STATUS_AUT_PAGO => PaymentSchedulingConstants::STATUS_PAGADA,
        PaymentSchedulingConstants::STATUS_PAGADA => null,
    ];

    // Regreso cuando Contador rechaza
    public const REJECTION_TARGET = PaymentSchedulingConstants::STATUS_TESORERIA;

    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::TESORERIA => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::CONTADOR => [
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::ADMIN => PaymentSchedulingConstants::PIPELINE_STATUSES,
    ];

    private const TRANSITION_REQUIREMENTS = [
        PaymentSchedulingConstants::STATUS_BORRADOR => [
            [
                'field' => '_has_items',
                'custom' => true,
                'label' => 'Debe vincular al menos una factura',
            ],
        ],
        PaymentSchedulingConstants::STATUS_TESORERIA => [],
        PaymentSchedulingConstants::STATUS_AUT_PAGO => [],
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ((self::TRANSITIONS[$currentStatus] ?? null) === null) {
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
        if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUT_PAGO) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            $currentStatus,
        );
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return self::TRANSITIONS[$currentStatus] ?? null;
    }

    public function validateTransitionRequirements(object $scheduling, string $fromStatus): array
    {
        $errors = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            if (!empty($rule['custom']) && $rule['field'] === '_has_items') {
                $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
                $count = $itemsTable->find()->where(['payment_scheduling_id' => $scheduling->id])->count();
                if ($count === 0) {
                    $errors[] = $rule['label'];
                }
            }
        }

        return $errors;
    }

    /**
     * Returns the previous pipeline status, or null if no predecessor exists
     * or the state is excluded from regression.
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    /**
     * Returns true if the role can regress the scheduling from the current status.
     */
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

    /**
     * No automatic locks in this iteration. Present for symmetry with the pattern.
     */
    public function getRegressionLockMessage(object $scheduling): ?string
    {
        return null;
    }

    /**
     * Cold regression — only changes pipeline_status, doesn't touch items or payments.
     *
     * @return \App\Service\ServiceResult on success: data = ['previousStatus' => string]
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
}
