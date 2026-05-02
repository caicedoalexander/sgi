<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

final class HealthCheckResult
{
    /**
     * @param string $name Nombre del check (database, cache, circuit_breakers, email_logs).
     * @param string $status Una de las constantes de HealthStatus.
     * @param bool $critical Si true, FAIL hace al endpoint devolver 503.
     * @param array $details Payload adicional visible solo para usuarios autenticados.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly bool $critical,
        public readonly array $details = [],
    ) {
    }
}
