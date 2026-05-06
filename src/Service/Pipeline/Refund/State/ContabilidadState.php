<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class ContabilidadState implements RefundPipelineState
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return RefundConstants::STATUS_CONTABILIDAD;
    }

    /**
     * @inheritDoc
     */
    public function getNext(): ?string
    {
        return RefundConstants::STATUS_TESORERIA;
    }

    /**
     * @inheritDoc
     */
    public function getPrevious(): ?string
    {
        return RefundConstants::STATUS_AGRUPACION;
    }

    /**
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        $errors = [];

        if (empty($record->accrued)) {
            $errors[] = 'El registro debe estar marcado como Causado.';
        }
        if (empty($record->ready_for_payment)) {
            $errors[] = 'Debe seleccionar "Lista para Pago".';
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}
