<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Resilience\Retryer;
use App\Service\Resilience\RetryPolicy;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Exception;

/**
 * Outbound HTTP client con CircuitBreaker, retry y timeouts diferenciados.
 * - sendJson / post genérico: timeout 5s (interactivo).
 * - sendFile (multipart): timeout 30s (uploads legítimamente lentos).
 * - 4xx: no se reintenta (filtro del Retryer no se activa porque el handler decide
 *   devolver inmediatamente sin tirar Exception).
 * - 5xx / network error: reintenta (1s, 2s, 4s).
 * - CircuitBreaker envuelve al Retryer: si el CB está abierto, el fallback retorna
 *   "Circuit breaker is open" sin tocar el remoto.
 */
class WebhookService
{
    private const TIMEOUT_JSON_SECONDS = 5;
    private const TIMEOUT_FILE_SECONDS = 30;

    private Client $client;
    private CircuitBreaker $circuitBreaker;
    private Retryer $retryer;
    private StructuredLogger $logger;

    /**
     * Inicializa el cliente HTTP, el circuit breaker, el retryer y el logger.
     */
    public function __construct()
    {
        $this->client = new Client();
        $this->circuitBreaker = new CircuitBreaker(
            'webhook',
            failureThreshold: 3,
            recoveryTimeoutSeconds: 120,
        );
        $this->retryer = new Retryer(RetryPolicy::default(), context: 'webhook');
        $this->logger = new StructuredLogger('Webhook');
    }

    /**
     * POST JSON data to a URL.
     */
    public function sendJson(string $url, array $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';

        return $this->dispatch(fn() => $this->client->post(
            $url,
            (string)json_encode($data),
            ['headers' => $headers, 'timeout' => self::TIMEOUT_JSON_SECONDS],
        ));
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

        return $this->dispatch(fn() => $this->client->post(
            $url,
            array_merge($extraData, [$fieldName => fopen($filePath, 'r')]),
            [
                'headers' => $headers,
                'type' => 'multipart/form-data',
                'timeout' => self::TIMEOUT_FILE_SECONDS,
            ],
        ));
    }

    /**
     * Generic POST request (treats body as JSON-shaped string; uses JSON timeout).
     */
    public function post(string $url, mixed $body, array $headers = []): array
    {
        return $this->dispatch(fn() => $this->client->post(
            $url,
            (string)$body,
            ['headers' => $headers, 'timeout' => self::TIMEOUT_JSON_SECONDS],
        ));
    }

    /**
     * Wrap el closure HTTP en CircuitBreaker → Retryer → request.
     * 4xx no es retriable: se devuelve inmediatamente como respuesta normal.
     * 5xx / network error: throw Exception → Retryer reintenta hasta agotar.
     */
    private function dispatch(callable $request): array
    {
        return $this->circuitBreaker->call(
            fn() => $this->retryer->run(function () use ($request) {
                /** @var \Cake\Http\Client\Response $response */
                $response = $request();

                if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                    return $this->shape($response);
                }

                if (!$response->isOk()) {
                    throw new Exception("HTTP {$response->getStatusCode()}");
                }

                return $this->shape($response);
            }),
            fn() => [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => 'Circuit breaker is open — external service unavailable',
            ],
        );
    }

    /**
     * Normaliza una respuesta HTTP al arreglo de resultado del webhook.
     *
     * @param \Cake\Http\Client\Response $response Respuesta HTTP.
     * @return array
     */
    private function shape(Response $response): array
    {
        return [
            'success' => $response->isOk(),
            'statusCode' => $response->getStatusCode(),
            'body' => (string)$response->getBody(),
            'error' => $response->isOk() ? null : "HTTP {$response->getStatusCode()}",
        ];
    }
}
