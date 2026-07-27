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
    /**
     * @param \App\Model\Entity\PettyCashRecord $record Entidad nueva o parcheada.
     * @param mixed $availableInvoices Facturas candidatas a vincular.
     * @param mixed $operationCenters Listado de centros de operación.
     * @param mixed $providers Listado de proveedores.
     * @param array $groupFilters Filtros aplicados a las facturas candidatas.
     */
    public function __construct(
        public PettyCashRecord $record,
        public mixed $availableInvoices,
        public mixed $operationCenters,
        public mixed $providers,
        public array $groupFilters,
    ) {
    }
}
