<?php
declare(strict_types=1);

namespace App\ViewModel\Support;

/**
 * Helpers para los botones de submit de los formularios de edit que comparten
 * el patrón "Guardar Cambios / Guardar y Avanzar a: {siguiente estado}".
 *
 * Usado por InvoiceEditViewModel, RefundEditViewModel y PettyCashEditViewModel
 * (audit CR-201). La decisión de cuál mostrar (avanzar vs guardar) sigue
 * viviendo en cada VM porque depende de flags específicos del dominio
 * (canAdvance, advanceErrors, isRejected, etc.).
 */
final class SubmitButton
{
    /**
     * HTML del label "Guardar y Avanzar a: {nextLabel}" con ícono de flecha.
     * El label del siguiente estado se escapa con htmlspecialchars.
     */
    public static function forAdvance(string $nextLabel): string
    {
        return '<i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Guardar y Avanzar a: '
            . htmlspecialchars($nextLabel, ENT_QUOTES, 'UTF-8');
    }

    /**
     * HTML del label "Guardar Cambios" con ícono de save.
     */
    public static function forSave(): string
    {
        return '<i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Cambios';
    }
}
