<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Invoice;

/**
 * Datos pre-calculados que el template `templates/Advances/add.php` necesita.
 * DTO de transporte puro: la construcción del Anticipo (defaults, lista blanca
 * de campos accesibles CR-001 y validación de beneficiario) vive en
 * `AdvancesController::add()` (canon de los AddViewModel, igual que Refund).
 */
final readonly class AdvanceAddViewModel
{
    /**
     * @param \App\Model\Entity\Invoice $invoice Entidad nueva o parcheada.
     * @param array<string, mixed> $dropdowns Listas para los <select> del form.
     */
    public function __construct(
        public Invoice $invoice,
        public array $dropdowns,
    ) {
    }
}
