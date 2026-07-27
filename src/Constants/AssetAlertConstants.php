<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Asset\AlertPriority;
use App\Constants\Domain\Asset\AlertStatus;
use App\Constants\Domain\Asset\AlertType;

final class AssetAlertConstants
{
    public const TYPE_STOCK_BAJO = AlertType::STOCK_BAJO->value;
    public const TYPE_ACTA_PENDIENTE = AlertType::ACTA_PENDIENTE->value;
    public const TYPE_ACTIVO_SIN_RESPONSABLE = AlertType::ACTIVO_SIN_RESPONSABLE->value;
    public const TYPE_REGISTRO_INCOMPLETO = AlertType::REGISTRO_INCOMPLETO->value;
    public const TYPE_MOVIMIENTO_SIN_CERRAR = AlertType::MOVIMIENTO_SIN_CERRAR->value;

    public const STATUS_ABIERTA = AlertStatus::ABIERTA->value;
    public const STATUS_RESUELTA = AlertStatus::RESUELTA->value;
    public const STATUS_VENCIDA = AlertStatus::VENCIDA->value;

    public const PRIORITY_ALTA = AlertPriority::ALTA->value;
    public const PRIORITY_MEDIA = AlertPriority::MEDIA->value;
    public const PRIORITY_BAJA = AlertPriority::BAJA->value;

    /** Días tras los cuales un acta pendiente genera alerta (RN-06). */
    public const ACTA_PENDING_DAYS = 3;

    /** Días tras los cuales un acta pendiente se considera vencida (movimiento_sin_cerrar). */
    public const ACTA_OVERDUE_DAYS = 15;

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_STOCK_BAJO,
        self::TYPE_ACTA_PENDIENTE,
        self::TYPE_ACTIVO_SIN_RESPONSABLE,
        self::TYPE_REGISTRO_INCOMPLETO,
        self::TYPE_MOVIMIENTO_SIN_CERRAR,
    ];

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_ABIERTA, self::STATUS_RESUELTA, self::STATUS_VENCIDA];

    /** @var array<int, string> */
    public const PRIORITIES = [self::PRIORITY_ALTA, self::PRIORITY_MEDIA, self::PRIORITY_BAJA];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_STOCK_BAJO => 'Stock bajo',
        self::TYPE_ACTA_PENDIENTE => 'Acta pendiente',
        self::TYPE_ACTIVO_SIN_RESPONSABLE => 'Activo sin responsable',
        self::TYPE_REGISTRO_INCOMPLETO => 'Registro incompleto',
        self::TYPE_MOVIMIENTO_SIN_CERRAR => 'Movimiento sin cerrar',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_ABIERTA => 'Abierta',
        self::STATUS_RESUELTA => 'Resuelta',
        self::STATUS_VENCIDA => 'Vencida',
    ];

    /** @var array<string, string> */
    public const PRIORITY_LABELS = [
        self::PRIORITY_ALTA => 'Alta',
        self::PRIORITY_MEDIA => 'Media',
        self::PRIORITY_BAJA => 'Baja',
    ];
}
