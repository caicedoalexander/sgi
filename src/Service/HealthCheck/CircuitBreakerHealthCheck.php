<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\Cache\Cache;

/**
 * Lee el estado de los CircuitBreaker desde el cache (sin instanciar el CB).
 * El layout de las keys está definido en CircuitBreaker::_cacheKey:
 *   "circuit_breaker_{$name}_state".
 * Si el cache no devuelve nada, asumimos closed (estado por defecto del CB).
 */
final class CircuitBreakerHealthCheck implements HealthCheckInterface
{
    private const CB_NAMES = ['webhook', 'smtp'];

    /**
     * Reporta el estado de los circuit breakers leídos del cache.
     *
     * @return \App\Service\HealthCheck\HealthCheckResult
     */
    public function check(): HealthCheckResult
    {
        $states = [];
        $anyOpen = false;

        foreach (self::CB_NAMES as $name) {
            $state = Cache::read("circuit_breaker_{$name}_state", 'default') ?: 'closed';
            $states[$name] = (string)$state;

            if ($state === 'open') {
                $anyOpen = true;
            }
        }

        return new HealthCheckResult(
            'circuit_breakers',
            $anyOpen ? HealthStatus::DEGRADED : HealthStatus::OK,
            critical: false,
            details: $states,
        );
    }
}
