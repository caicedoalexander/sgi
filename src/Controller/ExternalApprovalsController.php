<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ApprovalTokenService;
use Cake\Event\EventInterface;

class ExternalApprovalsController extends AppController
{
    private ApprovalTokenService $tokenService;

    public function initialize(): void
    {
        parent::initialize();
        $this->tokenService = new ApprovalTokenService();
        // Authentication is required - no allowUnauthenticated
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Skip permission enforcement for external approvals
        // These are token-based + authentication-based, not role-based
    }

    protected function _enforcePermission(object $user): void
    {
        // Override to skip permission checks for this controller
        // Access is controlled by token validation + user identity match
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

        // Validate that logged-in user is the assigned approver
        $identity = $this->Authentication->getIdentity();
        $currentUser = $identity->getOriginalData();

        if ($tokenRecord->entity_type === 'invoices' && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta factura. Solo el aprobador asignado puede hacerlo.');
            $this->set('unauthorized', true);

            return $this->render('expired');
        }

        if ($tokenRecord->entity_type === 'employee_novelties' && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta novedad. Solo el aprobador asignado puede hacerlo.');
            $this->set('unauthorized', true);

            return $this->render('expired');
        }

        $this->set(compact('token', 'tokenRecord', 'entity', 'currentUser'));
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

        // Validate that logged-in user is the assigned approver
        $identity = $this->Authentication->getIdentity();
        $currentUser = $identity->getOriginalData();
        $entity = $this->tokenService->getEntity($tokenRecord->entity_type, $tokenRecord->entity_id);

        if ($tokenRecord->entity_type === 'invoices' && $entity && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta factura.');
            $this->set('expired', true);

            return $this->render('expired');
        }

        if ($tokenRecord->entity_type === 'employee_novelties' && $entity && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta novedad.');
            $this->set('expired', true);

            return $this->render('expired');
        }

        $action = $this->request->getData('action');
        if (!in_array($action, ['approve', 'reject'])) {
            $this->Flash->error('Acción no válida.');

            return $this->redirect(['action' => 'review', $token]);
        }

        $observations = $this->request->getData('observations');
        $approvalDate = $this->request->getData('approval_date');
        $ip = $this->request->clientIp();
        $userAgent = $this->request->getHeaderLine('User-Agent');

        $success = $this->tokenService->consumeToken(
            $token,
            $action,
            $observations,
            $ip,
            $userAgent,
            $approvalDate,
            (int)$currentUser->id,
        );

        $this->set(compact('success', 'action'));

        return $this->render('confirmed');
    }
}
