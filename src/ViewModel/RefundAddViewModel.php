<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Refund;

/**
 * Datos pre-calculados que el template `templates/Refunds/add.php` necesita.
 * Construido por `RefundsController::add()` y pasado como `$viewModel`.
 */
final readonly class RefundAddViewModel
{
    /**
     * @param \App\Model\Entity\Refund $record Entidad nueva (vacía).
     * @param array<int, string> $employees Lista [id => "Nombre Apellido1 Apellido2"] ordenada por nombre.
     * @param array<int, string> $providers Lista [id => name] ordenada por nombre.
     * @param iterable $operationCenters Centros de operación (find('codeList')).
     */
    public function __construct(
        public Refund $record,
        public array $employees,
        public array $providers,
        public iterable $operationCenters,
    ) {
    }
}
