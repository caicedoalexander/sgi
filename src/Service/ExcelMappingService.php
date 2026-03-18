<?php
declare(strict_types=1);

namespace App\Service;

final class ExcelMappingService
{
    private const FIELD_DEFINITIONS = [
        'Employees' => [
            'document_type' => ['label' => 'Tipo de documento', 'type' => 'string'],
            'document_number' => [
                'label' => 'Cédula', 'type' => 'string', 'required' => true, 'is_key' => true,
                'aliases' => ['empleado'],
            ],
            'first_name' => ['label' => 'Nombres', 'type' => 'string', 'required_new' => true],
            'last_name1' => [
                'label' => 'Primer Apellido', 'type' => 'string', 'required_new' => true,
                'aliases' => ['apellido1', 'apellido 1', 'primer apellido', 'apellidos'],
            ],
            'last_name2' => [
                'label' => 'Segundo Apellido', 'type' => 'string',
                'aliases' => ['apellido2', 'apellido 2', 'segundo apellido'],
            ],
            'birth_date' => [
                'label' => 'Fecha de nacimiento', 'type' => 'date',
                'aliases' => ['fecha nacimiento del empleado'],
            ],
            'gender' => [
                'label' => 'Género', 'type' => 'string',
                'aliases' => ['genero del empleado'],
            ],
            'email' => [
                'label' => 'Correo electrónico', 'type' => 'string',
                'aliases' => ['email del contacto'],
            ],
            'phone' => [
                'label' => 'Teléfono', 'type' => 'string',
                'aliases' => ['celular del contacto'],
            ],
            'address' => [
                'label' => 'Dirección', 'type' => 'string',
                'aliases' => ['dirección del contacto', 'direccion del contacto'],
            ],
            'city' => ['label' => 'Ciudad', 'type' => 'string'],
            'hire_date' => [
                'label' => 'Fecha de ingreso', 'type' => 'date',
                'aliases' => ['fecha ingreso'],
            ],
            'termination_date' => ['label' => 'Fecha de retiro', 'type' => 'date'],
            'salary' => ['label' => 'Salario', 'type' => 'decimal'],
            'contract_type' => ['label' => 'Tipo de contrato', 'type' => 'string'],
            'vest_number' => ['label' => 'Número de chaleco', 'type' => 'string'],
            'eps' => ['label' => 'EPS', 'type' => 'string'],
            'pension_fund' => ['label' => 'Fondo de pensión', 'type' => 'string'],
            'arl' => ['label' => 'ARL', 'type' => 'string'],
            'severance_fund' => ['label' => 'Fondo de cesantías', 'type' => 'string'],
            'notes' => ['label' => 'Observaciones', 'type' => 'string'],
            'active' => ['label' => 'Activo', 'type' => 'boolean'],

            // FK fields: import/export by code
            'position_id' => [
                'label' => 'Código Cargo', 'type' => 'string',
                'fk' => true, 'fk_table' => 'Positions', 'fk_code' => 'code',
                'aliases' => ['id cargo'],
            ],
            'supervisor_position_id' => [
                'label' => 'Código Cargo supervisor', 'type' => 'string',
                'fk' => true, 'fk_table' => 'Positions', 'fk_code' => 'code',
                'aliases' => ['cargo jefe inmediato'],
            ],
            'operation_center_id' => [
                'label' => 'Código Centro de operación', 'type' => 'string',
                'fk' => true, 'fk_table' => 'OperationCenters', 'fk_code' => 'code',
                'aliases' => ['id c.o.'],
            ],
            'cost_center_id' => [
                'label' => 'Código Centro de costos', 'type' => 'string',
                'fk' => true, 'fk_table' => 'CostCenters', 'fk_code' => 'code',
                'aliases' => ['id ccosto'],
            ],
            'employee_status_id' => [
                'label' => 'Estado empleado', 'type' => 'string',
                'fk' => true, 'fk_table' => 'EmployeeStatuses', 'fk_code' => 'name',
            ],
            'marital_status_id' => [
                'label' => 'Estado civil', 'type' => 'string',
                'fk' => true, 'fk_table' => 'MaritalStatuses', 'fk_code' => 'name',
            ],
            'education_level_id' => [
                'label' => 'Nivel educativo', 'type' => 'string',
                'fk' => true, 'fk_table' => 'EducationLevels', 'fk_code' => 'name',
            ],
            'temporary_organization_id' => [
                'label' => 'NIT Temporal', 'type' => 'string',
                'fk' => true, 'fk_table' => 'TemporaryOrganizations', 'fk_code' => 'nit',
            ],

            // Display-only fields: export name, import resolves name→id
            'position' => [
                'label' => 'Cargo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'position_id',
                'aliases' => ['descripcion del cargo'],
            ],
            'supervisor_position' => [
                'label' => 'Cargo supervisor', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'supervisor_position_id',
                'aliases' => ['descripción cargo jefe inmediato', 'descripcion cargo jefe inmediato'],
            ],
            'operation_center' => [
                'label' => 'Centro de operación', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'OperationCenters', 'fk_target' => 'operation_center_id',
                'aliases' => ['descripcion c.o.'],
            ],
            'cost_center' => [
                'label' => 'Centro de costos', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'CostCenters', 'fk_target' => 'cost_center_id',
                'aliases' => ['descripcion ccosto'],
            ],
            'employee_status' => [
                'label' => 'Estado', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'EmployeeStatuses', 'fk_target' => 'employee_status_id',
                'aliases' => ['descripcion estado'],
            ],
            'marital_status' => [
                'label' => 'Estado civil', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'MaritalStatuses', 'fk_target' => 'marital_status_id',
                'aliases' => ['estado civil del empleado'],
            ],
            'education_level' => [
                'label' => 'Nivel educativo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'EducationLevels', 'fk_target' => 'education_level_id',
                'aliases' => ['nivel educativo del empleado'],
            ],
            'temporary_organization' => [
                'label' => 'Temporal', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'TemporaryOrganizations',
                'fk_target' => 'temporary_organization_id',
            ],
        ],
    ];

    /**
     * Get field definitions for a module.
     *
     * @return array<string, array>
     */
    public function getFieldDefinitions(string $module): array
    {
        return self::FIELD_DEFINITIONS[$module] ?? [];
    }

    /**
     * Get exportable fields as ordered list for JSON response.
     *
     * @return array<int, array{field: string, label: string, checked: bool}>
     */
    public function getExportableFields(string $module): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            $fields[] = [
                'field' => $field,
                'label' => $def['label'],
                'checked' => true,
            ];
        }

        return $fields;
    }

    /**
     * Get system fields for import mapping UI.
     * Includes display_only fields that can resolve by name (fk_resolve).
     *
     * @return array<int, array{field: string, label: string, required: bool}>
     */
    public function getImportableFields(string $module): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            // Skip pure display_only without fk_resolve capability
            if (!empty($def['display_only']) && empty($def['fk_resolve'])) {
                continue;
            }
            $fields[] = [
                'field' => $field,
                'label' => $def['label'],
                'required' => !empty($def['required']),
            ];
        }

        return $fields;
    }

    /**
     * Build lookup maps for auto-mapping file headers to system fields.
     * Includes Spanish labels, English field names, and aliases.
     *
     * @return array<string, string>
     */
    public function buildAutoMapLookup(string $module): array
    {
        $lookup = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            // Match by Spanish label (case-insensitive)
            $lookup[mb_strtolower(trim($def['label']))] = $field;
            // Match by English field name (case-insensitive)
            $lookup[mb_strtolower($field)] = $field;
            // Match by aliases
            if (!empty($def['aliases'])) {
                foreach ($def['aliases'] as $alias) {
                    $lookup[mb_strtolower(trim($alias))] = $field;
                }
            }
        }

        return $lookup;
    }

    /**
     * Auto-map file headers to system fields.
     *
     * @param array<string> $fileHeaders Headers from the uploaded Excel file
     * @return array<string, string|null> Map of file_header => system_field (null if no match)
     */
    public function autoMapColumns(array $fileHeaders, string $module): array
    {
        $lookup = $this->buildAutoMapLookup($module);
        $mapping = [];

        foreach ($fileHeaders as $header) {
            $normalized = mb_strtolower(trim($header));
            $mapping[$header] = $lookup[$normalized] ?? null;
        }

        return $mapping;
    }

    /**
     * Validate that required fields are mapped.
     *
     * @param array<string, string|null> $mapping file_header => system_field
     * @return array<string> List of error messages (empty = valid)
     */
    public function validateMapping(array $mapping, string $module): array
    {
        $definitions = $this->getFieldDefinitions($module);
        $mappedFields = array_filter(array_values($mapping));
        $errors = [];

        foreach ($definitions as $field => $def) {
            if (!empty($def['required']) && !in_array($field, $mappedFields)) {
                $errors[] = "El campo obligatorio \"{$def['label']}\" no está mapeado.";
            }
        }

        return $errors;
    }

    /**
     * Get the label map (field => label) for export headers.
     *
     * @return array<string, string>
     */
    public function getLabelMap(string $module): array
    {
        $map = [];
        foreach ($this->getFieldDefinitions($module) as $field => $def) {
            $map[$field] = $def['label'];
        }

        return $map;
    }
}
