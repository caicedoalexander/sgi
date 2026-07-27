<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Asset\ActaStatus;
use App\Constants\Domain\Asset\AssetStatus;
use App\Constants\Domain\Asset\DocumentType;
use App\Constants\Domain\Asset\MovementSource;
use App\Constants\Domain\Asset\MovementType;

/**
 * Constantes de activos. Fuente única real en los enums de Domain\Asset; estas
 * consts delegan por retrocompatibilidad y ergonomía en validación/templates.
 */
final class AssetConstants
{
    public const CODE_PREFIX = 'ACT';

    // Estados del activo
    public const STATUS_DISPONIBLE = AssetStatus::DISPONIBLE->value;
    public const STATUS_ASIGNADO = AssetStatus::ASIGNADO->value;
    public const STATUS_PRESTADO = AssetStatus::PRESTADO->value;
    public const STATUS_EN_REPARACION = AssetStatus::EN_REPARACION->value;
    public const STATUS_DADO_DE_BAJA = AssetStatus::DADO_DE_BAJA->value;

    // Tipos de movimiento
    public const MOVEMENT_INGRESO = MovementType::INGRESO->value;
    public const MOVEMENT_ENTREGA = MovementType::ENTREGA->value;
    public const MOVEMENT_DEVOLUCION = MovementType::DEVOLUCION->value;
    public const MOVEMENT_TRASLADO = MovementType::TRASLADO->value;
    public const MOVEMENT_PRESTAMO = MovementType::PRESTAMO->value;
    public const MOVEMENT_BAJA = MovementType::BAJA->value;
    public const MOVEMENT_AJUSTE = MovementType::AJUSTE->value;

    // Estados de acta
    public const ACTA_PENDIENTE = ActaStatus::PENDIENTE->value;
    public const ACTA_CARGADA = ActaStatus::CARGADA->value;
    public const ACTA_VALIDADA = ActaStatus::VALIDADA->value;
    public const ACTA_RECHAZADA = ActaStatus::RECHAZADA->value;

    // Tipos de documento
    public const DOCTYPE_ACTA = DocumentType::ACTA->value;
    public const DOCTYPE_FACTURA_COMPRA = DocumentType::FACTURA_COMPRA->value;
    public const DOCTYPE_FOTO = DocumentType::FOTO->value;
    public const DOCTYPE_SOPORTE_MANTENIMIENTO = DocumentType::SOPORTE_MANTENIMIENTO->value;
    public const DOCTYPE_OTRO = DocumentType::OTRO->value;

    // Origen del movimiento
    public const SOURCE_WEB = MovementSource::WEB->value;
    public const SOURCE_AGENT = MovementSource::AGENT->value;

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DISPONIBLE,
        self::STATUS_ASIGNADO,
        self::STATUS_PRESTADO,
        self::STATUS_EN_REPARACION,
        self::STATUS_DADO_DE_BAJA,
    ];

    /** @var array<int, string> */
    public const MOVEMENT_TYPES = [
        self::MOVEMENT_INGRESO,
        self::MOVEMENT_ENTREGA,
        self::MOVEMENT_DEVOLUCION,
        self::MOVEMENT_TRASLADO,
        self::MOVEMENT_PRESTAMO,
        self::MOVEMENT_BAJA,
        self::MOVEMENT_AJUSTE,
    ];

    /** @var array<int, string> */
    public const ACTA_STATUSES = [
        self::ACTA_PENDIENTE,
        self::ACTA_CARGADA,
        self::ACTA_VALIDADA,
        self::ACTA_RECHAZADA,
    ];

    /** @var array<int, string> */
    public const DOCUMENT_TYPES = [
        self::DOCTYPE_ACTA,
        self::DOCTYPE_FACTURA_COMPRA,
        self::DOCTYPE_FOTO,
        self::DOCTYPE_SOPORTE_MANTENIMIENTO,
        self::DOCTYPE_OTRO,
    ];

    /** @var array<int, string> */
    public const SOURCES = [self::SOURCE_WEB, self::SOURCE_AGENT];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DISPONIBLE => 'Disponible',
        self::STATUS_ASIGNADO => 'Asignado',
        self::STATUS_PRESTADO => 'Prestado',
        self::STATUS_EN_REPARACION => 'En reparación',
        self::STATUS_DADO_DE_BAJA => 'Dado de baja',
    ];

    /** @var array<string, string> */
    public const MOVEMENT_LABELS = [
        self::MOVEMENT_INGRESO => 'Ingreso',
        self::MOVEMENT_ENTREGA => 'Entrega',
        self::MOVEMENT_DEVOLUCION => 'Devolución',
        self::MOVEMENT_TRASLADO => 'Traslado',
        self::MOVEMENT_PRESTAMO => 'Préstamo',
        self::MOVEMENT_BAJA => 'Baja',
        self::MOVEMENT_AJUSTE => 'Ajuste',
    ];
}
