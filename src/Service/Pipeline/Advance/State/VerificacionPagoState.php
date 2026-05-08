<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

/**
 * Subfase de "Verificación de pago" en legalizaciones de Anticipos (caso sobrante).
 * Tras la autorización del Contador del reintegro, la legalización queda aquí
 * esperando que Tesorería confirme que el dinero efectivamente salió del banco
 * antes de cerrarla como Legalizada.
 */
final class VerificacionPagoState implements AdvanceLegalizationPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::LEGALIZADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        // El cierre lo dispara AdvanceLegalizationService::confirmRefundExecuted
        // (Tesorería con permiso sobre el step `verificacion_pago`).
        return [];
    }
}
