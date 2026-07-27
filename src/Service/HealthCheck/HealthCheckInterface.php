<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

interface HealthCheckInterface
{
    /**
     * Ejecuta el chequeo de salud y retorna su resultado.
     *
     * @return \App\Service\HealthCheck\HealthCheckResult
     */
    public function check(): HealthCheckResult;
}
