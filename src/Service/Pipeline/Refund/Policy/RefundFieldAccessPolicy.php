<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\Policy;

use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Service\Pipeline\FilterResult;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Audit PA-008 — extraído de `RefundsController::edit` (lógica inline
 * `if ($record->isAgrupacion()) {} if ($record->isContabilidad()) {}`). La
 * validación inline de `accrual_date` se conserva en el override de
 * `filterEntityData`.
 */
final class RefundFieldAccessPolicy extends PipelineFieldPolicy
{
    private const FIELDS_BY_STEP = [
        RefundConstants::STATUS_AGRUPACION => ['beneficiary_type', 'beneficiary_employee_id', 'beneficiary_provider_id'],
        RefundConstants::STATUS_CONTABILIDAD => ['accrued', 'accrual_date', 'ready_for_payment'],
    ];

    // Section keys: beneficiary / invoices / accounting / treasury — coinciden
    // con templates/Refunds/edit.php.
    private const SECTIONS_BY_STEP = [
        RefundConstants::STATUS_AGRUPACION => ['beneficiary', 'invoices'],
        RefundConstants::STATUS_CONTABILIDAD => ['beneficiary', 'invoices', 'accounting'],
        RefundConstants::STATUS_TESORERIA => ['beneficiary', 'invoices', 'accounting', 'treasury'],
        RefundConstants::STATUS_AUTORIZACION_PAGO => ['beneficiary', 'invoices', 'accounting', 'treasury'],
        RefundConstants::STATUS_VERIFICACION_PAGO => ['beneficiary', 'invoices', 'accounting', 'treasury'],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    protected static function fieldsByStep(): array
    {
        return self::FIELDS_BY_STEP;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected static function sectionsByStep(): array
    {
        return self::SECTIONS_BY_STEP;
    }

    /**
     * @return string
     */
    protected static function pipelineKey(): string
    {
        return PipelineStepConstants::PIPELINE_REFUNDS;
    }

    /**
     * Override: aplica las dos ramas (beneficiary en AGRUPACION, accounting en
     * CONTABILIDAD) que antes vivían inline en `RefundsController::edit`.
     * Preserva la validación de `accrual_date` requerida cuando `accrued=true`.
     *
     * No realiza chequeo de rol aquí — el controller original tampoco lo hacía
     * (solo verificaba estado). El `_ensureExpectedStatus` del controller sigue
     * siendo el gate de status; el gate de rol queda como deuda separada
     * (fuera del alcance de PA-008).
     *
     * @param array $data Raw POST data.
     * @param int $roleId Role ID (unused, conserved by base contract).
     * @param string $step Current pipeline status.
     * @return \App\Service\Pipeline\FilterResult
     */
    public function filterEntityData(array $data, int $roleId, string $step): FilterResult
    {
        unset($roleId);
        $patch = [];
        $errors = [];

        if ($step === RefundConstants::STATUS_AGRUPACION) {
            $beneficiaryType = $data['beneficiary_type'] ?? null;
            $patch['beneficiary_type'] = $beneficiaryType ?: null;
            $patch['beneficiary_employee_id'] = $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE
                && !empty($data['beneficiary_employee_id'])
                ? (int)$data['beneficiary_employee_id']
                : null;
            $patch['beneficiary_provider_id'] = $beneficiaryType === RefundConstants::BENEFICIARY_TYPE_PROVIDER
                && !empty($data['beneficiary_provider_id'])
                ? (int)$data['beneficiary_provider_id']
                : null;
        }

        if ($step === RefundConstants::STATUS_CONTABILIDAD) {
            $isAccrued = !empty($data['accrued']);
            $patch['accrued'] = $isAccrued;

            if ($isAccrued) {
                $submittedDate = !empty($data['accrual_date']) ? $data['accrual_date'] : null;
                if (empty($submittedDate)) {
                    $errors[] = 'La fecha de causación es requerida cuando el registro está marcado como causado.';
                }
                $patch['accrual_date'] = $submittedDate;
            } else {
                $patch['accrual_date'] = null;
            }

            $patch['ready_for_payment'] = $data['ready_for_payment'] ?? null;
        }

        return new FilterResult(patch: $patch, errors: $errors);
    }
}
