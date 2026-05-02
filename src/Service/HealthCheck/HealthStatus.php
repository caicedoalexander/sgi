<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

final class HealthStatus
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const DEGRADED = 'degraded';
}
