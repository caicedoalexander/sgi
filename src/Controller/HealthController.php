<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthCheck\CacheHealthCheck;
use App\Service\HealthCheck\CircuitBreakerHealthCheck;
use App\Service\HealthCheck\DatabaseHealthCheck;
use App\Service\HealthCheck\EmailLogHealthCheck;
use App\Service\HealthCheck\HealthCheckResult;
use App\Service\HealthCheck\HealthStatus;
use Cake\Http\Response;

class HealthController extends AppController
{
    private const CHECKS = [
        DatabaseHealthCheck::class,
        CacheHealthCheck::class,
        CircuitBreakerHealthCheck::class,
        EmailLogHealthCheck::class,
    ];

    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['index']);
    }

    /**
     * Endpoint público con respuesta mínima para uptime monitoring;
     * con sesión autenticada devuelve el detalle por componente.
     *
     * 503 sólo si DatabaseHealthCheck o CacheHealthCheck devuelven FAIL
     * (críticos para que la app procese requests). CB abierto y
     * email_logs_failed > 0 se reportan como degraded con status 200.
     */
    public function index(): Response
    {
        $container = $this->getContainer();
        $results = array_map(
            fn (string $cls): HealthCheckResult => $container->get($cls)->check(),
            self::CHECKS,
        );

        $criticalFailed = !empty(array_filter(
            $results,
            fn (HealthCheckResult $r) => $r->critical && $r->status === HealthStatus::FAIL,
        ));

        $hasDegraded = !empty(array_filter(
            $results,
            fn (HealthCheckResult $r) => $r->status === HealthStatus::DEGRADED
                || (!$r->critical && $r->status === HealthStatus::FAIL),
        ));

        $globalStatus = match (true) {
            $criticalFailed => 'unhealthy',
            $hasDegraded => 'degraded',
            default => 'healthy',
        };

        $isAuthed = $this->Authentication->getIdentity() !== null;
        $body = $isAuthed
            ? ['status' => $globalStatus, 'checks' => $this->_serializeChecks($results)]
            : ['status' => $globalStatus];

        return $this->response
            ->withType('application/json')
            ->withStatus($criticalFailed ? 503 : 200)
            ->withStringBody((string)json_encode($body));
    }

    /**
     * @param list<HealthCheckResult> $results
     * @return array<string, array{status: string, ...}>
     */
    private function _serializeChecks(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r->name] = ['status' => $r->status] + $r->details;
        }

        return $out;
    }
}
