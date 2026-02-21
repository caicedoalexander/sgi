<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ApprovalTokenService;

class ExternalApprovalsController extends AppController
{
    private ApprovalTokenService $tokenService;

    public function initialize(): void
    {
        parent::initialize();
        $this->tokenService = new ApprovalTokenService();
        $this->Authentication->allowUnauthenticated(['review', 'process']);
    }

    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        // Skip permission enforcement for external approvals
        // (these are token-based, not role-based)
    }

    public function review($token = null)
    {
        $this->viewBuilder()->setLayout('external');

        $tokenRecord = $this->tokenService->validateToken($token);
        if (!$tokenRecord) {
            $this->set('expired', true);
            return $this->render('expired');
        }

        $entity = $this->tokenService->getEntity($tokenRecord->entity_type, $tokenRecord->entity_id);
        if (!$entity) {
            $this->set('expired', true);
            return $this->render('expired');
        }

        $this->set(compact('token', 'tokenRecord', 'entity'));
    }

    public function process($token = null)
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setLayout('external');

        $tokenRecord = $this->tokenService->validateToken($token);
        if (!$tokenRecord) {
            $this->set('expired', true);
            return $this->render('expired');
        }

        $action = $this->request->getData('action');
        if (!in_array($action, ['approve', 'reject'])) {
            $this->Flash->error('Acción no válida.');
            return $this->redirect(['action' => 'review', $token]);
        }

        $observations = $this->request->getData('observations');
        $ip = $this->request->clientIp();
        $userAgent = $this->request->getHeaderLine('User-Agent');

        $success = $this->tokenService->consumeToken($token, $action, $observations, $ip, $userAgent);

        $this->set(compact('success', 'action'));
        return $this->render('confirmed');
    }
}
