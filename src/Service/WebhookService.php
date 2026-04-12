<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Client;
use Cake\Log\Log;
use Exception;

class WebhookService
{
    private const MAX_RETRIES = 3;
    private const BASE_DELAY_MS = 1000; // 1s, 2s, 4s

    private Client $client;
    private int $maxRetries;
    private CircuitBreaker $circuitBreaker;

    /**
     * @param int $timeout Request timeout in seconds.
     * @param int $maxRetries Maximum retry attempts on server errors.
     */
    public function __construct(int $timeout = 30, int $maxRetries = self::MAX_RETRIES)
    {
        $this->client = new Client([
            'timeout' => $timeout,
        ]);
        $this->maxRetries = $maxRetries;
        $this->circuitBreaker = new CircuitBreaker('webhook', failureThreshold: 3, recoveryTimeoutSeconds: 120);
    }

    /**
     * POST JSON data to a URL.
     */
    public function sendJson(string $url, array $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';

        return $this->post($url, json_encode($data), $headers);
    }

    /**
     * POST a file (multipart) to a URL.
     */
    public function sendFile(
        string $url,
        string $filePath,
        string $fieldName = 'file',
        array $extraData = [],
        array $headers = [],
    ): array {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => "File not found: {$filePath}",
            ];
        }

        return $this->executeWithRetry(function () use ($url, $filePath, $fieldName, $extraData, $headers) {
            return $this->client->post($url, array_merge($extraData, [
                $fieldName => fopen($filePath, 'r'),
            ]), [
                'headers' => $headers,
                'type' => 'multipart/form-data',
            ]);
        });
    }

    /**
     * Generic POST request.
     */
    public function post(string $url, mixed $body, array $headers = []): array
    {
        return $this->executeWithRetry(function () use ($url, $body, $headers) {
            return $this->client->post($url, (string)$body, [
                'headers' => $headers,
            ]);
        });
    }

    /**
     * Execute an HTTP request with exponential backoff retry.
     *
     * @param callable $request Closure that performs the HTTP call and returns a Response.
     * @return array{success: bool, statusCode: int, body: string, error: ?string}
     */
    private function executeWithRetry(callable $request): array
    {
        return $this->circuitBreaker->call(
            function () use ($request) {
                $lastException = null;

                for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
                    try {
                        $response = $request();

                        if ($response->isOk() || ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500)) {
                            return [
                                'success' => $response->isOk(),
                                'statusCode' => $response->getStatusCode(),
                                'body' => (string)$response->getBody(),
                                'error' => $response->isOk() ? null : "HTTP {$response->getStatusCode()}",
                            ];
                        }

                        $lastException = new Exception("HTTP {$response->getStatusCode()}");
                    } catch (Exception $e) {
                        $lastException = $e;
                    }

                    if ($attempt < $this->maxRetries) {
                        $delayMs = self::BASE_DELAY_MS * (2 ** $attempt);
                        usleep($delayMs * 1000);
                        $msg = $lastException->getMessage();
                        $retryNum = $attempt + 1;
                        Log::warning("WebhookService: retry #{$retryNum} after {$delayMs}ms — {$msg}");
                    }
                }

                throw new Exception("Retries exhausted: {$lastException->getMessage()}");
            },
            function () {
                return [
                    'success' => false,
                    'statusCode' => 0,
                    'body' => '',
                    'error' => 'Circuit breaker is open — external service unavailable',
                ];
            },
        );
    }
}
