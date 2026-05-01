<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\NotificationService;
use App\Service\SidebarCounterService;
use App\Service\SystemSettingsService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class SystemSettingsController extends AppController
{
    /**
     * @param \App\Service\SystemSettingsService $settingsService Settings service.
     * @param \App\Service\NotificationService $notificationService Notification service.
     * @param \App\Service\AuthorizationService $authService Authorization service.
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters.
     * @param \Cake\Http\ServerRequest|null $request Request.
     * @param \Cake\Http\Response|null $response Response.
     * @param string|null $name Controller name.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Event manager.
     * @param \Cake\Controller\ComponentRegistry|null $components Component registry.
     */
    public function __construct(
        private readonly SystemSettingsService $settingsService,
        private readonly NotificationService $notificationService,
        AuthorizationService $authService,
        SidebarCounterService $counterService,
        ?ServerRequest $request = null,
        ?Response $response = null,
        ?string $name = null,
        ?EventManagerInterface $eventManager = null,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($authService, $counterService, $request, $response, $name, $eventManager, $components);
    }

    public function index()
    {
        $smtpSettings = $this->settingsService->getGroup('smtp');
        $n8nSettings = $this->settingsService->getGroup('n8n');

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

        $this->set(compact('smtpSettings', 'n8nSettings'));
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
