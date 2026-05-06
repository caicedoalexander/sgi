<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Model\Table\InvoicesTable;

/**
 * Datos pre-calculados que el template `templates/Invoices/add.php` necesita.
 *
 * Encapsula la creación de una Factura: defaults (registered_by, pipeline_status,
 * registration_date, due_date fallback) y el patch de la entidad. Creado por
 * simetría con `InvoiceEditViewModel` (audit Plan D — backlog Invoices).
 */
final readonly class InvoiceAddViewModel
{
    /**
     * @param \App\Model\Entity\Invoice $invoice Entidad nueva o parcheada.
     */
    public function __construct(
        public Invoice $invoice,
    ) {
    }

    /**
     * Construir VM para GET (form vacío).
     */
    public static function forForm(InvoicesTable $invoicesTable): self
    {
        return new self($invoicesTable->newEmptyEntity());
    }

    /**
     * Aplica defaults al payload y construye una entidad parcheada.
     *
     * @param array<string, mixed> $data Payload crudo del request.
     */
    public static function fromRequest(InvoicesTable $invoicesTable, array $data, int $userId): self
    {
        $data['registered_by'] = $userId;
        $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
        $data['registration_date'] = date('Y-m-d');
        // Documentos sin vencimiento real (Legalización, Anticipo, etc.) usan
        // la fecha de emisión como vencimiento para satisfacer el NOT NULL.
        if (empty($data['due_date']) && !empty($data['issue_date'])) {
            $data['due_date'] = $data['issue_date'];
        }

        return new self($invoicesTable->patchEntity($invoicesTable->newEmptyEntity(), $data));
    }
}
