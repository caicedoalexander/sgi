<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Employee;
use Cake\ORM\TableRegistry;

class EmployeeHistoryService
{
    public const FIELD_LABELS = [
        'document_type'            => 'Tipo de Documento',
        'document_number'          => 'Número de Documento',
        'first_name'               => 'Nombres',
        'last_name1'               => 'Primer Apellido',
        'last_name2'               => 'Segundo Apellido',
        'birth_date'               => 'Fecha de Nacimiento',
        'gender'                   => 'Género',
        'marital_status_id'        => 'Estado Civil',
        'education_level_id'       => 'Nivel Educativo',
        'email'                    => 'Correo Electrónico',
        'phone'                    => 'Teléfono',
        'address'                  => 'Dirección',
        'city'                     => 'Ciudad',
        'employee_status_id'       => 'Estado del Empleado',
        'position_id'              => 'Cargo',
        'supervisor_position_id'   => 'Jefe Inmediato',
        'operation_center_id'      => 'Centro de Operación',
        'cost_center_id'           => 'Centro de Costos',
        'hire_date'                => 'Fecha de Ingreso',
        'termination_date'         => 'Fecha de Retiro',
        'salary'                   => 'Salario',
        'contract_type'            => 'Tipo de Contrato',
        'temporary_organization_id' => 'Organización Temporal',
        'vest_number'              => 'Número de Chaleco',
        'eps'                      => 'EPS',
        'pension_fund'             => 'Fondo de Pensión',
        'arl'                      => 'ARL',
        'severance_fund'           => 'Fondo de Cesantías',
    ];

    public function recordChanges(Employee $original, Employee $modified, int $userId): void
    {
        $fieldsToTrack = array_keys(self::FIELD_LABELS);

        $historiesTable = TableRegistry::getTableLocator()->get('EmployeeHistories');

        foreach ($fieldsToTrack as $field) {
            $oldVal = $original->get($field);
            $newVal = $modified->get($field);

            // Normalize DateTime to string for comparison
            if ($oldVal instanceof \DateTimeInterface) {
                $oldVal = $oldVal->format('Y-m-d');
            }
            if ($newVal instanceof \DateTimeInterface) {
                $newVal = $newVal->format('Y-m-d');
            }

            // Normalize booleans
            if (is_bool($oldVal) || is_bool($newVal)) {
                $oldVal = (bool)$oldVal;
                $newVal = (bool)$newVal;
            }

            // Normalize null and empty string
            if ($oldVal === '') {
                $oldVal = null;
            }
            if ($newVal === '') {
                $newVal = null;
            }

            if ($oldVal !== $newVal) {
                $history = $historiesTable->newEntity([
                    'employee_id' => $original->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal !== null ? (string)$oldVal : null,
                    'new_value' => $newVal !== null ? (string)$newVal : null,
                ]);
                $historiesTable->save($history);
            }
        }
    }
}
