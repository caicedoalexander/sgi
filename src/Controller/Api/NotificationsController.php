<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Service\PendingNotificationsService;
use App\Service\SystemSettingsService;
use Cake\Event\EventInterface;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;

/**
 * API endpoint consumido por el workflow de n8n para enviar el digest
 * horario de pendientes a los usuarios. Autentica por header X-Api-Key.
 */
class NotificationsController extends AppController
{
    public function beforeFilter(EventInterface $event): void
    {
        // Saltarse el flujo del AppController (auth de sesión, sidebar counters,
        // chequeo de permisos por módulo). Este endpoint usa API key.
        $this->Authentication->allowUnauthenticated(['pending']);

        $settings = $this->getContainer()->get(SystemSettingsService::class);
        $expected = (string)$settings->get('notifications_api_key');
        $provided = (string)$this->request->getHeaderLine('X-Api-Key');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            throw new UnauthorizedException('API key inválida o no configurada.');
        }
    }

    public function pending(): Response
    {
        $service = $this->getContainer()->get(PendingNotificationsService::class);
        $users = $service->getPendingByUser();

        return $this->_jsonResponse([
            'generated_at' => date('c'),
            'count' => count($users),
            'users' => $users,
        ]);
    }
}
