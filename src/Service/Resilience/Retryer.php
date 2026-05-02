<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use App\Service\StructuredLogger;
use RuntimeException;
use Throwable;

/**
 * Ejecuta un closure con backoff exponencial. Si la excepción no es retriable
 * (no instanceof de ninguna de las clases en la policy), re-throw inmediato.
 * Si se agotan los intentos, lanza RuntimeException con previous = última excepción.
 */
final class Retryer
{
    private readonly StructuredLogger $logger;

    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly string $context = 'retry',
    ) {
        $this->logger = new StructuredLogger($context);
    }

    /**
     * @template T
     * @param callable():T $action
     * @return T
     */
    public function run(callable $action): mixed
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->policy->maxAttempts; $attempt++) {
            try {
                return $action();
            } catch (Throwable $e) {
                if (!$this->isRetriable($e)) {
                    throw $e;
                }
                $lastException = $e;

                if ($attempt < $this->policy->maxAttempts) {
                    $delayMs = $this->policy->baseDelayMs * (2 ** $attempt);
                    usleep($delayMs * 1000);
                    $this->logger->warning(
                        "[{$this->context}] retry #" . ($attempt + 1) . " after {$delayMs}ms",
                        ['error' => $e->getMessage()],
                    );
                }
            }
        }

        throw new RuntimeException(
            "Retries exhausted ({$this->context}): " . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException,
        );
    }

    private function isRetriable(Throwable $e): bool
    {
        foreach ($this->policy->retriableExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
