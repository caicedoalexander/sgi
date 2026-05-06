<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Mensajes y constantes compartidos por el chat de observaciones
 * (Invoices, PaymentSchedulings, EmployeeNovelties, Employees,
 * PettyCashRecords, Refunds).
 */
final class ObservationConstants
{
    public const ERR_EMPTY = 'El mensaje no puede estar vacío.';
    public const ERR_SAVE_FAILED = 'No se pudo agregar la observación.';
    public const MSG_ADDED = 'Observación agregada.';

    // Tipos de observación compartidos por todos los pipelines que usan el chat de observaciones.
    public const TYPE_GENERAL = 'general';
    public const TYPE_REGRESSION = 'regression';

    public const TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_REGRESSION,
    ];
}
