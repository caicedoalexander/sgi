<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\Cache\Cache;
use Throwable;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function check(): HealthCheckResult
    {
        $key = 'health_probe_' . bin2hex(random_bytes(4));

        try {
            Cache::write($key, '1', 'default');
            $value = Cache::read($key, 'default');
            Cache::delete($key, 'default');

            if ($value !== '1') {
                return new HealthCheckResult(
                    'cache',
                    HealthStatus::FAIL,
                    critical: true,
                    details: ['error' => 'cache read returned unexpected value'],
                );
            }

            return new HealthCheckResult('cache', HealthStatus::OK, critical: true);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                'cache',
                HealthStatus::FAIL,
                critical: true,
                details: ['error' => $e->getMessage()],
            );
        }
    }
}
