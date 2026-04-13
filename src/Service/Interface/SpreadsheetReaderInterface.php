<?php
declare(strict_types=1);

namespace App\Service\Interface;

interface SpreadsheetReaderInterface
{
    /**
     * Load a spreadsheet file and return its rows as arrays.
     *
     * @param string $filePath Path to the spreadsheet file.
     * @return array Array of row arrays.
     */
    public function load(string $filePath): array;
}
