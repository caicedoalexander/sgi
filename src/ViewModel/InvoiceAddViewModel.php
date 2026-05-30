<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Invoice;

/**
 * Datos pre-calculados que el template `templates/Invoices/add.php` necesita.
 * DTO de transporte puro: la construcción de la entidad (defaults + patch) vive
 * en `InvoicesController::add()` (canon de los AddViewModel, igual que Refund).
 * Creado por simetría con `InvoiceEditViewModel` (audit Plan D — backlog Invoices).
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
}
