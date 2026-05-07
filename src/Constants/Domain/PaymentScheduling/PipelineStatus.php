<?php
declare(strict_types=1);

namespace App\Constants\Domain\PaymentScheduling;

enum PipelineStatus: string
{
    case BORRADOR = 'borrador';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case PAGADA = 'pagada';

    public function label(): string
    {
        return match ($this) {
            self::BORRADOR => 'Borrador',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::PAGADA => 'Pagada',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::BORRADOR => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::PAGADA,
            self::PAGADA => null,
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::BORRADOR => null,
            self::TESORERIA => self::BORRADOR,
            self::AUTORIZACION_PAGO => self::TESORERIA,
            self::PAGADA => null,
        };
    }

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
