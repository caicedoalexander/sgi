<?php
declare(strict_types=1);

namespace App\Service;

class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public array $errors = [];

    public function getSummary(): string
    {
        $parts = [];
        if ($this->created > 0) {
            $parts[] = "{$this->created} creados";
        }
        if ($this->updated > 0) {
            $parts[] = "{$this->updated} actualizados";
        }
        if ($this->skipped > 0) {
            $parts[] = "{$this->skipped} omitidos";
        }
        if (!empty($this->errors)) {
            $parts[] = count($this->errors) . ' errores';
        }

        return implode(', ', $parts) ?: 'Sin cambios';
    }
}
