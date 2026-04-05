<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Service\ApprovalTokenService;
use App\Service\InvoiceApprovalService;
use App\Service\InvoicePipelineService;
use Cake\Event\EventInterface;
use Cake\ORM\TableRegistry;

class ExternalApprovalsController extends AppController
{
    private ApprovalTokenService $tokenService;
    private InvoiceApprovalService $approvalService;
    private InvoicePipelineService $pipelineService;

    public function initialize(): void
    {
        parent::initialize();
        $this->tokenService = new ApprovalTokenService();
        $this->approvalService = new InvoiceApprovalService();
        $this->pipelineService = new InvoicePipelineService();
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
    }

    protected function _enforcePermission(object $user): void
    {
        // Override to skip permission checks for this controller
        // Access is controlled by token validation + user identity match
    }

    public function review($token = null)
    {
        $this->viewBuilder()->setLayout('external');

        // Try multi-approver token first (invoice_approvals table)
        $approval = $this->approvalService->validateToken($token);
        if ($approval) {
            $entity = $approval->invoice;

            // Validate that logged-in user is the assigned approver
            $identity = $this->Authentication->getIdentity();
            $currentUser = $identity->getOriginalData();

            if ($approval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización para aprobar esta factura. Solo el aprobador asignado puede hacerlo.');
                $this->set('unauthorized', true);

                return $this->render('expired');
            }

            $tokenRecord = (object)[
                'entity_type' => 'invoices',
                'entity_id' => $entity->id,
            ];

            $this->set(compact('token', 'tokenRecord', 'entity', 'currentUser'));
            $this->set('isMultiApprover', true);

            return;
        }

        // Fall back to legacy ApprovalTokenService (novelties, old invoice tokens)
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
        $this->set('isMultiApprover', false);
    }

    public function process($token = null)
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setLayout('external');

        $action = $this->request->getData('action');
        if (!in_array($action, ['approve', 'reject'])) {
            $this->Flash->error('Acción no válida.');

            return $this->redirect(['action' => 'review', $token]);
        }

        // Try multi-approver token first
        $approval = $this->approvalService->validateToken($token);
        if ($approval) {
            $identity = $this->Authentication->getIdentity();
            $currentUser = $identity->getOriginalData();

            if ($approval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización para aprobar esta factura.');
                $this->set('expired', true);

                return $this->render('expired');
            }

            $observations = $this->request->getData('observations');
            $ipAddress = $this->request->clientIp();
            $userAgent = $this->request->getHeaderLine('User-Agent');

            $result = $this->approvalService->processResponse($token, $action, $observations, $ipAddress, $userAgent);

            if (!$result['success']) {
                $this->Flash->error($result['errors'][0] ?? 'Error al procesar respuesta');

                return $this->redirect(['action' => 'review', $token]);
            }

            if ($result['allApproved']) {
                $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
                $invoice = $invoicesTable->get($result['invoice_id']);

                if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
                    $identity = $this->Authentication->getIdentity();
                    $this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
                }
            }

            $success = true;
            $this->set(compact('success', 'action'));

            return $this->render('confirmed');
        }

        // Fall back to legacy ApprovalTokenService
        $tokenRecord = $this->tokenService->validateToken($token);
        if (!$tokenRecord) {
            $this->set('expired', true);

            return $this->render('expired');
        }

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

        $observations = $this->request->getData('observations');
        $approvalDate = date('Y-m-d');
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
