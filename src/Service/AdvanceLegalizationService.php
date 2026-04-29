<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use Cake\ORM\TableRegistry;

class AdvanceLegalizationService
{
    /**
     * Idempotently create the advance_legalizations row for a paid Anticipo.
     *
     * @param \App\Model\Entity\Invoice $advance Anticipo invoice (must be in `pagada`).
     * @param int $userId User id triggering the initialization.
     * @return \App\Service\ServiceResult
     */
    public function initialize(Invoice $advance, int $userId): ServiceResult
    {
        if (($advance->document_type ?? null) !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            return ServiceResult::fail('Solo los Anticipos pueden iniciar legalización.');
        }
        if (($advance->pipeline_status ?? null) !== InvoiceConstants::STATUS_PAGADA) {
            return ServiceResult::fail('El anticipo debe estar Pagada para iniciar legalización.');
        }

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $existing = $table->find()->where(['advance_invoice_id' => $advance->id])->first();
        if ($existing) {
            return ServiceResult::ok($existing);
        }

        $entity = $table->newEntity([
            'advance_invoice_id' => $advance->id,
            'status' => AdvanceConstants::STATUS_VALIDACION,
            'created_by' => $userId,
        ]);

        if (!$table->save($entity)) {
            return ServiceResult::fail(
                'No se pudo crear la legalización: ' . json_encode($entity->getErrors()),
            );
        }

        return ServiceResult::ok($entity);
    }
}
