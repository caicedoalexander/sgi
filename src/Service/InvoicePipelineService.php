<?php
declare(strict_types=1);

namespace App\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Event\InvoicePaidEvent;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry;
use App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceLockPolicy;
use App\Service\Pipeline\Invoice\Policy\InvoiceTransitionValidator;
use App\ValueObject\UserContext;
use Cake\Event\Event;
use Cake\Event\EventManagerInterface;
use Cake\ORM\TableRegistry;

/**
 * Coordinador delgado del pipeline de facturas.
 * Delega a States, DocumentTypePolicy, LockPolicy y TransitionValidator.
 *
 * API pública preservada para no romper callers (controllers, strategies, templates).
 */
class InvoicePipelineService
{
    /**
     * @param \App\Service\Interface\HistoryServiceInterface $historyService Servicio de auditoría de facturas.
     * @param \App\Service\InvoicePaymentService $paymentService Servicio de pagos de facturas.
     * @param \App\Service\Pipeline\Invoice\Policy\InvoiceFieldAccessPolicy $fieldPolicy Policy de campos/secciones editables por paso.
     * @param \App\Service\Pipeline\Invoice\Policy\InvoiceLockPolicy $lockPolicy Policy de bloqueo de edición/regresión.
     * @param \App\Service\Pipeline\Invoice\Policy\InvoiceTransitionValidator $transitionValidator Validador de requisitos de avance.
     * @param \App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry $states Registro de estados del pipeline.
     * @param \App\Service\Pipeline\Invoice\DocumentTypePolicyFactory $docTypePolicies Factory de policies por tipo de documento.
     * @param \Cake\Event\EventManagerInterface $events Event manager para publicar eventos del pipeline.
     * @param \App\Authorization\AuthorizationFacade $auth Fachada de autorización de pasos de pipeline.
     */
    public function __construct(
        private readonly HistoryServiceInterface $historyService,
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
        private readonly InvoiceLockPolicy $lockPolicy,
        private readonly InvoiceTransitionValidator $transitionValidator,
        private readonly InvoicePipelineStateRegistry $states,
        private readonly DocumentTypePolicyFactory $docTypePolicies,
        private readonly EventManagerInterface $events,
        private readonly AuthorizationFacade $auth,
    ) {
    }

    public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;

    /**
     * Retorna los estados del pipeline que el rol puede operar.
     *
     * @param int $roleId Id del rol.
     * @return array
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
        );
    }

    /**
     * Retorna los estados de pipeline visibles para un tipo de documento.
     *
     * @param string|null $documentType Tipo de documento de la factura.
     * @param object|null $invoice Factura (para resolver variantes por vínculo).
     * @return array
     */
    public function getPipelineStatusesFor(?string $documentType = null, ?object $invoice = null): array
    {
        return $this->docTypePolicies->for($documentType)->getPipelineStatusesForView($invoice);
    }

    /**
     * Retorna los campos editables por un rol en un estado dado.
     *
     * @param int $roleId Id del rol.
     * @param string $status Estado del pipeline.
     * @return array
     */
    public function getEditableFields(int $roleId, string $status): array
    {
        return $this->fieldPolicy->getEditableFields($roleId, $status);
    }

    /**
     * Retorna las secciones del formulario visibles por rol, estado y tipo de documento.
     *
     * @param int $roleId Id del rol.
     * @param string $status Estado del pipeline.
     * @param string|null $documentType Tipo de documento de la factura.
     * @param object|null $invoice Factura (para filtrar secciones por vínculo).
     * @return array
     */
    public function getVisibleSections(int $roleId, string $status, ?string $documentType = null, ?object $invoice = null): array
    {
        $sections = $this->fieldPolicy->getVisibleSections($roleId, $status);

        return $this->docTypePolicies->for($documentType)->filterVisibleSections($sections, $invoice);
    }

    /**
     * Indica si la factura está rechazada en la aprobación de área.
     *
     * @param object $invoice Factura o entidad equivalente.
     * @return bool
     */
    public function isRejected(object $invoice): bool
    {
        if ($invoice instanceof Invoice) {
            return $invoice->isRejected();
        }

        return ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    /**
     * Indica si la factura está bloqueada por una programación de pago pagada.
     *
     * @param int $invoiceId Id de la factura.
     * @return bool
     */
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        return $this->lockPolicy->isLockedByPaidScheduling($invoiceId);
    }

    /**
     * Indica si la factura está bloqueada por pertenecer a una caja menor.
     *
     * @param object $invoice Factura o entidad equivalente.
     * @return bool
     */
    public function isLockedByPettyCash(object $invoice): bool
    {
        return $this->lockPolicy->isLockedByPettyCash($invoice);
    }

    /**
     * Retorna el mensaje de bloqueo de edición, o null si la factura es editable.
     *
     * @param object $invoice Factura o entidad equivalente.
     * @return string|null
     */
    public function getEditLockMessage(object $invoice): ?string
    {
        return $this->lockPolicy->getEditLockMessage($invoice);
    }

    /**
     * Retorna el mensaje de bloqueo de regresión, o null si puede regresar.
     *
     * @param object $invoice Factura o entidad equivalente.
     * @return string|null
     */
    public function getRegressionLockMessage(object $invoice): ?string
    {
        $lockMsg = $this->lockPolicy->getRegressionLockMessage($invoice);
        if ($lockMsg !== null) {
            return $lockMsg;
        }

        return $this->docTypePolicies->for($invoice->document_type ?? null)->getRegressionLockReason($invoice);
    }

    /**
     * Valida los requisitos de avance desde un estado y retorna los errores por requisito.
     *
     * @param object $invoice Factura o entidad equivalente.
     * @param string $fromStatus Estado de origen.
     * @param array $overrides Valores sobreescritos a validar (datos del formulario).
     * @return array
     */
    public function validateTransitionRequirements(object $invoice, string $fromStatus, array $overrides = []): array
    {
        return $this->transitionValidator->validateAdvance($invoice, $fromStatus, $overrides);
    }

    /**
     * Filtra los errores de avance dejando solo los del rol para el estado dado.
     *
     * @param array $errors Errores keyed por requisito.
     * @param int $roleId Id del rol.
     * @param string $status Estado del pipeline.
     * @return array
     */
    public function filterAdvanceErrorsForRole(array $errors, int $roleId, string $status): array
    {
        return $this->transitionValidator->filterErrorsForRole($errors, $roleId, $status);
    }

    /**
     * Retorna el motivo por el que la factura no puede avanzar, o null si puede.
     */
    public function denialReasonForAdvance(Invoice $invoice, int $roleId): ?DenialReason
    {
        if ($this->getNextStatus($invoice->pipeline_status, $invoice->document_type, $invoice->advance_id) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if ($this->isRejected($invoice)) {
            return DenialReason::REJECTED;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_INVOICES,
                $invoice->pipeline_status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Resuelve el siguiente estado del pipeline, o null si es terminal o la policy lo bloquea.
     *
     * @param string $currentStatus Estado actual.
     * @param string|null $documentType Tipo de documento de la factura.
     * @param int|null $advanceId Id del anticipo vinculado (para el freeze del Recibo de Caja).
     * @return string|null
     */
    public function getNextStatus(string $currentStatus, ?string $documentType = null, ?int $advanceId = null): ?string
    {
        $currentEnum = PipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        $state = $this->states->get($currentEnum);
        $policy = $this->docTypePolicies->for($documentType);

        // Cuando la policy bloquea el avance del estado, el next efectivo es null.
        // El stub lleva advance_id para que el freeze del Recibo de Caja vinculado
        // (ReciboCajaDocumentTypePolicy::blocksAdvance) pueda evaluarlo.
        $stub = (object)[
            'document_type' => $documentType,
            'pipeline_status' => $currentStatus,
            'advance_id' => $advanceId,
        ];
        if ($policy->blocksAdvance($state, $stub) !== null) {
            return null;
        }

        return $state->getNextStatus()?->value;
    }

    /**
     * Filtra los datos de entrada dejando solo los campos editables por el rol en el estado.
     *
     * @param array $data Datos del formulario.
     * @param int $roleId Id del rol.
     * @param string $status Estado del pipeline.
     * @return array
     */
    public function filterEntityData(array $data, int $roleId, string $status): array
    {
        return $this->fieldPolicy->filterEntityData($data, $roleId, $status)->patch;
    }

    /**
     * Resuelve el estado anterior del pipeline, o null si es el primero.
     *
     * @param string $currentStatus Estado actual.
     * @return string|null
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        $currentEnum = PipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        return $this->states->get($currentEnum)->getPreviousStatus()?->value;
    }

    /**
     * Retorna el motivo por el que la factura no puede regresar, o null si puede.
     */
    public function denialReasonForRegress(Invoice $invoice, int $roleId): ?DenialReason
    {
        if ($this->getPreviousStatus($invoice->pipeline_status) === null) {
            return DenialReason::TERMINAL_STATE;
        }

        if ($this->isRejected($invoice)) {
            return DenialReason::REJECTED;
        }

        if (
            !$this->auth->canOperate(
                new UserContext($roleId),
                PipelineStepConstants::PIPELINE_INVOICES,
                $invoice->pipeline_status,
            )
        ) {
            return DenialReason::UNAUTHORIZED;
        }

        return null;
    }

    /**
     * Save invoice fields, optionally advance the pipeline, and record history.
     *
     * @return \App\Service\ServiceResult on success: data = ['advanced' => bool, 'nextStatus' => ?string, 'advanceErrors' => array<string, string>]
     */
    public function saveAndAdvance(
        Invoice $invoice,
        array $data,
        int $roleId,
        int $userId,
        ?string $baseUrl = null,
    ): ServiceResult {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $currentStatus = $invoice->pipeline_status;
        $filteredData = $this->filterEntityData($data, $roleId, $currentStatus);

        $original = clone $invoice;

        if (array_key_exists('area_approval', $filteredData)) {
            $newApproval = $filteredData['area_approval'] ?? '';
            if ($newApproval !== ($invoice->area_approval ?? '')) {
                $invoice->setApprovalResult($newApproval);
                unset($filteredData['area_approval']);
            }
        }

        $canAdvance = $this->denialReasonForAdvance($invoice, $roleId) === null;
        $isRejected = $this->isRejected($invoice);

        $advanceNextStatus = null;
        $postAdvanceErrors = [];
        if ($canAdvance) {
            $postAdvanceErrors = $this->validateTransitionRequirements($invoice, $currentStatus, $filteredData);
            if (empty($postAdvanceErrors)) {
                $advanceNextStatus = $this->getNextStatus(
                    $currentStatus,
                    $invoice->document_type,
                    $invoice->advance_id,
                );
            }
        }

        $saved = $invoicesTable->getConnection()->transactional(
            function () use ($invoicesTable, &$invoice, $filteredData, $advanceNextStatus, $currentStatus, $userId, $original) {
                $invoice = $invoicesTable->patchEntity($invoice, $filteredData);

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordChanges($original, $invoice, $userId);

                if ($advanceNextStatus) {
                    $invoice->pipeline_status = $advanceNextStatus;
                    if (!$invoicesTable->save($invoice)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $invoice->id,
                        $currentStatus,
                        $advanceNextStatus,
                        $userId,
                    );

                    // After advancing from autorizacion_pago: regress to tesoreria if pago parcial
                    if ($currentStatus === InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
                        $this->paymentService->recalculatePaymentStatus($invoice->id);
                        $refreshed = $invoicesTable->get($invoice->id);

                        if ($refreshed->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
                            $intermediateStatus = $advanceNextStatus;
                            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
                            $advanceNextStatus = InvoiceConstants::STATUS_TESORERIA;
                            $invoicesTable->save($invoice);
                            $this->historyService->recordStatusChange(
                                $invoice->id,
                                $intermediateStatus,
                                InvoiceConstants::STATUS_TESORERIA,
                                $userId,
                            );
                        }
                    }

                    // Plan 5: publicar InvoicePaidEvent cuando el avance dejó la factura
                    // en pagada. El subscriber LegalizationInitializerSubscriber filtra por
                    // doctype y dispara la inicialización de legalización si corresponde.
                    if ($invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA) {
                        $this->events->dispatch(new Event(
                            'Invoice.paid',
                            null,
                            ['payload' => new InvoicePaidEvent($invoice, $userId)],
                        ));
                    }
                }

                return true;
            },
        );

        if (!(bool)$saved) {
            return ServiceResult::fail(['No se pudo guardar la factura.']);
        }

        return ServiceResult::ok([
            'advanced' => (bool)$advanceNextStatus,
            'nextStatus' => $advanceNextStatus,
            'advanceErrors' => $postAdvanceErrors,
        ]);
    }

    /**
     * Regress the invoice to its previous pipeline status (cold regression).
     *
     * @return \App\Service\ServiceResult on success: data = ['previousStatus' => string]
     */
    public function regress(
        Invoice $invoice,
        int $roleId,
        int $userId,
        string $reason,
    ): ServiceResult {
        $reason = trim($reason);
        $currentStatus = $invoice->pipeline_status;

        $regressDenial = $this->denialReasonForRegress($invoice, $roleId);
        if ($regressDenial !== null) {
            $error = $regressDenial === DenialReason::TERMINAL_STATE
                ? 'Esta factura ya está en el primer paso del flujo.'
                : $regressDenial->message();

            return ServiceResult::fail([$error]);
        }

        $lock = $this->getRegressionLockMessage($invoice);
        if ($lock !== null) {
            return ServiceResult::fail([$lock]);
        }

        if (mb_strlen($reason) < 10) {
            return ServiceResult::fail(['El motivo es obligatorio (mínimo 10 caracteres).']);
        }
        if (mb_strlen($reason) > 500) {
            return ServiceResult::fail(['El motivo no puede superar 500 caracteres.']);
        }

        $previousStatus = $this->getPreviousStatus($currentStatus);
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');

        $ok = $invoicesTable->getConnection()->transactional(
            function () use (
                $invoicesTable,
                $observationsTable,
                $invoice,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
            ): bool {
                $invoice->pipeline_status = $previousStatus;
                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordStatusChange(
                    $invoice->id,
                    $currentStatus,
                    $previousStatus,
                    $userId,
                );
                $this->historyService->recordFieldChange(
                    $invoice->id,
                    'regression_reason',
                    null,
                    $reason,
                    $userId,
                );

                // verificacion_pago → autorizacion_pago: revertir pagos
                // autorizados a pendiente para que el Contador re-revise. El
                // avance volverá a dispararse automáticamente desde
                // InvoicePaymentService::authorizePayment cuando todos los
                // pagos queden authorized de nuevo.
                if (
                    $currentStatus === InvoiceConstants::STATUS_VERIFICACION_PAGO
                    && $previousStatus === InvoiceConstants::STATUS_AUTORIZACION_PAGO
                ) {
                    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
                    $authorizedPayments = $paymentsTable->find()
                        ->where([
                            'invoice_id' => $invoice->id,
                            'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                        ])
                        ->all();

                    foreach ($authorizedPayments as $payment) {
                        $payment->authorized = false;
                        $payment->status = InvoiceConstants::PAYMENT_RECORD_PENDING;
                        $payment->authorized_by = null;
                        $payment->authorized_date = null;
                        if (!$paymentsTable->save($payment)) {
                            return false;
                        }
                    }

                    if (!$this->paymentService->recalculatePaymentStatus((int)$invoice->id)) {
                        return false;
                    }
                }

                $observation = $observationsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'user_id' => $userId,
                    'type' => InvoiceConstants::OBSERVATION_TYPE_REGRESSION,
                    'message' => $reason,
                    'metadata' => [
                        'from_status' => $currentStatus,
                        'to_status' => $previousStatus,
                    ],
                ]);

                return (bool)$observationsTable->save($observation);
            },
        );

        if (!$ok) {
            return ServiceResult::fail(['No se pudo regresar la factura. Intente de nuevo.']);
        }

        return ServiceResult::ok(['previousStatus' => $previousStatus]);
    }
}
