<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Service\Interface\HistoryServiceInterface;
use Cake\ORM\TableRegistry;

/**
 * Audit trail dedicado al pipeline de Programaciones de Pago.
 *
 * Implementa HistoryServiceInterface: el contrato sólo cubre
 * recordStatusChange (cambios de estado de `payment_schedulings`, el history
 * del propio módulo). El typehint HistoryServiceInterface que aparece en los
 * coordinadores de pipeline apunta al history de las facturas hijas
 * (InvoiceHistoryService), no a este servicio; el `implements` aquí es
 * uniformidad.
 */
class PaymentSchedulingHistoryService implements HistoryServiceInterface
{
    /**
     * Registra un cambio de estado del registro de Programación de Pago.
     */
    public function recordStatusChange(int $recordId, string $fromStatus, string $toStatus, int $userId): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $labels = PaymentSchedulingConstants::STATUS_LABELS;
        $table = TableRegistry::getTableLocator()->get('PaymentSchedulingHistories');

        $entity = $table->newEntity([
            'payment_scheduling_id' => $recordId,
            'user_id' => $userId,
            'field_changed' => 'status',
            'old_value' => $labels[$fromStatus] ?? $fromStatus,
            'new_value' => $labels[$toStatus] ?? $toStatus,
        ]);

        $table->save($entity);
    }

    /**
     * Registra un cambio de campo arbitrario sobre el registro.
     */
    public function recordFieldChange(
        int $recordId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        int $userId,
    ): void {
        if ((string)$oldValue === (string)$newValue) {
            return;
        }

        $table = TableRegistry::getTableLocator()->get('PaymentSchedulingHistories');
        $entity = $table->newEntity([
            'payment_scheduling_id' => $recordId,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);

        $table->save($entity);
    }
}
