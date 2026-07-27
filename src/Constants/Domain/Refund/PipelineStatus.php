<?php
declare(strict_types=1);

namespace App\Constants\Domain\Refund;

enum PipelineStatus: string
{
    case AGRUPACION = 'agrupacion';
    case APROBACION = 'aprobacion';
    case CONTABILIDAD = 'contabilidad';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case VERIFICACION_PAGO = 'verificacion_pago';
    case PAGADA = 'pagada';

    /**
     * Etiqueta legible del estado para mostrar al usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::AGRUPACION => 'Agrupación',
            self::APROBACION => 'Aprobación',
            self::CONTABILIDAD => 'Contabilidad',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::VERIFICACION_PAGO => 'Verificación de pago',
            self::PAGADA => 'Pagada',
        };
    }

    /**
     * Siguiente estado en el avance lineal del pipeline; null si el estado es terminal.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::AGRUPACION => self::APROBACION,
            self::APROBACION => self::CONTABILIDAD,
            self::CONTABILIDAD => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::VERIFICACION_PAGO,
            self::VERIFICACION_PAGO => self::PAGADA,
            self::PAGADA => null,
        };
    }

    /**
     * Estado anterior en el flujo (regresión); null si no admite retroceso.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::AGRUPACION => null,
            self::APROBACION => self::AGRUPACION,
            self::CONTABILIDAD => self::APROBACION,
            self::TESORERIA => self::CONTABILIDAD,
            self::AUTORIZACION_PAGO => self::TESORERIA,
            self::VERIFICACION_PAGO => self::AUTORIZACION_PAGO,
            self::PAGADA => null,
        };
    }

    /**
     * True si el estado es terminal (no admite avance).
     */
    public function isTerminal(): bool
    {
        return $this === self::PAGADA;
    }
}
