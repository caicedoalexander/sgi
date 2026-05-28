<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Trait\HistoryNormalizationTrait;
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
    use HistoryNormalizationTrait;

    /**
     * Campos editables del registro que se auditan campo a campo.
     *
     * Espejo de los campos que `RefundFieldAccessPolicy::filterEntityData`
     * permite patchear en `RefundsController::edit` (beneficiary_* en
     * agrupación, más accrued/accrual_date/ready_for_payment en contabilidad).
     */
    private const FIELDS_TO_TRACK = [
        'beneficiary_type',
        'beneficiary_employee_id',
        'beneficiary_provider_id',
        'accrued',
        'accrual_date',
        'ready_for_payment',
    ];

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
     * Registra los cambios campo a campo entre el estado original y el
     * modificado del registro. Espeja InvoiceHistoryService::recordChanges:
     * `field_changed` guarda el nombre crudo del campo (no el label).
     *
     * @param \App\Model\Entity\Refund $original Snapshot previo al patch.
     * @param \App\Model\Entity\Refund $modified Entidad tras el save.
     * @param int $userId Usuario que realizó el cambio.
     * @return void
     */
    public function recordChanges(Refund $original, Refund $modified, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('RefundHistories');
        $entities = [];

        foreach (self::FIELDS_TO_TRACK as $field) {
            $oldVal = $this->normalizeValue($original->get($field));
            $newVal = $this->normalizeValue($modified->get($field));

            if (is_bool($oldVal) || is_bool($newVal)) {
                $oldVal = (bool)$oldVal;
                $newVal = (bool)$newVal;
            }

            if ($oldVal !== $newVal) {
                $entities[] = $table->newEntity([
                    'refund_id' => $original->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal !== null ? (string)$oldVal : null,
                    'new_value' => $newVal !== null ? (string)$newVal : null,
                ]);
            }
        }

        if (!empty($entities)) {
            $table->getConnection()->transactional(function () use ($table, $entities): void {
                $table->saveMany($entities);
            });
        }
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
