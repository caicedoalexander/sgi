<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Service\ApprovalTokenService;
use App\Service\AuthorizationService;
use App\Service\InvoiceApprovalService;
use App\Service\InvoicePipelineService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventInterface;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

class ExternalApprovalsController extends AppController
{
    /**
     * @param \App\Service\ApprovalTokenService $tokenService Token service.
     * @param \App\Service\InvoiceApprovalService $approvalService Approval service.
     * @param \App\Service\InvoicePipelineService $pipelineService Pipeline service.
     * @param \App\Service\AuthorizationService $authService Authorization service.
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters.
     * @param \Cake\Http\ServerRequest|null $request Request.
     * @param \Cake\Http\Response|null $response Response.
     * @param string|null $name Controller name.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Event manager.
     * @param \Cake\Controller\ComponentRegistry|null $components Component registry.
     */
    public function __construct(
        private readonly ApprovalTokenService $tokenService,
        private readonly InvoiceApprovalService $approvalService,
        private readonly InvoicePipelineService $pipelineService,
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
                'expires_at' => $approval->token_expires_at,
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

            // Save observation to invoice_observations chat if not empty
            if (!empty($observations)) {
                $actionLabel = $action === 'approve' ? 'Aprobado' : 'Rechazado';
                $this->_saveExternalObservation(
                    $approval->invoice_id,
                    $currentUser->id,
                    "[Aprobación externa - {$actionLabel}] {$observations}",
                );
            }

            if ($result['allApproved']) {
                $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
                $invoice = $invoicesTable->get($result['invoice_id']);

                if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
                    $identity = $this->Authentication->getIdentity();
                    $advanceResult = $this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
                    if (!$advanceResult->success) {
                        Log::warning('External approval: auto-advance falló', [
                            'invoice_id' => $invoice->id,
                            'errors' => $advanceResult->errors,
                        ]);
                    }
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

        // Save observation to invoice_observations chat if not empty
        if ($success && !empty($observations) && $tokenRecord->entity_type === 'invoices') {
            $actionLabel = $action === 'approve' ? 'Aprobado' : 'Rechazado';
            $this->_saveExternalObservation(
                $tokenRecord->entity_id,
                $currentUser->id,
                "[Aprobación externa - {$actionLabel}] {$observations}",
            );
        }

        $this->set(compact('success', 'action'));

        return $this->render('confirmed');
    }

    private function _saveExternalObservation(int $invoiceId, int $userId, string $message): void
    {
        $observationsTable = $this->fetchTable('InvoiceObservations');
        $observation = $observationsTable->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'message' => $message,
        ]);
        $observationsTable->save($observation);
    }
}
