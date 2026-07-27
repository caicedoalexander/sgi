<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\Approval\ConsumeContext;
use App\Service\Approval\ExternalApprovalService;
use App\Service\Approval\TokenRecord;
use App\Service\InvoiceApprovalService;
use App\Service\RefundApprovalService;
use Cake\ORM\TableRegistry;

class ExternalApprovalsController extends AppController
{
    private ExternalApprovalService $tokenService;

    private InvoiceApprovalService $approvalService;

    private RefundApprovalService $refundApprovalService;

    private AdvanceLegalizationApprovalService $advanceApprovalService;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->tokenService = $container->get(ExternalApprovalService::class);
        $this->approvalService = $container->get(InvoiceApprovalService::class);
        $this->refundApprovalService = $container->get(RefundApprovalService::class);
        $this->advanceApprovalService = $container->get(AdvanceLegalizationApprovalService::class);
    }

    /**
     * Muestra la pantalla de revisión de una aprobación externa.
     *
     * @param string|null $token Token de aprobación.
     * @return \Cake\Http\Response|null
     */
    #[NoAuthGate(reason: 'External approval via random token + identity match; sin gate de módulo')]
    public function review(?string $token = null)
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

            $tokenRecord = new TokenRecord('invoices', (int)$entity->id, $approval->token_expires_at);

            $this->set(compact('token', 'tokenRecord', 'entity', 'currentUser'));
            $this->set('isMultiApprover', true);

            return;
        }

        // Refund group token path (refund_approvals table)
        $groupApproval = $this->refundApprovalService->validateToken($token);
        if ($groupApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($groupApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización para aprobar este reintegro.');
                $this->set('unauthorized', true);

                return $this->render('expired');
            }
            $refund = TableRegistry::getTableLocator()->get('Refunds')->get(
                $groupApproval->refund_id,
                contain: [
                    'Invoices' => [
                        'sort' => ['Invoices.id' => 'ASC'],
                        'Providers',
                        'InvoiceDocuments',
                    ],
                    'BeneficiaryEmployees',
                    'BeneficiaryProviders',
                ],
            );
            $this->set(compact('token', 'refund', 'currentUser'));

            return $this->render('review_group');
        }

        // Advance legalization group token path (advance_legalization_approvals table)
        $advApproval = $this->advanceApprovalService->validateToken($token);
        if ($advApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($advApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización para aprobar esta legalización.');
                $this->set('unauthorized', true);

                return $this->render('expired');
            }
            $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
            $leg = $legTable->get($advApproval->advance_legalization_id);
            $invoices = TableRegistry::getTableLocator()->get('Invoices');
            $anticipo = $invoices->get($leg->advance_invoice_id, contain: ['Providers', 'Employees', 'InvoiceDocuments']);
            $linkedInvoices = $invoices->find()
                ->where([
                    'Invoices.advance_id' => $leg->advance_invoice_id,
                    'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                ])
                ->contain(['Providers', 'Employees', 'InvoiceDocuments'])
                ->orderBy(['Invoices.id' => 'ASC'])
                ->all();
            $this->set(compact('token', 'leg', 'anticipo', 'linkedInvoices', 'currentUser'));

            return $this->render('review_group_advance');
        }

        // Generic single-entity token path (novelties, old invoice tokens)
        $record = $this->tokenService->resolve($token);
        if (!$record) {
            $this->set('expired', true);

            return $this->render('expired');
        }

        $tokenRecord = new TokenRecord($record->entity_type, (int)$record->entity_id, $record->expires_at);

        $entity = $this->tokenService->getEntity($tokenRecord->entityType, $tokenRecord->entityId);
        if (!$entity) {
            $this->set('expired', true);

            return $this->render('expired');
        }

        $identity = $this->Authentication->getIdentity();
        $currentUser = $identity->getOriginalData();

        if ($tokenRecord->entityType === 'invoices' && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta factura. Solo el aprobador asignado puede hacerlo.');
            $this->set('unauthorized', true);

            return $this->render('expired');
        }

        if ($tokenRecord->entityType === 'employee_novelties' && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta novedad. Solo el aprobador asignado puede hacerlo.');
            $this->set('unauthorized', true);

            return $this->render('expired');
        }

        $this->set(compact('token', 'tokenRecord', 'entity', 'currentUser'));
        $this->set('isMultiApprover', false);
    }

    /**
     * Procesa la decisión de una aprobación externa.
     *
     * @param string|null $token Token de aprobación.
     * @return \Cake\Http\Response|null
     */
    #[NoAuthGate(reason: 'External approval via random token + identity match; sin gate de módulo')]
    public function process(?string $token = null)
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setLayout('external');

        $action = $this->request->getData('action');
        if (!in_array($action, ApprovalConstants::ACTIONS, true)) {
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

            if (!$result->success) {
                $this->Flash->error($result->firstError() ?? 'Error al procesar respuesta');

                return $this->redirect(['action' => 'review', $token]);
            }

            // Save observation to invoice_observations chat if not empty
            if (!empty($observations)) {
                $this->_saveExternalObservation(
                    $approval->invoice_id,
                    $currentUser->id,
                    $observations,
                    $action,
                );
            }

            $success = true;
            $this->set(compact('success', 'action'));

            return $this->render('confirmed');
        }

        // Refund group token path
        $groupApproval = $this->refundApprovalService->validateToken($token);
        if ($groupApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($groupApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización.');
                $this->set('expired', true);

                return $this->render('expired');
            }
            $result = $this->refundApprovalService->processResponse(
                $token,
                $action,
                $this->request->getData('observations'),
                $this->request->clientIp(),
                $this->request->getHeaderLine('User-Agent'),
            );
            if (!$result->success) {
                $this->Flash->error($result->firstError() ?? 'Error al procesar respuesta');

                return $this->redirect(['action' => 'review', $token]);
            }
            $success = true;
            $this->set(compact('success', 'action'));

            return $this->render('confirmed');
        }

        // Advance legalization group token path
        $advApproval = $this->advanceApprovalService->validateToken($token);
        if ($advApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($advApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización.');
                $this->set('expired', true);

                return $this->render('expired');
            }
            $result = $this->advanceApprovalService->processResponse(
                $token,
                $action,
                $this->request->getData('observations'),
                $this->request->clientIp(),
                $this->request->getHeaderLine('User-Agent'),
            );
            if (!$result->success) {
                $this->Flash->error($result->firstError() ?? 'Error al procesar respuesta');

                return $this->redirect(['action' => 'review', $token]);
            }
            $success = true;
            $this->set(compact('success', 'action'));

            return $this->render('confirmed');
        }

        // Generic single-entity token path
        $record = $this->tokenService->resolve($token);
        if (!$record) {
            $this->set('expired', true);

            return $this->render('expired');
        }

        $identity = $this->Authentication->getIdentity();
        $currentUser = $identity->getOriginalData();
        $tokenRecord = new TokenRecord($record->entity_type, (int)$record->entity_id, $record->expires_at);
        $entity = $this->tokenService->getEntity($tokenRecord->entityType, $tokenRecord->entityId);

        if ($tokenRecord->entityType === 'invoices' && $entity && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta factura.');
            $this->set('expired', true);

            return $this->render('expired');
        }

        if ($tokenRecord->entityType === 'employee_novelties' && $entity && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta novedad.');
            $this->set('expired', true);

            return $this->render('expired');
        }

        $observations = $this->request->getData('observations');
        $approvalDate = date('Y-m-d');
        $ip = $this->request->clientIp();
        $userAgent = $this->request->getHeaderLine('User-Agent');

        $success = $this->tokenService->consume(
            $token,
            $action,
            new ConsumeContext($observations, $ip, $userAgent, $approvalDate, (int)$currentUser->id),
        );

        // Save observation to invoice_observations chat if not empty
        if ($success && !empty($observations) && $tokenRecord->entityType === 'invoices') {
            $this->_saveExternalObservation(
                $tokenRecord->entityId,
                $currentUser->id,
                $observations,
                $action,
            );
        }

        $this->set(compact('success', 'action'));

        return $this->render('confirmed');
    }

    /**
     * Registra una observación de la aprobación externa.
     *
     * @param int $invoiceId ID de la factura.
     * @param int $userId ID del usuario.
     * @param string $message Mensaje de la observación.
     * @param string $action Acción realizada.
     * @return void
     */
    private function _saveExternalObservation(int $invoiceId, int $userId, string $message, string $action): void
    {
        $observationsTable = $this->fetchTable('InvoiceObservations');
        $observation = $observationsTable->newEntity([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'type' => InvoiceConstants::OBSERVATION_TYPE_EXTERNAL_APPROVAL,
            'message' => $message,
            'metadata' => ['action' => $action],
        ]);
        $observationsTable->save($observation);
    }
}
