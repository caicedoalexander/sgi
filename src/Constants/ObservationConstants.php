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

    public const DATE_FORMAT = 'd/m/Y H:i';
}
