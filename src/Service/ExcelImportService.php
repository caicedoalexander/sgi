<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Excel\ExcelExportableInterface;
use Cake\ORM\TableRegistry;
use DateTime;
use DateTimeInterface;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ExcelImportService
{
    /**
     * @param \App\Service\ExcelMappingService $mappingService Mapping service instance.
     */
    public function __construct(
        private readonly ExcelMappingService $mappingService,
    ) {
    }

    /**
     * Read headers from an uploaded Excel file saved as temp.
     *
     * @param string $tempFilePath Path to temp file
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

        return array_values(array_filter($headers, fn($h) => $h !== ''));
    }

    /**
     * Process a previously-uploaded Excel file using a user-provided column mapping.
     *
     * @param string $tempFilePath Path to the temporary Excel file
     * @param \App\Model\Excel\ExcelExportableInterface $table A Table implementing the interface.
     *        Both an ORM table type and the interface are required, but PHP cannot express
     *        the intersection in a portable signature; runtime check enforces it.
     * @param array<string, string> $mapping file_header => system_field
     * @param array<string> $enabledHeaders headers the user kept enabled in the wizard
     * @param int $userId User performing the import
     * @return \App\Service\ImportResult
     */
    public function processImport(
        string $tempFilePath,
        ExcelExportableInterface $table,
        array $mapping,
        array $enabledHeaders,
        int $userId,
    ): ImportResult {
        $result = new ImportResult();
        $definitions = $table->getExcelFields();

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

        $validationErrors = $this->mappingService->validateMapping($mapping, $definitions);
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
        $skipSystemFields = ['id', 'created', 'modified', 'profile_image'];

        $fkCodeLookups = $this->buildFkLookups($definitions);
        $fkNameLookups = $this->buildFkNameLookups($definitions);

        $rowCount = count($rows);
        for ($i = 1; $i < $rowCount; $i++) {
            $rowData = [];
            $rowNum = $i + 1;

            foreach ($headers as $col => $header) {
                if (!in_array($header, $enabledHeaders, true)) {
                    continue;
                }
                $systemField = $mapping[$header] ?? null;
                if (!$systemField) {
                    continue;
                }
                if (in_array($systemField, $skipSystemFields, true)) {
                    continue;
                }
                $fieldDef = $definitions[$systemField] ?? null;
                $rawValue = $rows[$i][$col] ?? null;
                $castValue = $this->castValue($rawValue, $fieldDef['type'] ?? 'string');

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
                        $targetField = $fieldDef['fk_target'];
                        if (!isset($rowData[$targetField])) {
                            $rowData[$targetField] = $resolvedId;
                        }
                    }
                    continue;
                }

                if (!empty($fieldDef['display_only'])) {
                    continue;
                }

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

            $keyValue = trim((string)($rowData[$keyField] ?? ''));
            if ($keyValue === '') {
                $result->skipped++;
                continue;
            }

            $existing = $table->find()
                ->where([$keyField => $keyValue])
                ->first();

            if ($existing) {
                $changedData = $this->filterChangedFields($existing, $rowData, $definitions);
                if (empty($changedData)) {
                    $result->unchanged++;
                    continue;
                }
                $originalClone = clone $existing;
                $entity = $table->patchEntity($existing, $changedData);
                if ($table->save($entity)) {
                    $result->updated++;
                    $table->onExcelImportUpdated($originalClone, $entity, $userId);
                } else {
                    $result->errors[] = $this->formatEntityErrors($entity, $rowNum, $definitions);
                }
            } else {
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
                if ($table->save($entity)) {
                    $result->created++;
                    $table->onExcelImportCreated($entity, $userId);
                } else {
                    $result->errors[] = $this->formatEntityErrors($entity, $rowNum, $definitions);
                }
            }
        }

        return $result;
    }

    /**
     * @param object $entity The entity that failed to save
     * @param int $rowNum 1-based row number for error message
     * @param array<string, array<string, mixed>> $definitions
     * @return string
     */
    private function formatEntityErrors(object $entity, int $rowNum, array $definitions): string
    {
        $errors = method_exists($entity, 'getErrors') ? $entity->getErrors() : [];
        $msg = "Fila {$rowNum}: ";
        foreach ($errors as $field => $fieldErrors) {
            $label = $definitions[$field]['label'] ?? $field;
            $msg .= "{$label}: " . implode(', ', $fieldErrors) . '. ';
        }

        return trim($msg);
    }

    /**
     * Cast a raw Excel value to the expected PHP type.
     *
     * @param mixed $rawValue Raw cell value
     * @param string $type Field type
     * @return mixed
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
     * @param mixed $value Raw date value
     * @return string|null
     */
    private function parseDate(mixed $value): ?string
    {
        if (is_numeric($value) && (float)$value > 1000) {
            try {
                $dateObj = Date::excelToDateTimeObject((float)$value);

                return $dateObj->format('Y-m-d');
            } catch (Exception) {
                return null;
            }
        }

        $strValue = trim((string)$value);
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
     * @param mixed $value Raw decimal value
     * @return float|null
     */
    private function parseDecimal(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        $cleaned = str_replace(['.', '$', ' '], '', (string)$value);
        $cleaned = str_replace(',', '.', $cleaned);

        return is_numeric($cleaned) ? (float)$cleaned : null;
    }

    /**
     * @param mixed $value Raw boolean value
     * @return bool|null
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
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, array<string, int>>
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
            $rows = $fkTable->find()->select(['id', $codeField])
                ->where(["{$codeField} IS NOT" => null])->all();
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
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, array<string, int>>
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
            $rows = $fkTable->find()->select(['id', $nameField])
                ->where(["{$nameField} IS NOT" => null])->all();
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
     * Normalize a value for change-detection between DB and import values.
     *
     * @param mixed $value Raw value
     * @param string $type Field type
     * @return string|null
     */
    private function normalizeForComparison(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toNative')) {
            return $value->toNative()->format('Y-m-d');
        }
        if ($value instanceof DateTimeInterface) {
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
     * @param object $existing Current entity in DB
     * @param array<string, mixed> $rowData Imported row data
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    private function filterChangedFields(object $existing, array $rowData, array $definitions): array
    {
        $changed = [];
        foreach ($rowData as $field => $newValue) {
            $type = $definitions[$field]['type'] ?? 'string';
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
