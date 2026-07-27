<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PaymentScheduling;

/**
 * Datos inmutables de vista para PaymentSchedulingsController::add().
 */
final readonly class PaymentSchedulingAddViewModel
{
    /**
     * @param \App\Model\Entity\PaymentScheduling $record Entidad nueva o parcheada.
     */
    public function __construct(
        public PaymentScheduling $record,
    ) {
    }
}
