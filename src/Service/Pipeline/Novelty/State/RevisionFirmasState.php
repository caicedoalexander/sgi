<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class RevisionFirmasState implements NoveltyPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::GDP;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
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
