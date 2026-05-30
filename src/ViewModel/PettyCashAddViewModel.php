<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PettyCashRecord;

/**
 * Datos inmutables de vista para PettyCashRecordsController::add().
 * El controller construye este objeto; la vista accede via get_object_vars().
 */
final readonly class PettyCashAddViewModel
{
    public function __construct(
        public PettyCashRecord $record,
        public mixed $availableInvoices,
        public mixed $operationCenters,
        public mixed $providers,
        public array $groupFilters,
    ) {
    }
}
