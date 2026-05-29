<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\Policy;

use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\FilterResult;
use App\Service\Pipeline\PipelineFieldPolicy;

/**
 * Audit PA-008 — extraído de `PettyCashPipelineService::_filterEditPatch` para alinear
 * caja menor con el patrón unificado. La validación inline de `accrual_date`
 * cuando `accrued=true` se conserva en el override de `filterEntityData`.
 */
final class PettyCashFieldAccessPolicy extends PipelineFieldPolicy
{
    private const FIELDS_BY_STEP = [
        PettyCashConstants::STATUS_AGRUPACION => ['notes'],
        PettyCashConstants::STATUS_CONTABILIDAD => ['notes', 'accrued', 'accrual_date', 'ready_for_payment'],
    ];

    // Section keys deben coincidir con templates/PettyCashRecords/edit.php:
    // notes / invoices / accounting / treasury. El ViewModel convierte este
    // array en los flags booleanos $showAccounting/$showTreasury que la template
    // consume.
    private const SECTIONS_BY_STEP = [
        PettyCashConstants::STATUS_AGRUPACION => ['notes', 'invoices'],
        PettyCashConstants::STATUS_CONTABILIDAD => ['notes', 'accounting'],
        PettyCashConstants::STATUS_TESORERIA => ['treasury'],
        PettyCashConstants::STATUS_AUTORIZACION_PAGO => ['treasury'],
        PettyCashConstants::STATUS_VERIFICACION_PAGO => ['treasury'],
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
        return PipelineStepConstants::PIPELINE_PETTY_CASH;
    }

    /**
     * Override: filtra los campos del header con gate de rol (A1). Si el rol no
     * puede operar el paso (`canOperate` vía la base `getEditableFields`), el
     * patch sale vacío y no se persiste ni valida nada — igual que Invoice/Novelty.
     * Para los roles que SÍ operan el paso, conserva las coerciones de tipo
     * (`accrued`=>bool, `accrual_date`=>null) y la validación inline de
     * `accrual_date` que `array_intersect_key` de la base destruiría. El avance
     * sigue gateado aparte por `denialReasonForAdvance`.
     *
     * @param array $data Raw POST data.
     * @param int $roleId Role ID (gatea la escritura vía canOperate del paso).
     * @param string $step Current pipeline status.
     * @return \App\Service\Pipeline\FilterResult
     */
    public function filterEntityData(array $data, int $roleId, string $step): FilterResult
    {
        if ($this->getEditableFields($roleId, $step) === []) {
            return new FilterResult(patch: [], errors: []);
        }
        $patch = [];
        $errors = [];

        if (
            ($step === PettyCashConstants::STATUS_AGRUPACION || $step === PettyCashConstants::STATUS_CONTABILIDAD)
            && array_key_exists('notes', $data)
        ) {
            $patch['notes'] = $data['notes'];
        }

        if ($step === PettyCashConstants::STATUS_CONTABILIDAD) {
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
