<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Invoice;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Exception;

class InvoicePipelineService
{
    private InvoiceHistoryService $historyService;
    private NotificationService $notificationService;

    public function __construct(
        ?InvoiceHistoryService $historyService = null,
        ?NotificationService $notificationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    // Pipeline statuses in order
    public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;

    public const STATUS_LABELS = [
        InvoiceConstants::STATUS_APROBACION    => 'Aprobación',
        InvoiceConstants::STATUS_CONTABILIDAD  => 'Contabilidad',
        InvoiceConstants::STATUS_TESORERIA     => 'Tesorería',
        InvoiceConstants::STATUS_PAGADA        => 'Pagada',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION    => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD  => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA     => 'bi-bank',
        InvoiceConstants::STATUS_PAGADA        => 'bi-cash-coin',
    ];

    // Which statuses each role can see/work with
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
        RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA],
        RoleConstants::ADMIN             => InvoiceConstants::PIPELINE_STATUSES,
    ];

    // All fields available for Admin in any status
    private const ALL_FIELDS = [
        'invoice_number', 'issue_date', 'due_date',
        'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
        'detail', 'amount', 'expense_type_id', 'cost_center_id',
        'confirmed_by', 'approver_id', 'area_approval',
        'dian_validation', 'accrued', 'ready_for_payment',
        'payment_status', 'payment_date', 'pipeline_status',
    ];

    // Fields editable by role in each status
    private const EDITABLE_FIELDS = [
        RoleConstants::REGISTRO_REVISION => [
            InvoiceConstants::STATUS_APROBACION => [
                'invoice_number', 'issue_date', 'due_date',
                'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
                'detail', 'amount', 'expense_type_id', 'cost_center_id',
                'confirmed_by',
                'dian_validation',
            ],
        ],
        RoleConstants::CONTABILIDAD => [
            InvoiceConstants::STATUS_CONTABILIDAD => [
                'accrued', 'ready_for_payment',
            ],
        ],
        RoleConstants::TESORERIA => [
            InvoiceConstants::STATUS_TESORERIA => [
                'payment_status', 'payment_date',
            ],
        ],
    ];

    // Sections visible per role (non-Admin roles have fixed sections)
    private const VISIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::REGISTRO_REVISION => ['general', 'dates', 'classification', 'revision'],
        RoleConstants::CONTABILIDAD      => ['general', 'dates', 'classification', 'accounting'],
        RoleConstants::TESORERIA         => ['general', 'treasury'],
    ];

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
                'field' => 'payment_status',
                'value' => InvoiceConstants::PAYMENT_FULL,
                'label' => 'Estado de Pago debe ser "Pago total" para marcar como Pagada',
            ],
            [
                'field' => 'payment_date',
                'not_empty' => true,
                'label' => 'Fecha de Pago es requerida',
            ],
        ],
    ];

    // Next status transitions
    public const TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION    => InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_CONTABILIDAD  => InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_TESORERIA     => InvoiceConstants::STATUS_PAGADA,
        InvoiceConstants::STATUS_PAGADA        => null,
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function getEditableFields(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        return self::EDITABLE_FIELDS[$roleName][$status] ?? [];
    }

    /**
     * Returns sections visible in the edit form for the given role and current status.
     * For non-Admin roles: fixed sections regardless of status.
     * For Admin: sections depend on how far the invoice has progressed.
     */
    public function getVisibleSections(string $roleName, string $status): array
    {
        if ($roleName !== RoleConstants::ADMIN) {
            return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? ['general'];
        }

        // Admin: show sections up to the current state
        // STATUSES: aprobacion(0), contabilidad(1), tesoreria(2), pagada(3)
        $statusIndex = $this->getStatusIndex($status);
        $sections = ['general', 'dates', 'classification', 'revision'];
        if ($statusIndex >= 1) {
            $sections[] = 'accounting';
        }
        if ($statusIndex >= 2) {
            $sections[] = 'treasury';
        }

        return $sections;
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
        if ($roleName === RoleConstants::ADMIN) {
            return $data;
        }

        $allowed = $this->getEditableFields($roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
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

        // Auto-set accrual_date when marking as accrued, clear it when unchecking
        if (array_key_exists('accrued', $filteredData)) {
            if (!empty($filteredData['accrued']) && empty($invoice->accrual_date)) {
                $filteredData['accrual_date'] = date('Y-m-d');
            } elseif (empty($filteredData['accrued'])) {
                $filteredData['accrual_date'] = null;
            }
        }

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
            Log::error('Error enviando notificación de cambio de estado para factura #' . $invoice->id . ': ' . $errorMsg);

            return ['success' => false, 'error' => 'No se pudo enviar la notificación de cambio de estado: ' . $errorMsg];
        }

        return ['success' => true, 'error' => null];
    }
}
