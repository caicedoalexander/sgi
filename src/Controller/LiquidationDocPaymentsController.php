<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\PipelineAction;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Service\LiquidationDocPaymentService;
use App\Service\PipelineAuthorizationService;
use Cake\Http\Response;

class LiquidationDocPaymentsController extends AppController
{
    /**
     * Todas las acciones de este controlador operan pasos del pipeline de
     * novedades (Tesorería/Autorización de pago/Verificación de pago) y validan
     * internamente vía `PipelineAuthorizationService::canOperate`. Saltan el
     * gate CRUD del módulo `novelty_liquidation_docs`.
     *
     * @var array<int, string>
     */
    protected array $pipelineActions = [
        'addPayment',
        'authorizePayment',
        'confirmPayment',
        'rejectPayment',
    ];

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
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, step: NoveltyConstants::STATUS_TESORERIA)]
    public function addPayment(?string $docId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
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
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, step: NoveltyConstants::STATUS_AUTORIZACION_PAGO)]
    public function authorizePayment(?string $docId = null, ?string $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_AUTORIZACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            $this->Flash->success('Pago autorizado. Documento en verificación de pago — Tesorería debe confirmar la ejecución.');
        } else {
            $this->Flash->error('No se pudo autorizar el pago.');
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }

    /**
     * Tesorería confirma que el pago del documento de liquidación ya se ejecutó.
     * Avanza doc y novedades hijas de verificacion_pago → pagada.
     *
     * @param string|null $docId Document ID.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, step: NoveltyConstants::STATUS_VERIFICACION_PAGO)]
    public function confirmPayment(?string $docId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
                PipelineStepConstants::PIPELINE_NOVELTIES,
                NoveltyConstants::STATUS_VERIFICACION_PAGO,
            )
        ) {
            $this->Flash->error('No tiene permisos para confirmar este pago.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'view', $docId]);
        }

        $result = $this->paymentService->confirmPayment((int)$docId, (int)$this->_getCurrentUser()->id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago confirmado.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo confirmar el pago.');
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'view', $docId]);
    }

    /**
     * Reject a pending payment.
     *
     * @param string|null $docId Document ID.
     * @param string|null $paymentId Payment ID.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, step: NoveltyConstants::STATUS_AUTORIZACION_PAGO)]
    public function rejectPayment(?string $docId = null, ?string $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        if (
            !$this->pipelineAuth->canOperate(
                $this->_getRoleId(),
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
