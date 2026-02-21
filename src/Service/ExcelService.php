<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Http\Message\UploadedFileInterface;

class ExcelService
{
    /**
     * Export a catalog query to an XLSX file.
     * Returns the file path of the generated file.
     */
    public function exportCatalog(string $tableName, SelectQuery $query): string
    {
        $results = $query->all()->toArray();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($tableName);

        if (empty($results)) {
            $sheet->setCellValue('A1', 'Sin datos');
        } else {
            // Headers from first row keys
            $firstRow = $results[0]->toArray();
            $headers = array_keys($firstRow);
            foreach ($headers as $col => $header) {
                $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
                $sheet->getStyleByColumnAndRow($col + 1, 1)->getFont()->setBold(true);
            }

            // Data rows
            foreach ($results as $rowNum => $entity) {
                $row = $entity->toArray();
                foreach ($headers as $col => $header) {
                    $value = $row[$header] ?? '';
                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i:s');
                    }
                    $sheet->setCellValueByColumnAndRow($col + 1, $rowNum + 2, $value);
                }
            }

            // Auto-size columns
            foreach ($headers as $col => $header) {
                $sheet->getColumnDimensionByColumn($col + 1)->setAutoSize(true);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'sgi_export_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Import a catalog from an XLSX file.
     * Uses 'code' field as unique identifier for upsert.
     */
    public function importCatalog(string $tableName, UploadedFileInterface $file): ImportResult
    {
        $result = new ImportResult();

        // Save uploaded file temporarily
        $tempFile = tempnam(sys_get_temp_dir(), 'sgi_import_');
        $file->moveTo($tempFile);

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
        } catch (\Exception $e) {
            $result->errors[] = 'No se pudo leer el archivo: ' . $e->getMessage();
            return $result;
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        if (count($rows) < 2) {
            $result->errors[] = 'El archivo está vacío o solo tiene encabezados.';
            return $result;
        }

        $headers = array_map('trim', $rows[0]);
        $codeIndex = array_search('code', $headers);

        if ($codeIndex === false) {
            $result->errors[] = 'El archivo debe contener una columna "code".';
            return $result;
        }

        $table = TableRegistry::getTableLocator()->get($tableName);

        // Skip system fields
        $skipFields = ['id', 'created', 'modified'];

        for ($i = 1; $i < count($rows); $i++) {
            $rowData = [];
            foreach ($headers as $col => $header) {
                if (in_array($header, $skipFields)) {
                    continue;
                }
                $rowData[$header] = $rows[$i][$col] ?? null;
            }

            $code = trim((string)($rowData['code'] ?? ''));
            if (empty($code)) {
                $result->skipped++;
                continue;
            }

            // Check if exists
            $existing = $table->find()
                ->where(['code' => $code])
                ->first();

            if ($existing) {
                $entity = $table->patchEntity($existing, $rowData);
                if ($table->save($entity)) {
                    $result->updated++;
                } else {
                    $errors = $entity->getErrors();
                    $errorMsg = "Fila {$i}: ";
                    foreach ($errors as $field => $fieldErrors) {
                        $errorMsg .= "$field: " . implode(', ', $fieldErrors) . '. ';
                    }
                    $result->errors[] = $errorMsg;
                }
            } else {
                $entity = $table->newEntity($rowData);
                if ($table->save($entity)) {
                    $result->created++;
                } else {
                    $errors = $entity->getErrors();
                    $errorMsg = "Fila {$i}: ";
                    foreach ($errors as $field => $fieldErrors) {
                        $errorMsg .= "$field: " . implode(', ', $fieldErrors) . '. ';
                    }
                    $result->errors[] = $errorMsg;
                }
            }
        }

        return $result;
    }
}
