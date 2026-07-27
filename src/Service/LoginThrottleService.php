<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\RateLimitBucketsTable;
use Cake\ORM\TableRegistry;

/**
 * Lockout de login por FALLOS de cuenta sobre la tabla rate_limit_buckets.
 *
 * El eje por IP vive en RateLimitMiddleware (borde). Este service es el eje por
 * cuenta con enforcement real: superado el umbral, el controller deniega todo
 * intento sobre esa cuenta durante la ventana. Un login exitoso limpia el
 * contador (el usuario legítimo no arrastra fallos).
 *
 * La tabla se resuelve vía TableRegistry (no por constructor) para no ser
 * autowireada por el container con una instancia sin conexión.
 */
class LoginThrottleService
{
    private const WINDOW_SECONDS = 900;
    private const MAX_FAILURES_PER_ACCOUNT = 10;

    private ?RateLimitBucketsTable $buckets = null;

    /**
     * Resuelve la tabla RateLimitBuckets desde el registry (memoizada).
     */
    private function buckets(): RateLimitBucketsTable
    {
        /** @var \App\Model\Table\RateLimitBucketsTable $table */
        $table = TableRegistry::getTableLocator()->get('RateLimitBuckets');

        return $this->buckets ??= $table;
    }

    /**
     * Inicio de la ventana actual, alineado a WINDOW_SECONDS.
     */
    private function windowStart(): int
    {
        return (int)floor(time() / self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }

    /**
     * Bucket key del username normalizado (trim + lowercase) en la ventana actual.
     */
    private function keyFor(string $username): string
    {
        $normalized = strtolower(trim($username));

        return hash('sha256', 'login_user|' . $normalized . '|' . $this->windowStart());
    }

    /**
     * Si la cuenta superó el umbral de fallos en la ventana actual.
     */
    public function isBlocked(string $username): bool
    {
        return $this->buckets()->getCount($this->keyFor($username)) >= self::MAX_FAILURES_PER_ACCOUNT;
    }

    /**
     * Registra un fallo de login para la cuenta en la ventana actual.
     */
    public function registerFailure(string $username): void
    {
        $this->buckets()->incrementAndGet($this->keyFor($username), $this->windowStart());
    }

    /**
     * Limpia el contador de fallos de la cuenta (p. ej. tras login exitoso).
     */
    public function clear(string $username): void
    {
        $this->buckets()->clearKey($this->keyFor($username));
    }
}
