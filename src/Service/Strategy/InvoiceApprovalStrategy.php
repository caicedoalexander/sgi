<?php
declare(strict_types=1);

namespace App\Service\Strategy;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePipelineService;
use Cake\ORM\TableRegistry;
use DateTime;
use Exception;

class InvoiceApprovalStrategy implements ApprovalStrategyInterface
{
    /**
     * @param \App\Service\InvoiceHistoryService $historyService History service.
     * @param \App\Service\InvoicePipelineService $pipeline Invoice pipeline service.
     */
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
        private readonly InvoicePipelineService $pipeline,
    ) {
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
        $table = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $table->get($entityId, contain: ['Providers']);

        $userId = $createdBy ?? 0;
        $parsedDate = !empty($approvalDate) ? new DateTime($approvalDate) : new DateTime();

        if ($action === 'approve') {
            $result = $this->pipeline->saveAndAdvance(
                $invoice,
                [
                    'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
                    'area_approval_date' => $parsedDate,
                ],
                RoleConstants::ADMIN,
                $userId,
            );

            if ($result['saved'] && !empty($observations)) {
                $this->_saveObservation($entityId, $observations, $userId);
            }

            return $result['saved'];
        }

        if ($action === 'reject') {
            $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
            $invoice->area_approval_date = $parsedDate;

            if (!$table->save($invoice)) {
                return false;
            }

            $this->historyService->recordFieldChange(
                $entityId,
                'area_approval',
                InvoiceConstants::APPROVAL_PENDING,
                InvoiceConstants::APPROVAL_REJECTED,
                $userId,
            );

            if (!empty($observations)) {
                $this->_saveObservation($entityId, $observations, $userId);
            }

            return true;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getEntity(int $entityId): ?object
    {
        $table = TableRegistry::getTableLocator()->get('Invoices');

        try {
            return $table->get($entityId, contain: ['Providers', 'InvoiceDocuments']);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param int $invoiceId Invoice ID.
     * @param string $message Observation message.
     * @param int $userId User ID.
     * @return void
     */
    private function _saveObservation(int $invoiceId, string $message, int $userId): void
    {
        $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');
        $observation = $observationsTable->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'message' => $message,
        ]);
        $observationsTable->save($observation);
    }
}
