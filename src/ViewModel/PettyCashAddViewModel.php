<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PettyCashRecord;

/**
 * Datos inmutables de vista para PettyCashRecordsController::add().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final class PettyCashAddViewModel
{
    public function __construct(
        public readonly PettyCashRecord $record,
        public readonly mixed $availableInvoices,
        public readonly mixed $operationCenters,
        public readonly mixed $providers,
        public readonly array $groupFilters,
    ) {
    }
}
