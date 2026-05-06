<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Service\LiquidationDocPaymentService;
use App\Service\PipelineAuthorizationService;

class LiquidationDocPaymentsController extends AppController
{
    private LiquidationDocPaymentService $paymentService;
    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->paymentService = $this->getContainer()->get(LiquidationDocPaymentService::class);
        $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
    }

    private function _getRoleId(): int
    {
        return (int)$this->_getCurrentUser()->role_id;
    }

    /**
     * Get the current authenticated user entity.
     *
     * @return object
     */
    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    /**
     * Get the role name for the current user.
     *
     * @return string
     */
    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    /**
     * Register a new payment for a liquidation document.
     *
     * @param string|null $docId Document ID.
     * @return \Cake\Http\Response|null
     */
    public function addPayment(?string $docId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_TESORERIA,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->registerPayment(
            (int)$docId,
            $this->request->getData(),
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error($result->firstError());
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }

    /**
     * Authorize a pending payment.
     *
     * @param string|null $docId Document ID.
     * @param string|null $paymentId Payment ID.
     * @return \Cake\Http\Response|null
     */
    public function authorizePayment(?string $docId = null, ?string $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            $this->Flash->success('Pago autorizado. Documento marcado como Pagado.');
        } else {
            $this->Flash->error('No se pudo autorizar el pago.');
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }

    /**
     * Reject a pending payment.
     *
     * @param string|null $docId Document ID.
     * @param string|null $paymentId Payment ID.
     * @return \Cake\Http\Response|null
     */
    public function rejectPayment(?string $docId = null, ?string $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->rejectPayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error($result->firstError());
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }
}
