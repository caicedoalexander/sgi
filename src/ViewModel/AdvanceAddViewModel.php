<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Model\Table\InvoicesTable;

/**
 * Datos pre-calculados que el template `templates/Advances/add.php` necesita.
 * Encapsula la creación de un Anticipo: defaults, validación de beneficiario
 * y lista blanca de campos accesibles (audit CR-001 — bloquea mass-assignment
 * de approver_id, area_approval, payment_status, confirmed_by, accrued, advance_id).
 */
final readonly class AdvanceAddViewModel
{
    private const ALLOWED_FIELDS = [
        'provider_id', 'employee_id', 'operation_center_id',
        'expense_type_id', 'cost_center_id', 'amount', 'detail',
        'issue_date', 'due_date', 'document_type', 'registered_by',
        'pipeline_status', 'registration_date',
    ];

    private const BLOCKED_FIELDS = [
        'approver_id', 'area_approval', 'payment_status',
        'confirmed_by', 'accrued', 'advance_id',
    ];

    /**
     * @param \App\Model\Entity\Invoice $invoice Entidad nueva o parcheada.
     * @param array<string, mixed> $dropdowns Listas para los <select> del form.
     * @param array<int, string> $errors Errores de validación a nivel del VM.
     */
    public function __construct(
        public Invoice $invoice,
        public array $dropdowns,
        public array $errors = [],
    ) {
    }

    /**
     * @param array<string, mixed> $dropdowns
     */
    public static function forForm(InvoicesTable $invoicesTable, array $dropdowns): self
    {
        return new self($invoicesTable->newEmptyEntity(), $dropdowns);
    }

    /**
     * @param array<string, mixed> $data Payload crudo del request.
     * @param array<string, mixed> $dropdowns
     */
    public static function fromRequest(
        InvoicesTable $invoicesTable,
        array $data,
        int $userId,
        array $dropdowns,
    ): self {
        $data['document_type'] = InvoiceConstants::DOCTYPE_ANTICIPO;
        $data['registered_by'] = $userId;
        $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
        $data['registration_date'] = date('Y-m-d');
        // Anticipos no tienen fecha de vencimiento; usamos la de emisión.
        if (empty($data['due_date']) && !empty($data['issue_date'])) {
            $data['due_date'] = $data['issue_date'];
        }

        $errors = [];
        if (empty($data['provider_id']) && empty($data['employee_id'])) {
            $errors[] = 'Debe seleccionar un proveedor o un empleado como beneficiario.';

            return new self($invoicesTable->newEmptyEntity(), $dropdowns, $errors);
        }

        $accessibleFields = array_fill_keys(self::ALLOWED_FIELDS, true)
            + array_fill_keys(self::BLOCKED_FIELDS, false);

        $invoice = $invoicesTable->patchEntity(
            $invoicesTable->newEmptyEntity(),
            $data,
            ['accessibleFields' => $accessibleFields],
        );

        return new self($invoice, $dropdowns, $errors);
    }
}
