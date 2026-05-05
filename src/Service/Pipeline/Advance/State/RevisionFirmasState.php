<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

final class RevisionFirmasState implements AdvanceLegalizationPipelineState
{
    public function getName(): string
    {
        return AdvanceConstants::STATUS_REVISION_FIRMAS;
    }

    public function getNext(): ?string
    {
        return AdvanceConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return AdvanceConstants::STATUS_VALIDACION;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        // El avance desde Revisión y Firmas se dispara por markSigned()
        // (acción explícita), no por advance lineal. Sin requirements de campos
        // a este nivel.
        return [];
    }
}
