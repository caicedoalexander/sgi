<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Trait\HistoryNormalizationTrait;
use Cake\ORM\TableRegistry;

/**
 * Records field-by-field audit trail for employee novelties.
 */
class NoveltyHistoryService implements HistoryServiceInterface
{
    use HistoryNormalizationTrait;

    /**
     * Field labels for display in history table.
     */
    public const FIELD_LABELS = [
        'pipeline_status' => 'Estado del Pipeline',
        'passes_payroll' => 'Pasa a Nómina',
        'permission_date' => 'Fecha del Permiso',
        'schedule_type' => 'Tipo de Horario',
        'start_date' => 'Fecha Inicio',
        'end_date' => 'Fecha Fin',
        'start_time' => 'Hora Salida',
        'end_time' => 'Hora Entrada',
        'is_paid' => 'Remunerado',
        'reason' => 'Motivo',
        'employee_id' => 'Empleado',
        'novelty_type_id' => 'Tipo de Novedad',
        'liquidation_doc_id' => 'Documento de Liquidación',
        'area_approval' => 'Aprobación de Área',
        'approver_response' => 'Respuesta de Aprobador',
        'approver_id' => 'Aprobador',
    ];

    /**
     * Record all changed fields between original and modified entity.
     *
     * @param object $original Original entity state.
     * @param object $modified Modified entity state.
     * @param int $userId User making the change.
     * @return void
     */
    public function recordChanges(object $original, object $modified, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyHistories');
        $entities = [];

        foreach (array_keys(self::FIELD_LABELS) as $field) {
            $oldVal = $this->normalizeToString($original->$field ?? null);
            $newVal = $this->normalizeToString($modified->$field ?? null);

            if ($oldVal !== $newVal) {
                $entities[] = $table->newEntity([
                    'novelty_id' => $modified->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal === '' ? null : $oldVal,
                    'new_value' => $newVal === '' ? null : $newVal,
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
     * Record a pipeline status change.
     *
     * @param int $noveltyId Novelty ID.
     * @param string $fromStatus Previous status.
     * @param string $toStatus New status.
     * @param int $userId User making the change.
     * @return void
     */
    public function recordStatusChange(int $noveltyId, string $fromStatus, string $toStatus, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyHistories');
        $entry = $table->newEntity([
            'novelty_id' => $noveltyId,
            'user_id' => $userId,
            'field_changed' => 'pipeline_status',
            'old_value' => NoveltyConstants::STATUS_LABELS[$fromStatus] ?? $fromStatus,
            'new_value' => NoveltyConstants::STATUS_LABELS[$toStatus] ?? $toStatus,
        ]);
        $table->save($entry);
    }

    public function recordFieldChange(int $noveltyId, string $field, ?string $oldValue, ?string $newValue, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyHistories');
        $entry = $table->newEntity([
            'novelty_id' => $noveltyId,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
        $table->save($entry);
    }
}
