<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Event\InvoicePaidEvent;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use App\Service\Pipeline\InvoicePipelineStateRegistry;
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
        private readonly PipelineAuthorizationService $pipelineAuth,
    ) {
    }

    public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;

    public function getVisibleStatuses(string $roleName): array
    {
        $result = [];
        foreach ($this->states->all() as $name => $state) {
            if (in_array($roleName, $state->getRoleVisibility(), true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    public function getVisibleAdvanceStatuses(string $roleName): array
    {
        $result = [];
        foreach ($this->states->all() as $name => $state) {
            if ($name === InvoiceConstants::STATUS_PAGADA || $name === InvoiceConstants::STATUS_LEGALIZADA) {
                // ADVANCE_ACTIVE_STATUSES excluye terminales para "Mis Anticipos"
                continue;
            }
            if (in_array($roleName, $state->getAdvanceRoleVisibility(), true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    public function getPipelineStatusesFor(?string $documentType = null): array
    {
        return $this->docTypePolicies->for($documentType)->getPipelineStatusesForView();
    }

    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        return $this->fieldPolicy->getEditableFields($roleId, $roleName, $status);
    }

    public function getVisibleSections(int $roleId, string $roleName, string $status, ?string $documentType = null): array
    {
        $sections = $this->fieldPolicy->getVisibleSections($roleId, $roleName, $status);

        return $this->docTypePolicies->for($documentType)->filterVisibleSections($sections);
    }

    public function getCollapsibleSections(int $roleId, string $roleName, string $status): array
    {
        return $this->fieldPolicy->getCollapsibleSections($roleId, $roleName, $status);
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

    public function filterAdvanceErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
    {
        return $this->transitionValidator->filterErrorsForRole($errors, $rules, $roleId, $roleName, $status);
    }

    public function canAdvance(int $roleId, string $roleName, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
    }

    public function getNextStatus(string $currentStatus, ?string $documentType = null): ?string
    {
        $state = $this->states->get($currentStatus);
        $policy = $this->docTypePolicies->for($documentType);

        // Cuando la policy bloquea el avance del estado, el next efectivo es null.
        // Pasamos un stdClass con document_type para mantener compat con la firma de blocksAdvance.
        $stub = (object)['document_type' => $documentType, 'pipeline_status' => $currentStatus];
        if ($policy->blocksAdvance($state, $stub) !== null) {
            return null;
        }

        return $state->getNext();
    }

    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
    {
        return $this->fieldPolicy->filterEntityData($data, $roleId, $roleName, $status);
    }

    public function getStatusIndex(string $status): int
    {
        $index = array_search($status, self::STATUSES);

        return $index !== false ? $index : 0;
    }

    public function getPreviousStatus(string $currentStatus): ?string
    {
        return $this->states->get($currentStatus)->getPrevious();
    }

    public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        );
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
        string $roleName,
        int $userId,
        ?string $baseUrl = null,
    ): ServiceResult {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $currentStatus = $invoice->pipeline_status;
        $filteredData = $this->filterEntityData($data, $roleId, $roleName, $currentStatus);

        $original = clone $invoice;

        if (array_key_exists('area_approval', $filteredData)) {
            $newApproval = $filteredData['area_approval'] ?? '';
            if ($newApproval !== ($invoice->area_approval ?? '')) {
                $invoice->setApprovalResult($newApproval);
                unset($filteredData['area_approval']);
            }
        }

        $canAdvance = $this->canAdvance($roleId, $roleName, $currentStatus, $invoice->document_type ?? null);
        $isRejected = $this->isRejected($invoice);

        $advanceNextStatus = null;
        $postAdvanceErrors = [];
        if ($canAdvance && !$isRejected) {
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
     * Standalone advance (without field edits). Used by the legacy advanceStatus route.
     *
     * @return \App\Service\ServiceResult on success: data = ['nextStatus' => string]
     */
    public function advance(Invoice $invoice, int $roleId, string $roleName, int $userId): ServiceResult
    {
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canAdvance($roleId, $roleName, $currentStatus, $invoice->document_type ?? null)) {
            return ServiceResult::fail(['No tiene permisos para avanzar esta factura.']);
        }

        if ($this->isRejected($invoice)) {
            return ServiceResult::fail(['La factura fue rechazada. El flujo ha terminado.']);
        }

        $errors = $this->validateTransitionRequirements($invoice, $currentStatus);
        if (!empty($errors)) {
            return ServiceResult::fail($errors);
        }

        $nextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
        if (!$nextStatus) {
            return ServiceResult::fail(['Esta factura ya está en el estado final.']);
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice->pipeline_status = $nextStatus;

        if (!$invoicesTable->save($invoice)) {
            return ServiceResult::fail(['No se pudo avanzar el estado.']);
        }

        $this->historyService->recordStatusChange($invoice->id, $currentStatus, $nextStatus, $userId);

        return ServiceResult::ok(['nextStatus' => $nextStatus]);
    }

    /**
     * Regress the invoice to its previous pipeline status (cold regression).
     *
     * @return \App\Service\ServiceResult on success: data = ['previousStatus' => string]
     */
    public function regress(
        Invoice $invoice,
        int $roleId,
        string $roleName,
        int $userId,
        string $reason,
    ): ServiceResult {
        $reason = trim($reason);
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta factura ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta factura.';

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
