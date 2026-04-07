<?php
declare(strict_types=1);

namespace App\Service\Trait;

use DateTimeInterface;

trait HistoryNormalizationTrait
{
    /**
     * Normalize a value for comparison and storage.
     *
     * Converts DateTime to 'Y-m-d', empty string to null. Bools pass through.
     *
     * @param mixed $value Value to normalize.
     * @return mixed Normalized value.
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Normalize a value to its string representation for history storage.
     *
     * @param mixed $value Value to normalize.
     * @return string
     */
    protected function normalizeToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string)$value;
    }
}
