<?php
declare(strict_types=1);

namespace App\Constants\Domain\PaymentScheduling;

enum PipelineStatus: string
{
    case BORRADOR = 'borrador';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case PAGADA = 'pagada';

    /**
     * Etiqueta legible del estado para mostrar al usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::BORRADOR => 'Borrador',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::PAGADA => 'Pagada',
        };
    }

    /**
     * Siguiente estado en el avance lineal del pipeline; null si el estado es terminal.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::BORRADOR => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::PAGADA,
            self::PAGADA => null,
        };
    }

    /**
     * Estado anterior en el flujo (regresión); null si no admite retroceso.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::BORRADOR => null,
            self::TESORERIA => self::BORRADOR,
            self::AUTORIZACION_PAGO => self::TESORERIA,
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

    /**
     * Estado al que regresa una programación cuando el Contador rechaza
     * desde autorizacion_pago. Lógica única de PaymentScheduling.
     */
    public static function rejectionTarget(): self
    {
        return self::TESORERIA;
    }
}
