<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;
use RuntimeException;
use Throwable;

class CircuitBreaker
{
    private string $name;
    private int $failureThreshold;
    private int $recoveryTimeoutSeconds;
    private StructuredLogger $logger;

    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    /**
     * @param string $name Circuit breaker identifier (namespaces its cache keys).
     * @param int $failureThreshold Consecutive failures before the circuit opens.
     * @param int $recoveryTimeoutSeconds Seconds the circuit stays open before a half-open retry.
     */
    public function __construct(
        string $name,
        int $failureThreshold = 5,
        int $recoveryTimeoutSeconds = 60,
    ) {
        $this->name = $name;
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeoutSeconds = $recoveryTimeoutSeconds;
        $this->logger = new StructuredLogger('CircuitBreaker.' . $name);
    }

    /**
     * Execute a callable through the circuit breaker.
     *
     * @param callable $action The action to execute.
     * @param callable|null $fallback Optional fallback when circuit is open.
     * @return mixed The result of $action or $fallback.
     * @throws \RuntimeException When circuit is open and no fallback provided.
     */
    public function call(callable $action, ?callable $fallback = null): mixed
    {
        $state = $this->_getState();

        if ($state === self::STATE_OPEN) {
            if ($this->_shouldAttemptReset()) {
                $this->_setState(self::STATE_HALF_OPEN);
            } else {
                $this->logger->warning('open_skip_call', ['name' => $this->name]);

                if ($fallback) {
                    return $fallback();
                }

                throw new RuntimeException("Circuit breaker [{$this->name}] is open");
            }
        }

        try {
            $result = $action();
            $this->_onSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->_onFailure();
            $this->logger->error('call_failure', [
                'name' => $this->name,
                'exception' => $e->getMessage(),
            ]);

            if ($fallback) {
                return $fallback();
            }

            throw $e;
        }
    }

    /**
     * Reset the failure counter and close the circuit after a successful call.
     *
     * @return void
     */
    private function _onSuccess(): void
    {
        $this->_setFailureCount(0);
        $this->_setState(self::STATE_CLOSED);
    }

    /**
     * Increment the failure counter and open the circuit once the threshold is reached.
     *
     * @return void
     */
    private function _onFailure(): void
    {
        $count = $this->_getFailureCount() + 1;
        $this->_setFailureCount($count);

        if ($count >= $this->failureThreshold) {
            $this->_setState(self::STATE_OPEN);
            $this->_setOpenedAt(time());
            $this->logger->warning('opened', [
                'name' => $this->name,
                'failures' => $count,
            ]);
        }
    }

    /**
     * Whether the recovery timeout has elapsed since the circuit opened.
     *
     * @return bool
     */
    private function _shouldAttemptReset(): bool
    {
        $openedAt = $this->_getOpenedAt();

        return $openedAt && (time() - $openedAt) >= $this->recoveryTimeoutSeconds;
    }

    /**
     * Build a namespaced cache key for this circuit's persisted state.
     *
     * @param string $suffix Key segment (state|failures|opened_at).
     * @return string
     */
    private function _cacheKey(string $suffix): string
    {
        return "circuit_breaker_{$this->name}_{$suffix}";
    }

    /**
     * Read the current circuit state from cache, defaulting to closed.
     *
     * @return string
     */
    private function _getState(): string
    {
        return Cache::read($this->_cacheKey('state'), 'default') ?: self::STATE_CLOSED;
    }

    /**
     * Persist the circuit state to cache.
     *
     * @param string $state New circuit state.
     * @return void
     */
    private function _setState(string $state): void
    {
        Cache::write($this->_cacheKey('state'), $state, 'default');
    }

    /**
     * Read the current consecutive failure count from cache.
     *
     * @return int
     */
    private function _getFailureCount(): int
    {
        return (int)(Cache::read($this->_cacheKey('failures'), 'default') ?: 0);
    }

    /**
     * Persist the consecutive failure count to cache.
     *
     * @param int $count Failure count to store.
     * @return void
     */
    private function _setFailureCount(int $count): void
    {
        Cache::write($this->_cacheKey('failures'), $count, 'default');
    }

    /**
     * Read the timestamp at which the circuit was opened, if any.
     *
     * @return int|null
     */
    private function _getOpenedAt(): ?int
    {
        $val = Cache::read($this->_cacheKey('opened_at'), 'default');

        return $val ? (int)$val : null;
    }

    /**
     * Persist the timestamp at which the circuit was opened.
     *
     * @param int $timestamp Unix timestamp when the circuit opened.
     * @return void
     */
    private function _setOpenedAt(int $timestamp): void
    {
        Cache::write($this->_cacheKey('opened_at'), $timestamp, 'default');
    }
}
