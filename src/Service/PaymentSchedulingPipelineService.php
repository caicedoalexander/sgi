<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;

class PaymentSchedulingPipelineService
{
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

    public function canAdvance(string $roleName, string $currentStatus): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::TRANSITIONS[$currentStatus] !== null;
        }

        $visible = $this->getVisibleStatuses($roleName);
        if (!in_array($currentStatus, $visible)) {
            return false;
        }

        // Tesorería puede avanzar borrador y tesoreria
        if ($roleName === RoleConstants::TESORERIA) {
            return in_array($currentStatus, [
                PaymentSchedulingConstants::STATUS_BORRADOR,
                PaymentSchedulingConstants::STATUS_TESORERIA,
            ]);
        }

        // Contador puede avanzar aut_pago
        if ($roleName === RoleConstants::CONTADOR) {
            return $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO;
        }

        return false;
    }

    public function canReject(string $roleName, string $currentStatus): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO;
        }

        return $roleName === RoleConstants::CONTADOR
            && $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO;
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
}
