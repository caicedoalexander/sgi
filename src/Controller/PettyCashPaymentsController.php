<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\RoleConstants;
use App\Service\PettyCashPaymentService;
use Cake\Http\Response;

class PettyCashPaymentsController extends AppController
{
    private PettyCashPaymentService $paymentService;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->paymentService = new PettyCashPaymentService();
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
     * Register a new payment for a petty cash record.
     *
     * @param string|null $recordId Record ID.
     * @return \Cake\Http\Response|null
     */
    public function addPayment(?string $recordId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::TESORERIA && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('No tiene permisos para registrar pagos.');

            return $this->redirect(['controller' => 'PettyCashRecords', 'action' => 'edit', $recordId]);
        }

        $result = $this->paymentService->registerPayment(
            (int)$recordId,
            $this->request->getData(),
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error($result->firstError());
        }

        return $this->redirect(['controller' => 'PettyCashRecords', 'action' => 'edit', $recordId]);
    }

    /**
     * Authorize a pending payment.
     *
     * @param string|null $recordId Record ID.
     * @param string|null $paymentId Payment ID.
     * @return \Cake\Http\Response|null
     */
    public function authorizePayment(?string $recordId = null, ?string $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede autorizar pagos.');

            return $this->redirect(['controller' => 'PettyCashRecords', 'action' => 'edit', $recordId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            $this->Flash->success('Pago autorizado. Registro marcado como Pagado.');
        } else {
            $this->Flash->error('No se pudo autorizar el pago.');
        }

        return $this->redirect(['controller' => 'PettyCashRecords', 'action' => 'edit', $recordId]);
    }

    /**
     * Reject a pending payment.
     *
     * @param string|null $recordId Record ID.
     * @param string|null $paymentId Payment ID.
     * @return \Cake\Http\Response|null
     */
    public function rejectPayment(?string $recordId = null, ?string $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede rechazar pagos.');

            return $this->redirect(['controller' => 'PettyCashRecords', 'action' => 'edit', $recordId]);
        }

        $result = $this->paymentService->rejectPayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error($result->firstError());
        }

        return $this->redirect(['controller' => 'PettyCashRecords', 'action' => 'edit', $recordId]);
    }
}
