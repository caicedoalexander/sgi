<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use Cake\ORM\TableRegistry;

/**
 * Audit trail dedicado al pipeline de legalización de anticipos.
 *
 * Espejo del patrón usado por PettyCashHistoryService / InvoiceHistoryService.
 * Registra cambios de estado, declaración de caso, montos y comprobantes
 * sobre la entidad AdvanceLegalization (audit SU-004).
 */
class AdvanceLegalizationHistoryService
{
    /**
     * Registra un cambio de estado del pipeline de legalización.
     */
    public function recordStatusChange(int $legalizationId, string $fromStatus, string $toStatus, int $userId): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $labels = AdvanceConstants::STATUS_LABELS;
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationHistories');

        $entity = $table->newEntity([
            'legalization_id' => $legalizationId,
            'user_id' => $userId,
            'field_changed' => 'status',
            'old_value' => $labels[$fromStatus] ?? $fromStatus,
            'new_value' => $labels[$toStatus] ?? $toStatus,
        ]);

        $table->save($entity);
    }

    /**
     * Registra un cambio de campo arbitrario sobre la legalización
     * (case_type, shortage_amount, surplus_amount, receipt_number, etc.).
     */
    public function recordFieldChange(
        int $legalizationId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        int $userId,
    ): void {
        if ((string)$oldValue === (string)$newValue) {
            return;
        }

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationHistories');
        $entity = $table->newEntity([
            'legalization_id' => $legalizationId,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);

        $table->save($entity);
    }
}
