<?php
declare(strict_types=1);

namespace App\Constants\Domain\Invoice;

enum PipelineStatus: string
{
    case APROBACION = 'aprobacion';
    case CONTABILIDAD = 'contabilidad';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case VERIFICACION_PAGO = 'verificacion_pago';
    case PAGADA = 'pagada';
    case LEGALIZADA = 'legalizada';

    /**
     * Etiqueta legible del estado para mostrar al usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::APROBACION => 'Aprobación',
            self::CONTABILIDAD => 'Contabilidad',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::VERIFICACION_PAGO => 'Verificación de pago',
            self::PAGADA => 'Pagada',
            self::LEGALIZADA => 'Legalizada',
        };
    }

    /**
     * Siguiente estado en el avance lineal del pipeline; null si el estado es terminal.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::APROBACION => self::CONTABILIDAD,
            self::CONTABILIDAD => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::VERIFICACION_PAGO,
            self::VERIFICACION_PAGO => self::PAGADA,
            self::PAGADA, self::LEGALIZADA => null,
        };
    }

    /**
     * True si el estado es terminal (no admite avance).
     */
    public function isTerminal(): bool
    {
        return $this === self::PAGADA || $this === self::LEGALIZADA;
    }

    /**
     * Estados del flujo normal de facturas (excluye LEGALIZADA, terminal alterno
     * exclusivo de document_type = Legalización).
     *
     * @return list<self>
     */
    public static function pipelineCases(): array
    {
        return [
            self::APROBACION,
            self::CONTABILIDAD,
            self::TESORERIA,
            self::AUTORIZACION_PAGO,
            self::VERIFICACION_PAGO,
            self::PAGADA,
        ];
    }

    /**
     * Pipeline visual exclusivo de Legalizaciones (3 pasos).
     *
     * @return list<self>
     */
    public static function legalizationCases(): array
    {
        return [self::APROBACION, self::CONTABILIDAD, self::LEGALIZADA];
    }
}
