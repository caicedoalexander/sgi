<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Constants\InvoiceConstants;
use App\Service\InvoiceHistoryService;
use Cake\ORM\TableRegistry;

/**
 * Promueve a `legalizada` todas las facturas tipo Legalización vinculadas al
 * Anticipo dado que estén actualmente en `contabilidad`. Disparado por el
 * subscriber LinkedInvoicesPromoterSubscriber al recibir AdvanceLegalizedEvent.
 *
 * Extraído de InvoicePipelineService como parte del Plan 5 (Domain Events)
 * para liberar al coordinador del conocimiento de Legalización.
 */
final class LinkedInvoiceLegalizer
{
    public function __construct(
        private readonly InvoiceHistoryService $historyService,
    ) {
    }

    /**
     * @return int Cantidad de facturas promovidas.
     */
    public function legalizeFor(int $advanceInvoiceId, int $userId): int
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $linked = $invoicesTable->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            ])
            ->all();

        if ($linked->isEmpty()) {
            return 0;
        }

        $count = 0;
        $invoicesTable->getConnection()->transactional(
            function () use ($linked, $userId, &$count, $invoicesTable): bool {
                foreach ($linked as $inv) {
                    $from = $inv->pipeline_status;
                    $inv->pipeline_status = InvoiceConstants::STATUS_LEGALIZADA;
                    if (!$invoicesTable->save($inv)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $inv->id,
                        $from,
                        InvoiceConstants::STATUS_LEGALIZADA,
                        $userId,
                    );
                    $count++;
                }

                return true;
            },
        );

        return $count;
    }
}
