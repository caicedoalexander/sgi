<?php
declare(strict_types=1);

namespace App\Model\Excel;

use Cake\Datasource\EntityInterface;

/**
 * Default no-op implementations for the optional hooks of ExcelExportableInterface.
 * Tables still must implement getExcelFields(), getExcelSheetTitle(), getExcelDownloadSlug().
 */
trait ExcelExportableTrait
{
    /**
     * @return bool
     */
    public function isExcelImportable(): bool
    {
        return true;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getExcelExportContains(): array
    {
        return [];
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity
     * @param int $userId
     * @return void
     */
    public function onExcelImportCreated(EntityInterface $entity, int $userId): void
    {
    }

    /**
     * @param \Cake\Datasource\EntityInterface $original
     * @param \Cake\Datasource\EntityInterface $entity
     * @param int $userId
     * @return void
     */
    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void
    {
    }
}
