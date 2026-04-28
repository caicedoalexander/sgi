<?php
declare(strict_types=1);

namespace App\Model\Excel;

use Cake\Datasource\EntityInterface;

/**
 * Tables that opt into the Excel wizard implement this interface.
 *
 * Field definition shape (returned by getExcelFields()):
 *   'field_name' => [
 *     'label'        => string,                    // Spanish header in UI/export
 *     'type'         => 'string'|'date'|'decimal'|'integer'|'boolean',
 *     'required'?    => bool,                      // mapping must include this on import
 *     'required_new'?=> bool,                      // mandatory only when creating a new row
 *     'is_key'?      => bool,                      // upsert key (exactly one per Table)
 *     'aliases'?     => array<string>,             // alternative file headers for auto-mapping
 *     'fk'?          => bool,                      // foreign key resolved from a code/name
 *     'fk_table'?    => string,                    // ORM table alias of the related entity
 *     'fk_code'?     => string,                    // column in fk_table holding the code
 *     'display_only'?=> bool,                      // exported only; on import resolves via fk_resolve
 *     'fk_resolve'?  => string,                    // column in fk_table to look up by name
 *     'fk_target'?   => string,                    // sibling field receiving the resolved id
 *   ]
 */
interface ExcelExportableInterface
{
    /**
     * Field definitions used by the Excel wizard for export and import.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getExcelFields(): array;

    /**
     * Sheet title used in the generated XLSX workbook.
     *
     * @return string
     */
    public function getExcelSheetTitle(): string;

    /**
     * Slug used to build the export filename (no extension).
     *
     * @return string
     */
    public function getExcelDownloadSlug(): string;

    /**
     * Whether the module accepts Excel imports.
     *
     * @return bool
     */
    public function isExcelImportable(): bool;

    /**
     * Associations to eagerly load when exporting.
     *
     * @return array<int|string, mixed>
     */
    public function getExcelExportContains(): array;

    /**
     * Hook invoked after a new entity is created via Excel import.
     *
     * @param \Cake\Datasource\EntityInterface $entity
     * @param int $userId
     * @return void
     */
    public function onExcelImportCreated(EntityInterface $entity, int $userId): void;

    /**
     * Hook invoked after an existing entity is updated via Excel import.
     *
     * @param \Cake\Datasource\EntityInterface $original
     * @param \Cake\Datasource\EntityInterface $entity
     * @param int $userId
     * @return void
     */
    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void;
}
