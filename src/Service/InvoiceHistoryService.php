<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Trait\HistoryNormalizationTrait;
use Cake\ORM\TableRegistry;

class InvoiceHistoryService implements HistoryServiceInterface
{
    use HistoryNormalizationTrait;

    public const FIELD_LABELS = [
        'invoice_number'      => 'Número de Factura',
        'registration_date'   => 'Fecha de Registro',
        'issue_date'          => 'Fecha de Emisión',
        'due_date'            => 'Fecha de Vencimiento',
        'document_type'       => 'Tipo de Documento',
        'purchase_order'      => 'Orden de Compra',
        'provider_id'         => 'Proveedor',
        'operation_center_id' => 'Centro de Operación',
        'detail'              => 'Detalle',
        'amount'              => 'Valor',
        'expense_type_id'     => 'Tipo de Gasto',
        'cost_center_id'      => 'Centro de Costos',
        'confirmed_by'        => 'Confirmado Por',
        'approver_id'         => 'Aprobador',
        'area_approval'       => 'Aprobación de Área',
        'area_approval_date'  => 'Fecha de Aprobación de Área',
        'dian_validation'     => 'Validación DIAN',
        'accrued'             => 'Causada',
        'accrual_date'        => 'Fecha de Causación',
        'ready_for_payment'   => 'Lista para Pago',
        'payment_status'              => 'Estado de Pago',
        'full_payment_date'           => 'Fecha Pago Total',
        'pipeline_status'             => 'Estado del Pipeline',
        'approvers_modified'          => 'Aprobadores',
        'approver_response'           => 'Respuesta de Aprobador',
        'regression_reason' => 'Motivo de Regresión',
        'payment_edit_reason' => 'Motivo de Edición de Pago',
        // Cambios de pagos individuales (field_changed = 'payment.*' en InvoicePaymentService)
        'payment.status'            => 'Estado del Pago',
        'payment.rejection_reason'  => 'Motivo de Rechazo del Pago',
        'payment.amount'            => 'Monto del Pago',
        'payment.payment_date'      => 'Fecha del Pago',
        'payment.banking_entity_id' => 'Entidad Bancaria del Pago',
    ];

    /**
     * Campos del encabezado de la factura que se auditan campo a campo en
     * recordChanges(). Subconjunto deliberado de FIELD_LABELS: excluye las
     * claves payment.* y approvers_modified, que se registran por otras vías
     * (InvoicePaymentService / InvoiceApprovalService).
     */
    private const FIELDS_TO_TRACK = [
        'invoice_number', 'registration_date', 'issue_date', 'due_date',
        'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
        'detail', 'amount', 'expense_type_id', 'cost_center_id',
        'confirmed_by', 'approver_id', 'area_approval', 'area_approval_date',
        'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
        'payment_status', 'full_payment_date', 'pipeline_status',
    ];

    /**
     * Registra en el historial cada campo rastreado que cambió entre dos versiones.
     *
     * @param \App\Model\Entity\Invoice $original Factura antes de los cambios.
     * @param \App\Model\Entity\Invoice $modified Factura después de los cambios.
     * @param int $userId Id del usuario que realizó los cambios.
     * @return void
     */
    public function recordChanges(Invoice $original, Invoice $modified, int $userId): void
    {
        $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
        $entities = [];

        foreach (self::FIELDS_TO_TRACK as $field) {
            $oldVal = $this->normalizeValue($original->get($field));
            $newVal = $this->normalizeValue($modified->get($field));

            // Normalizar booleanos para comparacion consistente
            if (is_bool($oldVal) || is_bool($newVal)) {
                $oldVal = (bool)$oldVal;
                $newVal = (bool)$newVal;
            }

            if ($oldVal !== $newVal) {
                $entities[] = $historiesTable->newEntity([
                    'invoice_id' => $original->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal !== null ? (string)$oldVal : null,
                    'new_value' => $newVal !== null ? (string)$newVal : null,
                ]);
            }
        }

        if (!empty($entities)) {
            $historiesTable->getConnection()->transactional(function () use ($historiesTable, $entities): void {
                $historiesTable->saveMany($entities);
            });
        }
    }

    /**
     * Registra el cambio de un único campo en el historial de la factura.
     *
     * @param int $invoiceId Id de la factura.
     * @param string $field Nombre del campo modificado.
     * @param string|null $oldValue Valor anterior.
     * @param string|null $newValue Valor nuevo.
     * @param int $userId Id del usuario que realizó el cambio.
     * @return void
     */
    public function recordFieldChange(int $invoiceId, string $field, ?string $oldValue, ?string $newValue, int $userId): void
    {
        $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
        $history = $historiesTable->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'field_changed' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
        $historiesTable->save($history);
    }

    /**
     * Registra en el historial una transición de estado del pipeline (con labels).
     *
     * @param int $invoiceId Id de la factura.
     * @param string $fromStatus Estado de origen.
     * @param string $toStatus Estado de destino.
     * @param int $userId Id del usuario que ejecutó la transición.
     * @return void
     */
    public function recordStatusChange(int $invoiceId, string $fromStatus, string $toStatus, int $userId): void
    {
        $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
        $labels = InvoiceConstants::STATUS_LABELS;

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
