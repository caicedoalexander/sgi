<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class RevisionFirmasState implements NoveltyPipelineState
{
    public function getName(): string
    {
        return NoveltyConstants::STATUS_REVISION_FIRMAS;
    }

    public function getNext(): ?string
    {
        return NoveltyConstants::STATUS_GDP;
    }

    public function getPrevious(): ?string
    {
        return NoveltyConstants::STATUS_CONTABILIDAD;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['Esta etapa solo avanza desde el documento de liquidación grupal.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $errors = [];
        $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

        $totalSlots = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
            ])
            ->count();

        $signedCount = $signaturesTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
                'signature_path IS NOT' => null,
            ])
            ->count();

        if ($signedCount < $totalSlots) {
            $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
        }

        $firstMember = TableRegistry::getTableLocator()->get('EmployeeNovelties')
            ->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $doc->id])
            ->first();

        if ($firstMember && $firstMember->novelty_type && !$firstMember->novelty_type->requires_employee_signature_review) {
            if ($doc->passes_for_payment === null) {
                $errors[] = 'Debe indicar si "Pasa para Pago".';
            }
        }

        return $errors;
    }
}
