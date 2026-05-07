<?php
declare(strict_types=1);

namespace App\Constants\Domain\Novelty;

enum PipelineStatus: string
{
    case REGISTRO = 'registro';
    case APROBACION = 'aprobacion';
    case RRHH = 'rrhh';
    case CONTABILIDAD = 'contabilidad';
    case REVISION_FIRMAS = 'revision_firmas';
    case GDP = 'gdp';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case PAGADA = 'pagada';
    case RECHAZADA = 'rechazada';

    public function label(): string
    {
        return match ($this) {
            self::REGISTRO => 'Registro',
            self::APROBACION => 'Aprobación',
            self::RRHH => 'RRHH',
            self::CONTABILIDAD => 'Contabilidad',
            self::REVISION_FIRMAS => 'Revisión y Firmas de documentos',
            self::GDP => 'GDP',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::PAGADA => 'Pagada',
            self::RECHAZADA => 'Rechazada',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::REGISTRO => self::APROBACION,
            self::APROBACION => self::RRHH,
            self::RRHH => self::CONTABILIDAD,
            self::CONTABILIDAD => self::REVISION_FIRMAS,
            self::REVISION_FIRMAS => self::GDP,
            self::GDP => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::PAGADA,
            self::PAGADA, self::RECHAZADA => null,
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::REGISTRO, self::PAGADA, self::RECHAZADA => null,
            self::APROBACION => self::REGISTRO,
            self::RRHH => self::APROBACION,
            self::CONTABILIDAD => self::RRHH,
            self::REVISION_FIRMAS => self::CONTABILIDAD,
            self::GDP => self::REVISION_FIRMAS,
            self::TESORERIA => self::GDP,
            self::AUTORIZACION_PAGO => self::TESORERIA,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::PAGADA || $this === self::RECHAZADA;
    }
}
