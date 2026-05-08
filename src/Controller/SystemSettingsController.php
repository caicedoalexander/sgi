<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NotificationService;
use App\Service\SystemSettingsService;

class SystemSettingsController extends AppController
{
    private SystemSettingsService $settingsService;

    private NotificationService $notificationService;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->settingsService = $container->get(SystemSettingsService::class);
        $this->notificationService = $container->get(NotificationService::class);
    }

    public function index()
    {
        $smtpSettings = $this->settingsService->getGroup('smtp');
        $n8nSettings = $this->settingsService->getGroup('n8n');
        $apiSettings = $this->settingsService->getGroup('api');

        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();
            $formType = $data['_form_type'] ?? 'smtp';

            if ($formType === 'smtp') {
                $smtpKeys = [
                    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
                    'smtp_encryption', 'smtp_from_email', 'smtp_from_name',
                ];

                foreach ($smtpKeys as $key) {
                    if (array_key_exists($key, $data)) {
                        if ($key === 'smtp_password' && empty($data[$key])) {
                            continue;
                        }
                        $this->settingsService->set($key, $data[$key] ?: null, 'smtp');
                    }
                }

                $this->Flash->success('Configuración SMTP actualizada.');
            } elseif ($formType === 'n8n') {
                $n8nKeys = [
                    'n8n_webhook_dian_crosscheck',
                ];

                foreach ($n8nKeys as $key) {
                    if (array_key_exists($key, $data)) {
                        $this->settingsService->set($key, $data[$key] ?: null, 'n8n');
                    }
                }

                $this->Flash->success('Configuración n8n actualizada.');
            }

            return $this->redirect(['action' => 'index']);
        }

        $this->set(compact('smtpSettings', 'n8nSettings', 'apiSettings'));
    }

    public function regenerateApiKey()
    {
        $this->request->allowMethod(['post']);

        $key = bin2hex(random_bytes(32));
        $this->settingsService->set('notifications_api_key', $key, 'api');

        $this->Flash->success('API key regenerada. Recordá actualizar la credencial en n8n.');

        return $this->redirect(['action' => 'index']);
    }

    public function testSmtp()
    {
        $this->request->allowMethod(['post']);

        $result = $this->notificationService->testSmtpConnection();

        if ($result['success']) {
            $this->Flash->success($result['message']);
        } else {
            $this->Flash->error($result['message']);
        }

        return $this->redirect(['action' => 'index']);
    }
}
