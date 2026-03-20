<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;
use DateTimeInterface;

/**
 * Records field-by-field audit trail for employee novelties.
 */
class NoveltyHistoryService
{
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

        foreach (array_keys(self::FIELD_LABELS) as $field) {
            $oldVal = $this->normalize($original->$field ?? null);
            $newVal = $this->normalize($modified->$field ?? null);

            if ($oldVal !== $newVal) {
                $entry = $table->newEntity([
                    'novelty_id' => $modified->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal === '' ? null : $oldVal,
                    'new_value' => $newVal === '' ? null : $newVal,
                ]);
                $table->save($entry);
            }
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

    /**
     * Normalize a value for comparison.
     *
     * @param mixed $value Value to normalize.
     * @return string
     */
    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string)$value;
    }
}
