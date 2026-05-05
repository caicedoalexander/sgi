<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RefundConstants;
use Cake\ORM\TableRegistry;

/**
 * Audit trail dedicado al pipeline de Reintegros.
 *
 * No implementa HistoryServiceInterface (que apunta a Invoice). Esto registra
 * cambios sobre `refunds` — los cambios de pipeline de las facturas hijas
 * siguen registrándose por separado vía InvoiceHistoryService usado por
 * GroupedInvoiceService::recordBulkHistory.
 */
class RefundHistoryService
{
    /**
     * Registra un cambio de estado del registro de Reintegro.
     */
    public function recordStatusChange(int $recordId, string $fromStatus, string $toStatus, int $userId): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $labels = RefundConstants::STATUS_LABELS;
        $table = TableRegistry::getTableLocator()->get('RefundHistories');

        $entity = $table->newEntity([
            'refund_id' => $recordId,
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

        $table = TableRegistry::getTableLocator()->get('RefundHistories');
        $entity = $table->newEntity([
            'refund_id' => $recordId,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);

        $table->save($entity);
    }
}
