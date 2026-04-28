<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Excel\ExcelExportableInterface;

final class ExcelMappingService
{
    /**
     * Resolve definitions from either a Table implementing the interface
     * or an already-built field array.
     *
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, array<string, mixed>>
     */
    public function getFieldDefinitions(ExcelExportableInterface|array $source): array
    {
        return $source instanceof ExcelExportableInterface ? $source->getExcelFields() : $source;
    }

    /**
     * Get exportable fields as ordered list for JSON response.
     *
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<int, array{field: string, label: string, checked: bool}>
     */
    public function getExportableFields(ExcelExportableInterface|array $source): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
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
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<int, array{field: string, label: string, required: bool}>
     */
    public function getImportableFields(ExcelExportableInterface|array $source): array
    {
        $fields = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
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
     *
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, string>
     */
    public function buildAutoMapLookup(ExcelExportableInterface|array $source): array
    {
        $lookup = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
            $lookup[mb_strtolower(trim($def['label']))] = $field;
            $lookup[mb_strtolower($field)] = $field;
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
     * @param array<string> $fileHeaders
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, string|null>
     */
    public function autoMapColumns(array $fileHeaders, ExcelExportableInterface|array $source): array
    {
        $lookup = $this->buildAutoMapLookup($source);
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
     * @param array<string, string|null> $mapping
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<int, string>
     */
    public function validateMapping(array $mapping, ExcelExportableInterface|array $source): array
    {
        $definitions = $this->getFieldDefinitions($source);
        $mappedFields = array_filter(array_values($mapping));
        $errors = [];
        foreach ($definitions as $field => $def) {
            if (!empty($def['required']) && !in_array($field, $mappedFields, true)) {
                $errors[] = "El campo obligatorio \"{$def['label']}\" no está mapeado.";
            }
        }

        return $errors;
    }

    /**
     * Get the label map (field => label) for export headers.
     *
     * @param \App\Model\Excel\ExcelExportableInterface|array<string, array<string, mixed>> $source
     * @return array<string, string>
     */
    public function getLabelMap(ExcelExportableInterface|array $source): array
    {
        $map = [];
        foreach ($this->getFieldDefinitions($source) as $field => $def) {
            $map[$field] = $def['label'];
        }

        return $map;
    }
}
