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

    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
        );
    }

    public function getPipelineStatusesFor(?string $documentType = null): array
    {
        return $this->docTypePolicies->for($documentType)->getPipelineStatusesForView();
    }

    public function getEditableFields(int $roleId, string $status): array
    {
        return $this->fieldPolicy->getEditableFields($roleId, $status);
    }

    public function getVisibleSections(int $roleId, string $status, ?string $documentType = null): array
    {
        $sections = $this->fieldPolicy->getVisibleSections($roleId, $status);

        return $this->docTypePolicies->for($documentType)->filterVisibleSections($sections);
    }

    public function isRejected(object $invoice): bool
    {
        if ($invoice instanceof Invoice) {
            return $invoice->isRejected();
        }

        return ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        return $this->lockPolicy->isLockedByPaidScheduling($invoiceId);
    }

    public function isLockedByPettyCash(object $invoice): bool
    {
        return $this->lockPolicy->isLockedByPettyCash($invoice);
    }

    public function getEditLockMessage(object $invoice): ?string
    {
        return $this->lockPolicy->getEditLockMessage($invoice);
    }

    public function getRegressionLockMessage(object $invoice): ?string
    {
        $lockMsg = $this->lockPolicy->getRegressionLockMessage($invoice);
        if ($lockMsg !== null) {
            return $lockMsg;
        }

        return $this->docTypePolicies->for($invoice->document_type ?? null)->getRegressionLockReason($invoice);
    }

    public function validateTransitionRequirements(object $invoice, string $fromStatus, array $overrides = []): array
    {
        return $this->transitionValidator->validateAdvance($invoice, $fromStatus, $overrides);
    }

    public function getTransitionRules(string $fromStatus): array
    {
        return $this->transitionValidator->getTransitionRules($fromStatus);
    }

    public function filterAdvanceErrorsForRole(array $errors, array $rules, int $roleId, string $status): array
    {
        return $this->transitionValidator->filterErrorsForRole($errors, $rules, $roleId, $status);
    }

    /**
     * Retorna el motivo por el que la factura no puede avanzar, o null si puede.
     */
    public function denialReasonForAdvance(Invoice $invoice, int $roleId): ?DenialReason
    {
        if ($this->getNextStatus($invoice->pipeline_status, $invoice->document_type) === null) {
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

    public function getNextStatus(string $currentStatus, ?string $documentType = null): ?string
    {
        $currentEnum = PipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        $state = $this->states->get($currentEnum);
        $policy = $this->docTypePolicies->for($documentType);

        // Cuando la policy bloquea el avance del estado, el next efectivo es null.
        // Pasamos un stdClass con document_type para mantener compat con la firma de blocksAdvance.
        $stub = (object)['document_type' => $documentType, 'pipeline_status' => $currentStatus];
        if ($policy->blocksAdvance($state, $stub) !== null) {
            return null;
        }

        return $state->getNextStatus()?->value;
    }

    public function filterEntityData(array $data, int $roleId, string $status): array
    {
        return $this->fieldPolicy->filterEntityData($data, $roleId, $status)->patch;
    }

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
     * @return \App\Service\ServiceResult on success: data = ['advanced' => bool, 'nextStatus' => ?string, 'advanceErrors' => string[]]
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
                $advanceNextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
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
