<?php
declare(strict_types=1);

namespace App\Service\Adapter;

use App\Service\Interface\SpreadsheetReaderInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Adapter that wraps PhpSpreadsheet for reading spreadsheet files.
 */
class PhpSpreadsheetAdapter implements SpreadsheetReaderInterface
{
    /**
     * @inheritDoc
     */
    public function load(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        return $sheet->toArray();
    }
}
