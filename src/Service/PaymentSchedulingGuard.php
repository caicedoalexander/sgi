<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * Consultas de soporte para las validaciones de avance del pipeline de
 * programación de pagos.
 *
 * Encapsula el acceso a BD (conteo de items vinculados) que antes vivía inline
 * dentro de BorradorState, manteniendo el State puro (espejo de
 * NoveltyLiquidationGuard).
 */
class PaymentSchedulingGuard
{
    /**
     * True si la programación tiene al menos un item (factura) vinculado.
     */
    public function hasLinkedItems(int $schedulingId): bool
    {
        return TableRegistry::getTableLocator()->get('PaymentSchedulingItems')
            ->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->count() > 0;
    }
}
