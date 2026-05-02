<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\RoleConstants;
use App\Service\LiquidationDocPaymentService;

class LiquidationDocPaymentsController extends AppController
{
    private LiquidationDocPaymentService $paymentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->paymentService = $this->getContainer()->get(LiquidationDocPaymentService::class);
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

        if ($roleName !== RoleConstants::TESORERIA && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('No tiene permisos para registrar pagos.');

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

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede autorizar pagos.');

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

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede rechazar pagos.');

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
