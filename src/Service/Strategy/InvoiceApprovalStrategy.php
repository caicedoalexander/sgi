<?php
declare(strict_types=1);

namespace App\Service\Strategy;

use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePipelineService;
use App\Service\StructuredLogger;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use DateTime;

class InvoiceApprovalStrategy implements ApprovalStrategyInterface
{
    private StructuredLogger $logger;

    private ?int $adminRoleId = null;

    /**
     * @param \App\Service\InvoiceHistoryService $historyService History service.
     * @param \App\Service\InvoicePipelineService $pipeline Invoice pipeline service.
     */
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
        private readonly InvoicePipelineService $pipeline,
    ) {
        $this->logger = new StructuredLogger('Strategy.InvoiceApproval');
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

        if ($action === ApprovalConstants::ACTION_APPROVE) {
            $result = $this->pipeline->saveAndAdvance(
                $invoice,
                [
                    'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
                    'area_approval_date' => $parsedDate,
                ],
                $this->_getAdminRoleId(),
                RoleConstants::ADMIN,
                $userId,
            );

            if ($result->success && !empty($observations)) {
                $this->_saveObservation($entityId, $observations, $userId);
            }

            return $result->success;
        }

        if ($action === ApprovalConstants::ACTION_REJECT) {
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
        } catch (RecordNotFoundException $e) {
            // Finder nullable: la factura puede no existir si fue eliminada después de emitir el token.
            $this->logger->warning('entity_not_found', [
                'method' => __METHOD__,
                'exception' => $e->getMessage(),
            ]);

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

    /**
     * Resuelve el id del rol Administrador. La aprobación externa actúa como admin
     * para bypassear restricciones de rol/estado en saveAndAdvance.
     */
    private function _getAdminRoleId(): int
    {
        if ($this->adminRoleId === null) {
            $rolesTable = TableRegistry::getTableLocator()->get('Roles');
            $adminRole = $rolesTable->find()
                ->where(['name' => RoleConstants::ADMIN])
                ->firstOrFail();
            $this->adminRoleId = (int)$adminRole->id;
        }

        return $this->adminRoleId;
    }
}
