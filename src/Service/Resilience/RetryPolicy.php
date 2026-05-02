<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use Exception;

/**
 * Configuración inmutable para reintentos. Define cuántas veces reintentar,
 * el delay base en ms (backoff exponencial: base * 2^attempt) y qué
 * excepciones son retriables.
 */
final class RetryPolicy
{
    /**
     * @param int $maxAttempts Cantidad de reintentos tras el primer intento (0 = sin retry).
     * @param int $baseDelayMs Delay base en ms para el backoff exponencial.
     * @param list<class-string<\Throwable>> $retriableExceptions Excepciones que disparan retry.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 1000,
        public readonly array $retriableExceptions = [Exception::class],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function noRetry(): self
    {
        return new self(maxAttempts: 0);
    }
}
