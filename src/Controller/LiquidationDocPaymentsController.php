<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\RoleConstants;
use App\Service\AuthorizationService;
use App\Service\LiquidationDocPaymentService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class LiquidationDocPaymentsController extends AppController
{
    /**
     * @param \App\Service\LiquidationDocPaymentService $paymentService Payment service.
     * @param \App\Service\AuthorizationService $authService Authorization service.
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters.
     * @param \Cake\Http\ServerRequest|null $request Request.
     * @param \Cake\Http\Response|null $response Response.
     * @param string|null $name Controller name.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Event manager.
     * @param \Cake\Controller\ComponentRegistry|null $components Component registry.
     */
    public function __construct(
        private readonly LiquidationDocPaymentService $paymentService,
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
