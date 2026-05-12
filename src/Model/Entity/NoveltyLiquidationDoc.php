<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\NoveltyConstants;
use Cake\ORM\Entity;

class NoveltyLiquidationDoc extends Entity
{
    protected array $_accessible = [
        'liquidation_number' => true,
        'period' => true,
        'pipeline_status' => true,
        'document_date' => true,
        'performed_by' => true,
        'passes_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'created_by' => true,
    ];

    // -----------------------------------------------------------------
    // State-machine predicates (permissions-unification 2026-05-12, PR4).
    //
    // Encapsulan solo estado del agregado — la composición con rol vive
    // en NoveltyActionPolicy: canOperateStep($roleId, $step) && $doc->canX().
    // -----------------------------------------------------------------

    /** @return bool true cuando Tesorería puede registrar un nuevo pago del doc. */
    public function canRegisterPayment(): bool
    {
        return ($this->pipeline_status ?? '') === NoveltyConstants::STATUS_TESORERIA;
    }

    /** @return bool true cuando Contador puede autorizar un pago pendiente. */
    public function canAuthorizePayment(): bool
    {
        return ($this->pipeline_status ?? '') === NoveltyConstants::STATUS_AUTORIZACION_PAGO;
    }

    /** @return bool alias de canAuthorizePayment para el verbo "reject" (mismo estado). */
    public function canRejectPayment(): bool
    {
        return $this->canAuthorizePayment();
    }

    /** @return bool true cuando Tesorería puede confirmar la ejecución del pago. */
    public function canConfirmPayment(): bool
    {
        return ($this->pipeline_status ?? '') === NoveltyConstants::STATUS_VERIFICACION_PAGO;
    }
}
