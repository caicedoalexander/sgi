<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class GdpState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_GDP;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_REVISION_FIRMAS;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];

        if ($doc->passes_for_payment === null) {
            $errors[] = 'Debe indicar si "Pasa para Pago".';
        }

        $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
        $workerSlot = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'signer_type' => NoveltyConstants::SIGNER_TRABAJADOR,
            ])
            ->first();

        if ($workerSlot && empty($workerSlot->signature_path)) {
            $errors[] = 'La firma del trabajador es requerida para avanzar.';
        }

        return $errors;
    }
}
