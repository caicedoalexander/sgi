<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;

/**
 * Calcula qué campos puede editar un usuario en una factura y qué secciones
 * del formulario debe ver, dado su rol y el estado actual del pipeline.
 *
 * El mapeo `step → campos editables` y `step → sección visible` es lógica de
 * dominio (vive en código). La autorización (¿este rol puede operar este
 * paso?) se delega a `PipelineAuthorizationService`, que consulta
 * `pipeline_permissions`.
 */
class InvoiceFieldAccessPolicy
{
    /**
     * Campos editables por paso del pipeline (sin acoplamiento a rol).
     */
    private const FIELDS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => [
            'invoice_number', 'issue_date', 'due_date',
            'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
            'detail', 'amount', 'expense_type_id', 'cost_center_id',
            'confirmed_by',
            'dian_validation',
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            'accrued', 'accrual_date', 'ready_for_payment',
        ],
        InvoiceConstants::STATUS_TESORERIA => [],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
    ];

    /**
     * Sección del formulario asociada a cada paso.
     */
    private const SECTION_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => 'revision',
        InvoiceConstants::STATUS_CONTABILIDAD => 'accounting',
        InvoiceConstants::STATUS_TESORERIA => 'treasury',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'payment_authorization',
        // Verificación de pago reusa la sección de autorización (read-only),
        // donde aparece el botón "Pasar a Pagada".
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'payment_authorization',
    ];

    private PipelineAuthorizationService $pipelineAuth;

    /**
     * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth
     */
    public function __construct(?PipelineAuthorizationService $pipelineAuth = null)
    {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }

    /**
     * @param int $roleId
     * @param string $roleName
     * @param string $status
     * @return array
     */
    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if (PipelineStatus::tryFrom($status) === null) {
            return [];
        }

        $allowedSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        if (!in_array($status, $allowedSteps, true)) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }

    /**
     * @param int $roleId
     * @param string $roleName
     * @param string $status
     * @return array
     */
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        $sections = ['ledger'];

        if (PipelineStatus::tryFrom($status) === null) {
            return $sections;
        }

        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        foreach ($operableSteps as $step) {
            if (isset(self::SECTION_BY_STEP[$step])) {
                $sections[] = self::SECTION_BY_STEP[$step];
            }
        }

        return array_values(array_unique($sections));
    }

    /**
     * @param int $roleId
     * @param string $roleName
     * @param string $status
     * @return array
     */
    public function getCollapsibleSections(int $roleId, string $roleName, string $status): array
    {
        // La política previa no definía secciones colapsables por rol/estado;
        // se mantiene el contrato vacío.
        return [];
    }

    /**
     * @param array $data
     * @param int $roleId
     * @param string $roleName
     * @param string $status
     * @return array
     */
    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
    {
        $allowed = $this->getEditableFields($roleId, $roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * @param string $status
     * @return array
     */
}
