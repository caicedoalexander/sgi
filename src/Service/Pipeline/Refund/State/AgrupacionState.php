<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class AgrupacionState implements RefundPipelineState
{
    /**
     * Estado canónico `agrupacion` de este State.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AGRUPACION;
    }

    /**
     * Estado siguiente del pipeline; delega en el enum. Null si es terminal.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior del pipeline; delega en el enum. Null si es el primero o la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Refund\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * @inheritDoc
     */
    public function validateAdvance(Refund $record): array
    {
        $errors = [];
        $type = $record->beneficiary_type;

        if ($type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
            if (empty($record->beneficiary_employee_id)) {
                $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
            }
        } elseif ($type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
            if (empty($record->beneficiary_provider_id)) {
                $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
            }
        } else {
            $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
        }

        return $errors;
    }
}
