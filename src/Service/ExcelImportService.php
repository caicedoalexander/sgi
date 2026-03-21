<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use DateTime;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ExcelImportService
{
    private ExcelMappingService $mappingService;

    /**
     * @param \App\Service\ExcelMappingService|null $mappingService Mapping service instance
     */
    public function __construct(?ExcelMappingService $mappingService = null)
    {
        $this->mappingService = $mappingService ?? new ExcelMappingService();
    }

    /**
     * Read headers from an uploaded Excel file saved as temp.
     *
     * @return array<string> List of header strings
     * @throws \Exception if the file cannot be read
     */
    public function readHeaders(string $tempFilePath): array
    {
        $spreadsheet = IOFactory::load($tempFilePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (empty($rows)) {
            throw new Exception('El archivo está vacío.');
        }

        $headers = array_map(fn($h) => trim((string)$h), $rows[0]);
        // Remove empty trailing headers
        $headers = array_values(array_filter($headers, fn($h) => $h !== ''));

        return $headers;
    }

    /**
     * Process import with custom column mapping.
     *
     * @param string $tempFilePath Path to the temporary Excel file
     * @param string $module Module name (e.g., 'Employees')
     * @param string $tableName ORM table alias
     * @param array<string, string> $mapping file_header => system_field
     * @param array<string> $enabledHeaders Only these file headers will be processed
     */
    public function processImport(
        string $tempFilePath,
        string $module,
        string $tableName,
        array $mapping,
        array $enabledHeaders,
        ?callable $onCreated = null,
    ): ImportResult {
        $result = new ImportResult();
        $definitions = $this->mappingService->getFieldDefinitions($module);

        // Find key field
        $keyField = null;
        foreach ($definitions as $field => $def) {
            if (!empty($def['is_key'])) {
                $keyField = $field;
                break;
            }
        }
        if (!$keyField) {
            $result->errors[] = 'No se encontró campo clave para el módulo.';

            return $result;
        }

        // Validate required fields are mapped
        $validationErrors = $this->mappingService->validateMapping($mapping, $module);
        if (!empty($validationErrors)) {
            $result->errors = $validationErrors;

            return $result;
        }

        try {
            $spreadsheet = IOFactory::load($tempFilePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, false, false);
        } catch (Exception $e) {
            $result->errors[] = 'No se pudo leer el archivo: ' . $e->getMessage();

            return $result;
        }

        if (count($rows) < 2) {
            $result->errors[] = 'El archivo está vacío o solo tiene encabezados.';

            return $result;
        }

        $headers = array_map(fn($h) => trim((string)$h), $rows[0]);
        $table = TableRegistry::getTableLocator()->get($tableName);
        $skipSystemFields = ['id', 'created', 'modified', 'profile_image'];

        // Build FK lookup caches: code→id and name→id
        $fkCodeLookups = $this->buildFkLookups($definitions);
        $fkNameLookups = $this->buildFkNameLookups($definitions);

        $rowCount = count($rows);
        for ($i = 1; $i < $rowCount; $i++) {
            $rowData = [];
            $rowNum = $i + 1; // Human-readable row number

            foreach ($headers as $col => $header) {
                // Skip if header is not in enabled list
                if (!in_array($header, $enabledHeaders)) {
                    continue;
                }
                // Skip if not mapped
                $systemField = $mapping[$header] ?? null;
                if (!$systemField) {
                    continue;
                }
                // Skip system fields
                if (in_array($systemField, $skipSystemFields)) {
                    continue;
                }
                $fieldDef = $definitions[$systemField] ?? null;

                $rawValue = $rows[$i][$col] ?? null;
                $castValue = $this->castValue($rawValue, $fieldDef['type'] ?? 'string');

                // Handle display_only fields with fk_resolve (name→id)
                if (!empty($fieldDef['display_only']) && !empty($fieldDef['fk_resolve'])) {
                    $nameStr = trim((string)$castValue);
                    if ($nameStr !== '' && isset($fkNameLookups[$systemField])) {
                        $resolvedId = $fkNameLookups[$systemField][$nameStr]
                            ?? $fkNameLookups[$systemField][mb_strtolower($nameStr)]
                            ?? null;
                        if ($resolvedId === null) {
                            $result->errors[] = "Fila {$rowNum}: {$fieldDef['label']} \"{$nameStr}\" no encontrado.";
                            continue;
                        }
                        // Store in the target _id field
                        $targetField = $fieldDef['fk_target'];
                        if (!isset($rowData[$targetField])) {
                            $rowData[$targetField] = $resolvedId;
                        }
                    }
                    continue; // Don't store the display_only field itself
                }

                // Skip pure display_only fields without fk_resolve
                if (!empty($fieldDef['display_only'])) {
                    continue;
                }

                // Resolve FK code to ID
                if (!empty($fieldDef['fk']) && !empty($fieldDef['fk_code']) && $castValue !== null) {
                    $codeStr = trim((string)$castValue);
                    if ($codeStr !== '' && isset($fkCodeLookups[$systemField])) {
                        $resolvedId = $fkCodeLookups[$systemField][$codeStr] ?? null;
                        if ($resolvedId === null) {
                            $result->errors[] = "Fila {$rowNum}: {$fieldDef['label']} \"{$codeStr}\" no encontrado.";
                            continue;
                        }
                        $castValue = $resolvedId;
                    } else {
                        $castValue = null;
                    }
                }

                $rowData[$systemField] = $castValue;
            }

            // Check key field
            $keyValue = trim((string)($rowData[$keyField] ?? ''));
            if ($keyValue === '') {
                $result->skipped++;
                continue;
            }

            // Upsert
            $existing = $table->find()
                ->where([$keyField => $keyValue])
                ->first();

            if ($existing) {
                $entity = $table->patchEntity($existing, $rowData);
            } else {
                // Validate required_new fields
                $missingNew = [];
                foreach ($definitions as $field => $def) {
                    if (!empty($def['required_new']) && empty($rowData[$field])) {
                        $missingNew[] = $def['label'];
                    }
                }
                if (!empty($missingNew)) {
                    $result->errors[] = "Fila {$rowNum}: Campos obligatorios para nuevo registro: "
                        . implode(', ', $missingNew);
                    continue;
                }
                $entity = $table->newEntity($rowData);
            }

            if ($table->save($entity)) {
                if ($existing) {
                    $result->updated++;
                } else {
                    $result->created++;
                    if ($onCreated) {
                        $onCreated($entity);
                    }
                }
            } else {
                $errors = $entity->getErrors();
                $errorMsg = "Fila {$rowNum}: ";
                foreach ($errors as $field => $fieldErrors) {
                    $label = $definitions[$field]['label'] ?? $field;
                    $errorMsg .= "{$label}: " . implode(', ', $fieldErrors) . '. ';
                }
                $result->errors[] = trim($errorMsg);
            }
        }

        return $result;
    }

    /**
     * Cast a raw Excel value to the expected PHP type.
     */
    private function castValue(mixed $rawValue, string $type): mixed
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        return match ($type) {
            'date' => $this->parseDate($rawValue),
            'decimal' => $this->parseDecimal($rawValue),
            'integer' => (int)$rawValue,
            'boolean' => $this->parseBoolean($rawValue),
            default => trim((string)$rawValue),
        };
    }

    /**
     * @param mixed $value Raw date value from Excel
     * @return string|null Formatted date string
     */
    private function parseDate(mixed $value): ?string
    {
        // Excel serial number
        if (is_numeric($value) && (float)$value > 1000) {
            try {
                $dateObj = Date::excelToDateTimeObject((float)$value);

                return $dateObj->format('Y-m-d');
            } catch (Exception) {
                return null;
            }
        }

        $strValue = trim((string)$value);

        // Try common formats
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'];
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $strValue);
            if ($parsed && $parsed->format($format) === $strValue) {
                return $parsed->format('Y-m-d');
            }
        }

        return $strValue;
    }

    /**
     * @param mixed $value Raw decimal value from Excel
     * @return float|null Parsed float value
     */
    private function parseDecimal(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        // Remove currency formatting (dots as thousands, comma as decimal)
        $cleaned = str_replace(['.', '$', ' '], '', (string)$value);
        $cleaned = str_replace(',', '.', $cleaned);

        return is_numeric($cleaned) ? (float)$cleaned : null;
    }

    /**
     * @param mixed $value Raw boolean value from Excel
     * @return bool|null Parsed boolean value
     */
    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $strValue = mb_strtolower(trim((string)$value));

        return match ($strValue) {
            '1', 'true', 'sí', 'si', 'yes', 'activo' => true,
            '0', 'false', 'no', 'inactivo' => false,
            default => null,
        };
    }

    /**
     * Build code→id lookup caches for all FK fields in the definitions.
     *
     * @param array<string, array> $definitions Field definitions from ExcelMappingService
     * @return array<string, array<string, int>> Map of field_name => [code => id]
     */
    private function buildFkLookups(array $definitions): array
    {
        $lookups = [];
        foreach ($definitions as $field => $def) {
            if (empty($def['fk']) || empty($def['fk_table']) || empty($def['fk_code'])) {
                continue;
            }
            $fkTable = TableRegistry::getTableLocator()->get($def['fk_table']);
            $codeField = $def['fk_code'];
            $rows = $fkTable->find()
                ->select(['id', $codeField])
                ->where(["{$codeField} IS NOT" => null])
                ->all();

            $map = [];
            foreach ($rows as $row) {
                $code = trim((string)$row->{$codeField});
                if ($code !== '') {
                    $map[$code] = $row->id;
                }
            }
            $lookups[$field] = $map;
        }

        return $lookups;
    }

    /**
     * Build name→id lookup caches for display_only fields with fk_resolve.
     * Stores both original and lowercased name for case-insensitive matching.
     *
     * @param array<string, array> $definitions Field definitions from ExcelMappingService
     * @return array<string, array<string, int>> Map of field_name => [name => id]
     */
    private function buildFkNameLookups(array $definitions): array
    {
        $lookups = [];
        foreach ($definitions as $field => $def) {
            if (empty($def['display_only']) || empty($def['fk_resolve']) || empty($def['fk_table'])) {
                continue;
            }
            $fkTable = TableRegistry::getTableLocator()->get($def['fk_table']);
            $nameField = $def['fk_resolve'];
            $rows = $fkTable->find()
                ->select(['id', $nameField])
                ->where(["{$nameField} IS NOT" => null])
                ->all();

            $map = [];
            foreach ($rows as $row) {
                $name = trim((string)$row->{$nameField});
                if ($name !== '') {
                    $map[$name] = $row->id;
                    $map[mb_strtolower($name)] = $row->id;
                }
            }
            $lookups[$field] = $map;
        }

        return $lookups;
    }

    /**
     * Normalize a value for comparison between existing DB value and imported value.
     *
     * @param mixed $value The value to normalize
     * @param string $type The field type from definitions
     * @return string|null Normalized string representation for comparison
     */
    private function normalizeForComparison(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return match ($type) {
            'date' => trim((string)$value),
            'decimal' => rtrim(rtrim(number_format((float)$value, 10, '.', ''), '0'), '.'),
            'integer' => (string)(int)$value,
            'boolean' => (string)(int)(bool)$value,
            default => trim((string)$value),
        };
    }

    /**
     * Filter rowData to only fields that actually differ from existing entity.
     *
     * @param object $existing The existing entity from DB
     * @param array<string, mixed> $rowData Imported row data
     * @param array<string, array> $definitions Field definitions with types
     * @return array<string, mixed> Only the fields that have real changes
     */
    private function filterChangedFields(object $existing, array $rowData, array $definitions): array
    {
        $changed = [];
        foreach ($rowData as $field => $newValue) {
            $type = $definitions[$field]['type'] ?? 'string';
            // FK fields store integer IDs regardless of their declared type
            if (!empty($definitions[$field]['fk'])) {
                $type = 'integer';
            }

            $oldNormalized = $this->normalizeForComparison($existing->get($field), $type);
            $newNormalized = $this->normalizeForComparison($newValue, $type);

            if ($oldNormalized !== $newNormalized) {
                $changed[$field] = $newValue;
            }
        }

        return $changed;
    }
}
