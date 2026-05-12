<?php
declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una acción como exenta del gate de permisos.
 *
 * Casos de uso: login/logout, error rendering, páginas estáticas,
 * acciones que delegan la autorización internamente a otro flujo.
 *
 * El motivo ($reason) es obligatorio y se documenta como prueba de
 * que la exención es intencional, no un olvido.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class NoAuthGate
{
    /**
     * @param string $reason Motivo de la exención (documenta intención explícita).
     */
    public function __construct(public readonly string $reason)
    {
    }
}
