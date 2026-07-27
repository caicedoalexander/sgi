<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\PipelineAction;
use App\Constants\AdvanceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

/**
 * Autorización y rechazo del pago de reintegro (caso "sobrante") de una
 * legalización de anticipo.
 *
 * Vive aparte de `InvoicePaymentsController` porque, aunque el pago es un
 * `InvoicePayment` con `is_refund = true` colgado de la factura del Anticipo,
 * su ciclo de autorización pertenece al pipeline `legalizations`. La factura del
 * Anticipo permanece en `pagada` por diseño, así que `Invoice::canAuthorizePayment()`
 * — que exige `pipeline_status === autorizacion_pago` — es estructuralmente falso
 * para estos pagos.
 *
 * Espejo de `LiquidationDocPaymentsController`: el gate usa el policy y la entidad
 * del pipeline al que pertenece la acción, que es el mismo policy que dibuja los
 * botones en `templates/Advances/legalization.php`.
 */
class AdvanceRefundPaymentsController extends AppController
{
    private InvoicePaymentService $paymentService;
    private AdvanceLegalizationActionPolicy $actionPolicy;
    private AdvanceLegalizationService $legalizationService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->paymentService = $container->get(InvoicePaymentService::class);
        $this->actionPolicy = $container->get(AdvanceLegalizationActionPolicy::class);
        $this->legalizationService = $container->get(AdvanceLegalizationService::class);
    }

    /**
     * Obtiene el ID del rol del usuario autenticado.
     *
     * @return int
     */
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
     * Resuelve la legalización del anticipo. Null si no existe.
     */
    private function _loadLegalization(int $advanceInvoiceId): ?AdvanceLegalization
    {
        return TableRegistry::getTableLocator()
            ->get('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $advanceInvoiceId])
            ->first();
    }

    /**
     * Autoriza el pago de reintegro (caso "sobrante") de una legalización de
     * anticipo. Delega en `InvoicePaymentService::authorizePayment()`, que ya
     * soporta `is_refund` — dispara `InvoiceRefundAuthorizedEvent` y avanza la
     * legalización a `verificacion_pago` vía `RefundOutcomeSubscriber`.
     *
     * @param int|null $advanceId Invoice ID del Anticipo.
     * @param int|null $paymentId InvoicePayment ID del reintegro.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS, step: AdvanceConstants::STATUS_AUTORIZACION_PAGO)]
    public function authorizePayment(?int $advanceId = null, ?int $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$advanceId);

        if (!$leg) {
            $this->Flash->error('Legalización no encontrada.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'index']);
        }

        if (!$this->actionPolicy->canAuthorizeRefundPayment($leg, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
        }

        // El pago debe ser el reintegro de ESTA legalización. Sin esto, el gate
        // validaría la legalización mientras el service opera sobre un paymentId
        // arbitrario (IDOR). `surplus_payment_id === null` se rechaza explícito:
        // si no hay reintegro registrado, (int)null es 0 y no debe colisionar con
        // un paymentId igualmente falsy.
        if ($leg->surplus_payment_id === null || (int)$leg->surplus_payment_id !== (int)$paymentId) {
            $this->Flash->error('El pago no corresponde a esta legalización.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            $this->Flash->success('Reintegro autorizado. La legalización pasó a verificación de pago.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'pendingLegalization']);
        }

        $this->Flash->error('No se pudo autorizar el reintegro.');

        return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
    }

    /**
     * Rechaza el pago de reintegro (caso "sobrante") de una legalización de
     * anticipo. Delega en `InvoicePaymentService::rejectPayment()`, que dispara
     * `InvoiceRefundRejectedEvent` y devuelve la legalización a `tesoreria` vía
     * `RefundOutcomeSubscriber`.
     *
     * Usa `canAuthorizeRefundPayment()` también para el gate de rechazo — no hay
     * un predicado dedicado. Igual que en el dominio de facturas
     * (`Invoice::canRejectPayment()` es literalmente `return $this->canAuthorizePayment();`)
     * y en la vista (`payment_section.php` dibuja "Autorizar" y "Rechazar" con el
     * mismo flag `$canAuthorize`), autorizar y rechazar comparten la misma
     * condición de rol×paso×estado.
     *
     * @param int|null $advanceId Invoice ID del Anticipo.
     * @param int|null $paymentId InvoicePayment ID del reintegro.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS, step: AdvanceConstants::STATUS_AUTORIZACION_PAGO)]
    public function rejectPayment(?int $advanceId = null, ?int $paymentId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$advanceId);

        if (!$leg) {
            $this->Flash->error('Legalización no encontrada.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'index']);
        }

        if (!$this->actionPolicy->canAuthorizeRefundPayment($leg, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
        }

        // El pago debe ser el reintegro de ESTA legalización. Sin esto, el gate
        // validaría la legalización mientras el service opera sobre un paymentId
        // arbitrario (IDOR). `surplus_payment_id === null` se rechaza explícito:
        // si no hay reintegro registrado, (int)null es 0 y no debe colisionar con
        // un paymentId igualmente falsy.
        if ($leg->surplus_payment_id === null || (int)$leg->surplus_payment_id !== (int)$paymentId) {
            $this->Flash->error('El pago no corresponde a esta legalización.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
        }

        $reason = (string)$this->request->getData('reason');
        $result = $this->paymentService->rejectPayment(
            (int)$paymentId,
            (int)$this->_getCurrentUser()->id,
            $reason,
        );

        if ($result->success) {
            $this->Flash->success($result->data);

            return $this->redirect(['controller' => 'Advances', 'action' => 'pendingLegalization']);
        }

        $this->Flash->error($result->firstError() ?? 'No se pudo rechazar el reintegro.');

        return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
    }

    /**
     * Tesorería confirma que el reintegro al beneficiario ya se ejecutó.
     * Cierra la legalización (caso sobrante) de `verificacion_pago` → `legalizada`.
     *
     * Movida desde AdvancesController para agrupar las 3 acciones del ciclo del
     * reintegro (autorizar / rechazar / confirmar) en un solo controller, como
     * LiquidationDocPaymentsController agrupa las suyas.
     *
     * @param int|null $advanceId Invoice ID del Anticipo.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function confirmPayment(?int $advanceId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$advanceId);
        if (!$leg) {
            $this->Flash->error('Legalización no encontrada.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'index']);
        }
        if (!$this->actionPolicy->canConfirmRefundPayment($leg, $this->_getRoleId())) {
            $this->Flash->error('No tiene permisos para operar este paso del pipeline.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
        }
        $result = $this->legalizationService->confirmRefundExecuted($leg, (int)$this->_getCurrentUser()->id);
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al confirmar el reintegro.');

            return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
        }

        $this->Flash->success('Reintegro confirmado. La legalización quedó cerrada.');

        // El cierre siempre deja la legalización en `legalizada`, así que el
        // destino es siempre el hub de consulta (equivale a _redirectAfterTransition).
        return $this->redirect(['controller' => 'Advances', 'action' => 'view', $advanceId]);
    }
}
