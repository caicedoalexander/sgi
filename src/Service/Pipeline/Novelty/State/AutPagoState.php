<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class AutPagoState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_AUT_PAGO;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_PAGADA;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_TESORERIA;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['La autorización de pago se gestiona desde la sección de pagos.'];
    }
}
