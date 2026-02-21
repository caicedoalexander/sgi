<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NotificationService;
use App\Service\SystemSettingsService;

class SystemSettingsController extends AppController
{
    private SystemSettingsService $settingsService;

    public function initialize(): void
    {
        parent::initialize();
        $this->settingsService = new SystemSettingsService();
    }

    public function index()
    {
        $smtpSettings = $this->settingsService->getGroup('smtp');

        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();
            $smtpKeys = [
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
                'smtp_encryption', 'smtp_from_email', 'smtp_from_name',
            ];

            foreach ($smtpKeys as $key) {
                if (array_key_exists($key, $data)) {
                    // Don't overwrite password if left empty
                    if ($key === 'smtp_password' && empty($data[$key])) {
                        continue;
                    }
                    $this->settingsService->set($key, $data[$key] ?: null);
                }
            }

            $this->Flash->success('Configuración SMTP actualizada.');
            return $this->redirect(['action' => 'index']);
        }

        $this->set(compact('smtpSettings'));
    }

    public function testSmtp()
    {
        $this->request->allowMethod(['post']);

        $notificationService = new NotificationService();
        $result = $notificationService->testSmtpConnection();

        if ($result['success']) {
            $this->Flash->success($result['message']);
        } else {
            $this->Flash->error($result['message']);
        }

        return $this->redirect(['action' => 'index']);
    }
}
