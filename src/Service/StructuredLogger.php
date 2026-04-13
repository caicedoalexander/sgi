<?php
declare(strict_types=1);

namespace App\Service;

use App\Middleware\CorrelationIdMiddleware;
use Cake\Log\Log;

class StructuredLogger
{
    private string $context;

    /**
     * @param string $context The logger context name.
     */
    public function __construct(string $context)
    {
        $this->context = $context;
    }

    /**
     * @param string $message Log message.
     * @param array $data Additional data.
     * @return void
     */
    public function info(string $message, array $data = []): void
    {
        Log::info($this->_format($message, $data));
    }

    /**
     * @param string $message Log message.
     * @param array $data Additional data.
     * @return void
     */
    public function warning(string $message, array $data = []): void
    {
        Log::warning($this->_format($message, $data));
    }

    /**
     * @param string $message Log message.
     * @param array $data Additional data.
     * @return void
     */
    public function error(string $message, array $data = []): void
    {
        Log::error($this->_format($message, $data));
    }

    /**
     * @param string $message Log message.
     * @param array $data Additional data.
     * @return string
     */
    private function _format(string $message, array $data): string
    {
        $entry = [
            'context' => $this->context,
            'correlationId' => CorrelationIdMiddleware::getId(),
            'message' => $message,
        ];

        if (!empty($data)) {
            $entry['data'] = $data;
        }

        return (string)json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
