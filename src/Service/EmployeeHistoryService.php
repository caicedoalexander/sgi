<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Employee;
use App\Service\Trait\HistoryNormalizationTrait;
use Cake\ORM\TableRegistry;

class EmployeeHistoryService
{
    use HistoryNormalizationTrait;

    public const FIELD_LABELS = [
        'document_type' => 'Tipo de Documento',
        'document_number' => 'Número de Documento',
        'first_name' => 'Nombres',
        'last_name1' => 'Primer Apellido',
        'last_name2' => 'Segundo Apellido',
        'birth_date' => 'Fecha de Nacimiento',
        'gender' => 'Género',
        'marital_status_id' => 'Estado Civil',
        'education_level_id' => 'Nivel Educativo',
        'email' => 'Correo Electrónico',
        'phone' => 'Teléfono',
        'address' => 'Dirección',
        'city' => 'Ciudad',
        'status' => 'Estado del Empleado',
        'position_id' => 'Cargo',
        'supervisor_position_id' => 'Jefe Inmediato',
        'operation_center_id' => 'Centro de Operación',
        'cost_center_id' => 'Centro de Costos',
        'hire_date' => 'Fecha de Ingreso',
        'termination_date' => 'Fecha de Retiro',
        'salary' => 'Salario',
        'contract_type' => 'Tipo de Contrato',
        'temporary_organization_id' => 'Organización Temporal',
        'vest_number' => 'Número de Chaleco',
        'eps' => 'EPS',
        'pension_fund' => 'Fondo de Pensión',
        'arl' => 'ARL',
        'severance_fund' => 'Fondo de Cesantías',
    ];

    /**
     * Record field-by-field changes between original and modified employee.
     *
     * @param \App\Model\Entity\Employee $original Original employee state.
     * @param \App\Model\Entity\Employee $modified Modified employee state.
     * @param int $userId ID of the user making changes.
     * @return void
     */
    public function recordChanges(Employee $original, Employee $modified, int $userId): void
    {
        $fieldsToTrack = array_keys(self::FIELD_LABELS);

        $historiesTable = TableRegistry::getTableLocator()->get('EmployeeHistories');
        $entities = [];

        foreach ($fieldsToTrack as $field) {
            $oldVal = $this->normalizeValue($original->get($field));
            $newVal = $this->normalizeValue($modified->get($field));

            // Normalize booleans for consistent comparison
            if (is_bool($oldVal) || is_bool($newVal)) {
                $oldVal = (bool)$oldVal;
                $newVal = (bool)$newVal;
            }

            if ($oldVal !== $newVal) {
                $entities[] = $historiesTable->newEntity([
                    'employee_id' => $original->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal !== null ? (string)$oldVal : null,
                    'new_value' => $newVal !== null ? (string)$newVal : null,
                ]);
            }
        }

        if (!empty($entities)) {
            $historiesTable->getConnection()->transactional(function () use ($historiesTable, $entities): void {
                $historiesTable->saveMany($entities);
            });
        }
    }
}
