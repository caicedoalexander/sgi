<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\ORM\TableRegistry;
use Throwable;

final class EmailLogHealthCheck implements HealthCheckInterface
{
    /**
     * Reporta como degradado si existen correos en estado 'failed'.
     *
     * @return \App\Service\HealthCheck\HealthCheckResult
     */
    public function check(): HealthCheckResult
    {
        try {
            $count = TableRegistry::getTableLocator()
                ->get('EmailLogs')
                ->find()
                ->where(['status' => 'failed'])
                ->count();

            return new HealthCheckResult(
                'email_logs_failed',
                $count > 0 ? HealthStatus::DEGRADED : HealthStatus::OK,
                critical: false,
                details: ['failed_count' => $count],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                'email_logs_failed',
                HealthStatus::FAIL,
                critical: false,
                details: ['error' => $e->getMessage()],
            );
        }
    }
}
