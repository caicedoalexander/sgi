<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\PaymentScheduling;

/**
 * Datos inmutables de vista para PaymentSchedulingsController::add().
 */
final class PaymentSchedulingAddViewModel
{
    public function __construct(
        public readonly PaymentScheduling $record,
    ) {
    }
}
