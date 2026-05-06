<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Constantes del log de envío de correos.
 *
 * Slugs en inglés por convención de estados técnicos internos
 * (ver "Slug language convention" en CLAUDE.md). Los `STATUS_LABELS`
 * traducen al español para presentación en `/email-logs`.
 */
final class EmailLogConstants
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_SENT => 'Enviado',
        self::STATUS_FAILED => 'Fallido',
    ];

    public const EVENT_INVOICE_APPROVAL_REQUEST = 'invoice_approval_request';
    public const EVENT_NOVELTY_APPROVAL_REQUEST = 'novelty_approval_request';

    public const EVENT_LABELS = [
        self::EVENT_INVOICE_APPROVAL_REQUEST => 'Solicitud de aprobación de factura',
        self::EVENT_NOVELTY_APPROVAL_REQUEST => 'Solicitud de aprobación de novedad',
    ];

    public const ENTITY_INVOICE = 'invoice';
    public const ENTITY_NOVELTY = 'employee_novelty';

    /** Tras este tiempo, una fila 'pending' se considera huérfana (proceso interrumpido). */
    public const ORPHAN_THRESHOLD_SECONDS = 300;

    /** Truncado del mensaje de error guardado en `last_error`. */
    public const ERROR_MESSAGE_MAX_LENGTH = 5000;

    /** Tope de filas procesadas por una invocación de retryAllFailed. */
    public const RETRY_BATCH_LIMIT = 100;
}
