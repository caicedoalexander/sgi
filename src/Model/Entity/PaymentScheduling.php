<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\Entity;

class PaymentScheduling extends Entity
{
    protected array $_accessible = [
        'code' => false,
        'operation_center_id' => true,
        'title' => true,
        'pipeline_status' => true,
        'created_by' => true,
    ];

    public function isPagada(): bool
    {
        return ($this->pipeline_status ?? '') === PaymentSchedulingConstants::STATUS_PAGADA;
    }

    // -----------------------------------------------------------------
    // State-machine predicates (permissions-unification 2026-05-12, PR1).
    //
    // Encapsulan solo estado del agregado — la composición con rol vive en
    // PaymentSchedulingActionPolicy: _canOperate($roleId, $step) && $scheduling->canX().
    // -----------------------------------------------------------------

    /** @return bool true cuando Contador puede rechazar desde Autorización de pago. */
    public function canReject(): bool
    {
        return ($this->pipeline_status ?? '') === PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO;
    }

    /** @return bool true cuando Tesorería puede confirmar la ejecución del pago. */
    public function canConfirmPayment(): bool
    {
        return ($this->pipeline_status ?? '') === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO;
    }
}
