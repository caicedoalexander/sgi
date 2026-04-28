<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Query\SelectQuery;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelService
{
    /**
     * Export data with custom field selection, ordering, and Spanish labels.
     *
     * @param string $sheetTitle Title for the Excel sheet
     * @param \Cake\ORM\Query\SelectQuery $query The query to export
     * @param array<string> $fields Ordered list of field names to export
     * @param array<string, string> $labelMap Map of field_name => Spanish label for headers
     */
    public function exportWithLabels(
        string $sheetTitle,
        SelectQuery $query,
        array $fields,
        array $labelMap,
    ): string {
        $results = $query->all()->toArray();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        if (empty($results)) {
            $sheet->setCellValue('A1', 'Sin datos');
        } else {
            // Headers with Spanish labels
            foreach ($fields as $col => $field) {
                $cell = Coordinate::stringFromColumnIndex($col + 1) . '1';
                $sheet->setCellValue($cell, $labelMap[$field] ?? $field);
                $sheet->getStyle($cell)->getFont()->setBold(true);
            }

            // Data rows
            foreach ($results as $rowNum => $entity) {
                $row = method_exists($entity, 'toArray') ? $entity->toArray() : (array)$entity;
                foreach ($fields as $col => $field) {
                    $value = $row[$field] ?? '';
                    if ($value instanceof DateTimeInterface) {
                        $value = $value->format('Y-m-d');
                    }
                    $cell = Coordinate::stringFromColumnIndex($col + 1) . ($rowNum + 2);
                    $sheet->setCellValue($cell, $value);
                }
            }

            // Auto-size
            foreach ($fields as $col => $field) {
                $colLetter = Coordinate::stringFromColumnIndex($col + 1);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'sgi_export_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }
}
