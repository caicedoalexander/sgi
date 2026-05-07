<?php
declare(strict_types=1);

namespace App\Constants\Domain\Advance;

enum PipelineStatus: string
{
    case VALIDACION = 'validacion';
    case REVISION_FIRMAS = 'revision_firmas';
    case CONTABILIDAD = 'contabilidad';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case LEGALIZADA = 'legalizada';

    /**
     * Etiqueta legible del estado para mostrar al usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::VALIDACION => 'Validación',
            self::REVISION_FIRMAS => 'Revisión y Firmas',
            self::CONTABILIDAD => 'Contabilidad',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::LEGALIZADA => 'Legalizada',
        };
    }

    /**
     * Avance lineal natural; null si terminal o si el estado bifurca por
     * case_type (CONTABILIDAD: exacto/faltante/sobrante; TESORERIA: faltante/sobrante).
     */
    public function next(): ?self
    {
        return match ($this) {
            self::VALIDACION => self::REVISION_FIRMAS,
            self::REVISION_FIRMAS => self::CONTABILIDAD,
            self::CONTABILIDAD, self::TESORERIA => null,
            self::AUTORIZACION_PAGO => self::LEGALIZADA,
            self::LEGALIZADA => null,
        };
    }

    /**
     * Estado anterior en el flujo (regresión); null si no admite retroceso.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::VALIDACION, self::LEGALIZADA => null,
            self::REVISION_FIRMAS => self::VALIDACION,
            self::CONTABILIDAD => self::REVISION_FIRMAS,
            self::TESORERIA => self::CONTABILIDAD,
            self::AUTORIZACION_PAGO => self::TESORERIA,
        };
    }

    /**
     * True si el estado es terminal (no admite avance).
     */
    public function isTerminal(): bool
    {
        return $this === self::LEGALIZADA;
    }
}
