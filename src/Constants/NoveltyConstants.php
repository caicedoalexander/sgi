<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_APPROVED = 'aprobado';
    public const STATUS_REJECTED = 'rechazado';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const SCHEDULE_DAYS = 'days';
    public const SCHEDULE_HOURS = 'hours';
    public const SCHEDULE_TYPES = [self::SCHEDULE_DAYS, self::SCHEDULE_HOURS];

    public const SCHEDULE_LABELS = [
        self::SCHEDULE_DAYS => 'Por días',
        self::SCHEDULE_HOURS => 'Por horas',
    ];
}
