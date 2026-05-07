<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;
use Cake\ORM\TableRegistry;

final class ContabilidadState implements NoveltyPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::CONTABILIDAD;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::RRHH;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        if (empty($novelty->liquidation_doc_id)) {
            return ['La novedad debe estar asignada a un documento de liquidación.'];
        }

        return [];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $hasLiqDoc = $documentsTable->find()
            ->where([
                'liquidation_doc_id' => $doc->id,
                'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
            ])
            ->count();

        if ($hasLiqDoc === 0) {
            return ['Debe subir el documento de liquidación antes de avanzar.'];
        }

        return [];
    }
}
