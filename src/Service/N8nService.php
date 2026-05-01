<?php
declare(strict_types=1);

namespace App\Service;

class N8nService
{
    /**
     * Constructor.
     */
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly SystemSettingsService $settingsService,
    ) {
    }

    /**
     * Send JSON data to an n8n webhook identified by its settings key.
     */
    public function sendData(string $webhookKey, array $data): array
    {
        $url = $this->getWebhookUrl($webhookKey);
        if (!$url) {
            return [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => "Webhook URL not configured for key: {$webhookKey}",
            ];
        }

        return $this->webhookService->sendJson($url, $data);
    }

    /**
     * Send a file to an n8n webhook identified by its settings key.
     */
    public function sendFile(
        string $webhookKey,
        string $filePath,
        string $fieldName = 'file',
        array $extraData = [],
    ): array {
        $url = $this->getWebhookUrl($webhookKey);
        if (!$url) {
            return [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => "Webhook URL not configured for key: {$webhookKey}",
            ];
        }

        return $this->webhookService->sendFile($url, $filePath, $fieldName, $extraData);
    }

    /**
     * Check if a webhook key has a URL configured.
     */
    public function isConfigured(string $webhookKey): bool
    {
        return !empty($this->getWebhookUrl($webhookKey));
    }

    /**
     * @param string $webhookKey Settings key for the webhook URL.
     * @return string|null
     */
    private function getWebhookUrl(string $webhookKey): ?string
    {
        return $this->settingsService->get($webhookKey);
    }
}
