<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Interface\NotificationServiceInterface;
use Cake\ORM\TableRegistry;

class InvoicePipelineService
{
    private HistoryServiceInterface $historyService;
    private NotificationServiceInterface $notificationService;
    private InvoicePaymentService $paymentService;
    private InvoiceFieldAccessPolicy $fieldPolicy;
    private StructuredLogger $logger;

    public function __construct(
        ?HistoryServiceInterface $historyService = null,
        ?NotificationServiceInterface $notificationService = null,
        ?InvoicePaymentService $paymentService = null,
        ?InvoiceFieldAccessPolicy $fieldPolicy = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->notificationService = $notificationService ?? new NotificationService();
        $this->paymentService = $paymentService ?? new InvoicePaymentService();
        $this->fieldPolicy = $fieldPolicy ?? new InvoiceFieldAccessPolicy();
        $this->logger = new StructuredLogger('InvoicePipeline');
    }

    // Pipeline statuses in order
    public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;

    public const STATUS_LABELS = [
        InvoiceConstants::STATUS_APROBACION        => 'Aprobación',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
        InvoiceConstants::STATUS_TESORERIA         => 'Tesorería',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
        InvoiceConstants::STATUS_PAGADA            => 'Pagada',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
    ];

    // Which statuses each role can see/work with
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
        RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::CONTADOR          => [InvoiceConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::ADMIN             => InvoiceConstants::PIPELINE_STATUSES,
    ];

    // All fields available for Admin in any status
    // Fields required before advancing from each status
    private const TRANSITION_REQUIREMENTS = [
        InvoiceConstants::STATUS_APROBACION => [
            [
                'field' => 'area_approval',
                'value' => InvoiceConstants::APPROVAL_APPROVED,
                'label' => 'Todos los aprobadores deben haber aprobado',
            ],
            [
                'field' => 'dian_validation',
                'value' => InvoiceConstants::DIAN_APPROVED,
                'label' => 'Validación DIAN debe ser "Aprobada"',
            ],
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            [
                'field' => 'accrued',
                'value' => true,
                'label' => 'La factura debe estar marcada como Causada',
            ],
            [
                'field' => 'accrual_date',
                'not_empty' => true,
                'label' => 'Fecha de Causación es requerida',
            ],
            [
                'field' => 'ready_for_payment',
                'not_empty' => true,
                'label' => 'Campo "Lista para Pago" es requerido',
            ],
        ],
        InvoiceConstants::STATUS_TESORERIA => [
            [
                'field' => '_has_pending_payment',
                'custom' => true,
                'label' => 'Debe registrar al menos un pago para avanzar a autorización',
            ],
        ],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
            [
                'field' => '_payment_authorized',
                'custom' => true,
                'label' => 'El pago pendiente debe ser autorizado por el Contador',
            ],
        ],
    ];

    /**
     * Maps each transition requirement field to the invoice fields that resolve it.
     * An empty array means the requirement is resolved by actions (not by editing fields).
     */
    private const REQUIREMENT_FIELDS = [
        'area_approval'         => [],
        'dian_validation'       => ['dian_validation'],
        'accrued'               => ['accrued', 'accrual_date'],
        'accrual_date'          => ['accrual_date'],
        'ready_for_payment'     => ['ready_for_payment'],
        '_has_pending_payment'  => [],
        '_payment_authorized'   => [],
    ];

    // Next status transitions
    public const TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION        => InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_CONTABILIDAD       => InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_TESORERIA          => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO  => InvoiceConstants::STATUS_PAGADA,
        InvoiceConstants::STATUS_PAGADA             => null,
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function getEditableFields(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getEditableFields($roleName, $status);
    }

    public function getVisibleSections(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getVisibleSections($roleName, $status);
    }

    public function getCollapsibleSections(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getCollapsibleSections($roleName, $status);
    }

    /**
     * Returns true if the invoice has been rejected in the revision step.
     */
    public function isRejected(object $invoice): bool
    {
        if ($invoice instanceof Invoice) {
            return $invoice->isRejected();
        }

        return ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    /**
     * Returns true if the invoice has any payment linked to a payment
     * scheduling already in "pagada" state. Used to lock the invoice from
     * further edits (except for Admin).
     */
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        return $paymentsTable->find()
            ->matching('PaymentSchedulings', function ($q) {
                return $q->where([
                    'PaymentSchedulings.pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA,
                ]);
            })
            ->where(['InvoicePayments.invoice_id' => $invoiceId])
            ->count() > 0;
    }

    /**
     * Validates whether all requirements are met to advance from $fromStatus.
     * Returns an array of error messages (empty = can advance).
     */
    public function validateTransitionRequirements(object $invoice, string $fromStatus): array
    {
        // Rejection blocks all advancement
        if ($this->isRejected($invoice)) {
            return ['La factura fue rechazada. El flujo ha terminado.'];
        }

        $errors = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            if (!empty($rule['custom'])) {
                if ($rule['field'] === '_has_pending_payment') {
                    if (!$this->paymentService->hasPendingAuthorization($invoice->id)) {
                        $errors[] = $rule['label'];
                    }
                } elseif ($rule['field'] === '_payment_authorized') {
                    if ($this->paymentService->hasPendingAuthorization($invoice->id)) {
                        $errors[] = $rule['label'];
                    }
                }
                continue;
            }

            $field = $rule['field'];
            $value = $invoice->$field ?? null;

            if (isset($rule['value'])) {
                $expected = $rule['value'];
                if (is_bool($expected)) {
                    $actual = (bool)$value;
                } else {
                    $actual = $value;
                }
                if ($actual !== $expected) {
                    $errors[] = $rule['label'];
                }
            } elseif (!empty($rule['not_empty'])) {
                if ($value === null || $value === '' || $value === false) {
                    $errors[] = $rule['label'];
                }
            }
        }

        return $errors;
    }

    /**
     * Returns the transition requirement rules for a status (raw definitions).
     *
     * @return array<int, array{field:string, label:string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        $rules = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            $rules[] = ['field' => $rule['field'], 'label' => $rule['label']];
        }

        return $rules;
    }

    /**
     * Filters advanceErrors so only those the given role can resolve remain.
     * Requirements driven by actions (empty REQUIREMENT_FIELDS entry) are kept
     * when the role has visibility over the current status.
     *
     * @param string[] $errors error messages aligned positionally with $rules
     * @param array<int, array{field:string, label:string}> $rules
     * @return string[]
     */
    public function filterAdvanceErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return array_values($errors);
        }

        $editable = $this->getEditableFields($roleName, $status);
        $visibleStatuses = $this->getVisibleStatuses($roleName);
        $statusVisible = in_array($status, $visibleStatuses, true);

        $filtered = [];
        foreach ($rules as $i => $rule) {
            if (!isset($errors[$i])) {
                continue;
            }
            $field = $rule['field'];
            $responsible = self::REQUIREMENT_FIELDS[$field] ?? [$field];

            if ($responsible === []) {
                if ($statusVisible) {
                    $filtered[] = $errors[$i];
                }
                continue;
            }

            if (array_intersect($responsible, $editable)) {
                $filtered[] = $errors[$i];
            }
        }

        return $filtered;
    }

    public function canAdvance(string $roleName, string $currentStatus): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::TRANSITIONS[$currentStatus] !== null;
        }

        $visibleStatuses = $this->getVisibleStatuses($roleName);
        if (!in_array($currentStatus, $visibleStatuses)) {
            return false;
        }

        return self::TRANSITIONS[$currentStatus] !== null;
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return self::TRANSITIONS[$currentStatus] ?? null;
    }

    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        return $this->fieldPolicy->filterEntityData($data, $roleName, $status);
    }

    public function getStatusIndex(string $status): int
    {
        $index = array_search($status, self::STATUSES);

        return $index !== false ? $index : 0;
    }

    /**
     * Save invoice fields, optionally advance the pipeline, record history, and send notifications.
     *
     * Returns an associative array:
     *   - 'saved'          => bool
     *   - 'advanced'       => bool
     *   - 'nextStatus'     => ?string
     *   - 'advanceErrors'  => string[]   (warnings when save succeeded but advance did not)
     *   - 'notificationErrors' => string[]  (notification failures, non-blocking)
     */
    public function saveAndAdvance(
        Invoice $invoice,
        array $data,
        string $roleName,
        int $userId,
        ?string $baseUrl = null,
    ): array {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $currentStatus = $invoice->pipeline_status;
        $filteredData = $this->filterEntityData($data, $roleName, $currentStatus);

        // Auto-set area_approval_date when area_approval changes to Aprobada or Rechazada
        if (array_key_exists('area_approval', $filteredData)) {
            $newApproval = $filteredData['area_approval'] ?? '';
            $oldApproval = $invoice->area_approval ?? '';
            if ($newApproval !== $oldApproval && in_array($newApproval, [InvoiceConstants::APPROVAL_APPROVED, InvoiceConstants::APPROVAL_REJECTED])) {
                $invoice->area_approval_date = date('Y-m-d');
            }
        }

        $canAdvance = $this->canAdvance($roleName, $currentStatus);
        $isRejected = $this->isRejected($invoice);

        // Determine if we can advance with submitted data
        $advanceNextStatus = null;
        $postAdvanceErrors = [];
        if ($canAdvance && !$isRejected) {
            $testEntity = $invoicesTable->patchEntity(clone $invoice, $filteredData);
            $postAdvanceErrors = $this->validateTransitionRequirements($testEntity, $currentStatus);
            if (empty($postAdvanceErrors)) {
                $advanceNextStatus = $this->getNextStatus($currentStatus);
            }
        }

        $original = clone $invoice;

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

                    // After advancing from autorizacion_pago: check payment_status
                    // If partial, regress to tesoreria for more payments
                    if ($currentStatus === InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
                        $this->paymentService->recalculatePaymentStatus($invoice->id);
                        $refreshed = $invoicesTable->get($invoice->id);

                        if ($refreshed->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
                            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
                            $advanceNextStatus = InvoiceConstants::STATUS_TESORERIA;
                            $invoicesTable->save($invoice);
                            $this->historyService->recordStatusChange(
                                $invoice->id,
                                InvoiceConstants::STATUS_PAGADA,
                                InvoiceConstants::STATUS_TESORERIA,
                                $userId,
                            );
                        }
                    }
                }

                return true;
            },
        );

        $notificationErrors = [];

        if ($saved) {
            // Send status change notification if pipeline advanced
            if ($advanceNextStatus) {
                $notifResult = $this->trySendNotification($invoice, $currentStatus, $advanceNextStatus);
                if (!$notifResult['success']) {
                    $notificationErrors[] = $notifResult['error'];
                }
            }
        }

        return [
            'saved' => (bool)$saved,
            'advanced' => (bool)$advanceNextStatus && (bool)$saved,
            'nextStatus' => $advanceNextStatus,
            'advanceErrors' => $postAdvanceErrors,
            'notificationErrors' => $notificationErrors,
        ];
    }

    /**
     * Standalone advance (without field edits). Used by the legacy advanceStatus route.
     *
     * Returns an associative array:
     *   - 'success' => bool
     *   - 'error'   => ?string
     *   - 'nextStatus' => ?string
     */
    public function advance(Invoice $invoice, string $roleName, int $userId): array
    {
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canAdvance($roleName, $currentStatus)) {
            return ['success' => false, 'error' => 'No tiene permisos para avanzar esta factura.', 'nextStatus' => null];
        }

        if ($this->isRejected($invoice)) {
            return ['success' => false, 'error' => 'La factura fue rechazada. El flujo ha terminado.', 'nextStatus' => null];
        }

        $errors = $this->validateTransitionRequirements($invoice, $currentStatus);
        if (!empty($errors)) {
            return ['success' => false, 'error' => implode(' ', $errors), 'nextStatus' => null];
        }

        $nextStatus = $this->getNextStatus($currentStatus);
        if (!$nextStatus) {
            return ['success' => false, 'error' => 'Esta factura ya está en el estado final.', 'nextStatus' => null];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice->pipeline_status = $nextStatus;

        if (!$invoicesTable->save($invoice)) {
            return ['success' => false, 'error' => 'No se pudo avanzar el estado.', 'nextStatus' => null];
        }

        $this->historyService->recordStatusChange($invoice->id, $currentStatus, $nextStatus, $userId);

        $notifResult = $this->trySendNotification($invoice, $currentStatus, $nextStatus);

        return [
            'success' => true,
            'error' => null,
            'nextStatus' => $nextStatus,
            'notificationError' => $notifResult['error'],
        ];
    }

    /**
     * Try to send status change notification.
     * Returns ['success' => bool, 'error' => ?string].
     */
    private function trySendNotification(Invoice $invoice, string $fromStatus, string $toStatus): array
    {
        $notifResult = $this->notificationService->sendStatusChangeNotification($invoice, $fromStatus, $toStatus);

        if (!empty($notifResult['failed'])) {
            $errorMsg = implode('; ', $notifResult['failed']);
            $this->logger->error('Error enviando notificación de cambio de estado', [
                'invoiceId' => $invoice->id,
                'error' => $errorMsg,
            ]);

            return ['success' => false, 'error' => 'No se pudo enviar la notificación de cambio de estado: ' . $errorMsg];
        }

        return ['success' => true, 'error' => null];
    }
}
