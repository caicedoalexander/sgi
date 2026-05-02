<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

interface HealthCheckInterface
{
    public function check(): HealthCheckResult;
}
