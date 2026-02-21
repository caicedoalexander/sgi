<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Invoice;
use Cake\ORM\TableRegistry;

class InvoiceHistoryService
{
    public function recordChanges(Invoice $original, Invoice $modified, int $userId): void
    {
        $fieldsToTrack = [
            'invoice_number', 'registration_date', 'issue_date', 'due_date',
            'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
            'detail', 'amount', 'expense_type_id', 'cost_center_id',
            'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
            'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
            'payment_status', 'payment_date', 'pipeline_status',
        ];

        $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');

        foreach ($fieldsToTrack as $field) {
            $oldVal = $original->get($field);
            $newVal = $modified->get($field);

            if ($oldVal != $newVal) {
                $history = $historiesTable->newEntity([
                    'invoice_id' => $original->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal !== null ? (string)$oldVal : null,
                    'new_value' => $newVal !== null ? (string)$newVal : null,
                ]);
                $historiesTable->save($history);
            }
        }
    }

    public function recordStatusChange(int $invoiceId, string $fromStatus, string $toStatus, int $userId): void
    {
        $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
        $labels = InvoicePipelineService::STATUS_LABELS;

        $history = $historiesTable->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'field_changed' => 'pipeline_status',
            'old_value' => $labels[$fromStatus] ?? $fromStatus,
            'new_value' => $labels[$toStatus] ?? $toStatus,
        ]);
        $historiesTable->save($history);
    }
}
