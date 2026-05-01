<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class LegalizadaState implements InvoicePipelineState
{
    public function getName(): string
    {
        return InvoiceConstants::STATUS_LEGALIZADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        return [];
    }

    public function getTransitionRules(): array
    {
        return [];
    }
}
