<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class AgrupacionState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::AGRUPACION;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return null;
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
