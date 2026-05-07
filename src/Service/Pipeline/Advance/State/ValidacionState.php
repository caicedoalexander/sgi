<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\AdvanceConstants;
use App\Constants\Domain\Advance\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;
use Cake\ORM\TableRegistry;

final class ValidacionState implements AdvanceLegalizationPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VALIDACION;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::REVISION_FIRMAS;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return null;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        $linked = $invoices->find()
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            ])
            ->all();

        if ($linked->isEmpty()) {
            $errors[] = 'Vincule al menos una factura antes de avanzar.';
        }

        // MA-006 — toda factura vinculada debe estar en CONTABILIDAD para que
        // LinkedInvoiceLegalizer pueda promoverla al cierre.
        foreach ($linked as $li) {
            if ($li->pipeline_status !== InvoiceConstants::STATUS_CONTABILIDAD) {
                $errors[] = 'Todas las facturas vinculadas deben estar en Contabilidad. '
                    . 'Falta: factura ' . ($li->invoice_number ?: '#' . $li->id);
                break;
            }
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $hasDoc = $sigTable->exists([
            'legalization_id' => $leg->id,
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);
        if (!$hasDoc) {
            $errors[] = 'Debe adjuntar la relación de facturas (PDF).';
        }

        return $errors;
    }
}
