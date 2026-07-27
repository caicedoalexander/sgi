<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice;

use App\Constants\InvoiceConstants;
use App\Service\InvoiceHistoryService;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * Promueve a `legalizada` todas las facturas vinculadas al Anticipo dado
 * (Legalización y Recibo de Caja — ver InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES)
 * que estén actualmente en `contabilidad`. Disparado por el subscriber
 * LinkedInvoicesPromoterSubscriber al recibir AdvanceLegalizedEvent.
 *
 * Extraído de InvoicePipelineService como parte del Plan 5 (Domain Events)
 * para liberar al coordinador del conocimiento de Legalización.
 */
final class LinkedInvoiceLegalizer
{
    /**
     * @param \App\Service\InvoiceHistoryService $historyService Audit trail de facturas.
     */
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
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
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
                        // Throwing instead of `return false` ensures the rollback
                        // propagates upstream to AdvanceLegalizationService::_setStatus,
                        // which wraps the leg save + event dispatch in a transaction.
                        // See audit 2026-05-05 (CR-004).
                        throw new RuntimeException(sprintf(
                            'No se pudo promover la factura #%d: %s',
                            $inv->id,
                            json_encode($inv->getErrors()),
                        ));
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
