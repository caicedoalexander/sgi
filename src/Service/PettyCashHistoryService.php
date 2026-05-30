<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Trait\HistoryNormalizationTrait;
use Cake\ORM\TableRegistry;

/**
 * Audit trail dedicado al pipeline de Caja Menor.
 *
 * Implementa HistoryServiceInterface: el contrato sólo cubre
 * recordStatusChange (cambios de estado de `petty_cash_records`, el history
 * del propio módulo). El typehint HistoryServiceInterface que aparece en los
 * coordinadores de pipeline apunta al history de las facturas hijas
 * (InvoiceHistoryService usado vía GroupedInvoiceService::recordBulkHistory),
 * no a este servicio; el `implements` aquí es uniformidad.
 */
class PettyCashHistoryService implements HistoryServiceInterface
{
    use HistoryNormalizationTrait;

    /**
     * Campos editables del registro que se auditan campo a campo.
     *
     * Espejo de los campos que `PettyCashFieldAccessPolicy` permite patchear
     * en `PettyCashPipelineService::saveAndAdvance` (notes en agrupación/contabilidad,
     * más accrued/accrual_date/ready_for_payment en contabilidad).
     */
    private const FIELDS_TO_TRACK = [
        'notes',
        'accrued',
        'accrual_date',
        'ready_for_payment',
    ];

    /**
     * Registra un cambio de estado del registro de Caja Menor.
     */
    public function recordStatusChange(int $recordId, string $fromStatus, string $toStatus, int $userId): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $labels = PettyCashConstants::STATUS_LABELS;
        $table = TableRegistry::getTableLocator()->get('PettyCashHistories');

        $entity = $table->newEntity([
            'petty_cash_record_id' => $recordId,
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
     * @param \App\Model\Entity\PettyCashRecord $original Snapshot previo al patch.
     * @param \App\Model\Entity\PettyCashRecord $modified Entidad tras el save.
     * @param int $userId Usuario que realizó el cambio.
     * @return void
     */
    public function recordChanges(PettyCashRecord $original, PettyCashRecord $modified, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('PettyCashHistories');
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
                    'petty_cash_record_id' => $original->id,
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

        $table = TableRegistry::getTableLocator()->get('PettyCashHistories');
        $entity = $table->newEntity([
            'petty_cash_record_id' => $recordId,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);

        $table->save($entity);
    }
}
