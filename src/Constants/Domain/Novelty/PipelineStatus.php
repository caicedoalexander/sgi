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
    case VERIFICACION_PAGO = 'verificacion_pago';
    case PAGADA = 'pagada';
    case RECHAZADA = 'rechazada';

    /**
     * Etiqueta legible del estado para mostrar al usuario.
     */
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
            self::VERIFICACION_PAGO => 'Verificación de pago',
            self::PAGADA => 'Pagada',
            self::RECHAZADA => 'Rechazada',
        };
    }

    /**
     * Siguiente estado en el avance lineal del pipeline; null si el estado es terminal.
     */
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
            self::AUTORIZACION_PAGO => self::VERIFICACION_PAGO,
            self::VERIFICACION_PAGO => self::PAGADA,
            self::PAGADA, self::RECHAZADA => null,
        };
    }

    /**
     * Estado anterior en el flujo (regresión); null si no admite retroceso.
     */
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
            self::VERIFICACION_PAGO => self::AUTORIZACION_PAGO,
        };
    }

    /**
     * True si el estado es terminal (no admite avance).
     */
    public function isTerminal(): bool
    {
        return $this === self::PAGADA || $this === self::RECHAZADA;
    }
}
