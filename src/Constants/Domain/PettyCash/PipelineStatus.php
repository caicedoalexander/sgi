<?php
declare(strict_types=1);

namespace App\Constants\Domain\PettyCash;

enum PipelineStatus: string
{
    case AGRUPACION = 'agrupacion';
    case CONTABILIDAD = 'contabilidad';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case PAGADA = 'pagada';

    public function label(): string
    {
        return match ($this) {
            self::AGRUPACION => 'Agrupación',
            self::CONTABILIDAD => 'Contabilidad',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::PAGADA => 'Pagada',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::AGRUPACION => self::CONTABILIDAD,
            self::CONTABILIDAD => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::PAGADA,
            self::PAGADA => null,
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::AGRUPACION, self::PAGADA => null,
            self::CONTABILIDAD => self::AGRUPACION,
            self::TESORERIA => self::CONTABILIDAD,
            self::AUTORIZACION_PAGO => self::TESORERIA,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::PAGADA;
    }
}
