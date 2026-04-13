<?php
declare(strict_types=1);

namespace App\Service\Strategy;

use App\Constants\NoveltyConstants;
use App\Service\NoveltyObservationService;
use Cake\ORM\TableRegistry;
use DateTime;
use Exception;

class NoveltyApprovalStrategy implements ApprovalStrategyInterface
{
    private NoveltyObservationService $observationService;

    /**
     * @param \App\Service\NoveltyObservationService|null $observationService Observation service.
     */
    public function __construct(?NoveltyObservationService $observationService = null)
    {
        $this->observationService = $observationService ?? new NoveltyObservationService();
    }

    /**
     * @inheritDoc
     */
    public function apply(
        int $entityId,
        string $action,
        ?string $observations,
        ?int $createdBy,
        ?string $approvalDate = null,
    ): bool {
        $table = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty = $table->get($entityId);

        if ($action === 'approve') {
            $novelty->pipeline_status = NoveltyConstants::STATUS_RRHH;
            $novelty->area_approval = NoveltyConstants::APPROVAL_APPROVED;
            $novelty->approved_by = $novelty->approver_id;
            $novelty->approved_at = new DateTime();
        } elseif ($action === 'reject') {
            $novelty->area_approval = NoveltyConstants::APPROVAL_REJECTED;
        }

        $saved = (bool)$table->save($novelty);

        if ($saved && !empty($observations)) {
            $userId = $createdBy ?? $novelty->approver_id ?? 0;
            $this->observationService->addToNovelty($entityId, $userId, $observations);
        }

        return $saved;
    }

    /**
     * @inheritDoc
     */
    public function getEntity(int $entityId): ?object
    {
        $table = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        try {
            return $table->get($entityId, contain: ['Employees', 'NoveltyTypes', 'Approvers']);
        } catch (Exception $e) {
            return null;
        }
    }
}
