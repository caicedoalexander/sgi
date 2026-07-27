<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\Datasource\ConnectionManager;
use Throwable;

final class DatabaseHealthCheck implements HealthCheckInterface
{
    /**
     * Verifica la conexión a la base de datos con un SELECT 1.
     *
     * @return \App\Service\HealthCheck\HealthCheckResult
     */
    public function check(): HealthCheckResult
    {
        try {
            ConnectionManager::get('default')->execute('SELECT 1');

            return new HealthCheckResult('database', HealthStatus::OK, critical: true);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                'database',
                HealthStatus::FAIL,
                critical: true,
                details: ['error' => $e->getMessage()],
            );
        }
    }
}
