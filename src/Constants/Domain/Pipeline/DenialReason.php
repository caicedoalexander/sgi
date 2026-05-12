<?php
declare(strict_types=1);

namespace App\Constants\Domain\Pipeline;

/**
 * Motivos por los que un rol no puede avanzar/regresar un registro de pipeline.
 *
 * `null` (en métodos `denialReasonFor*`) ⇒ puede operar. Cualquier caso de este
 * enum ⇒ no puede, con motivo discriminable por el caller.
 */
enum DenialReason: string
{
    case TERMINAL_STATE = 'terminal_state';
    case UNAUTHORIZED = 'unauthorized';
    case REJECTED = 'rejected';
    case MISSING_FIELDS = 'missing_fields';

    public function message(): string
    {
        return match ($this) {
            self::TERMINAL_STATE => 'El registro ya está en su estado final.',
            self::UNAUTHORIZED => 'No tiene permisos para avanzar este registro.',
            self::REJECTED => 'El registro fue rechazado y no puede avanzar.',
            self::MISSING_FIELDS => 'Faltan campos requeridos para avanzar.',
        };
    }
}
