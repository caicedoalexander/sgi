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
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        // El cierre lo dispara AdvanceLegalizationService::confirmRefundExecuted
        // (Tesorería con permiso sobre el step `verificacion_pago`). Igual que
        // los otros 5 módulos del feature, retornamos mensaje explicativo en
        // vez de array vacío para que cualquier intento de avance genérico
        // muestre feedback consistente.
        return ['La confirmación del reintegro se gestiona desde la sección de pago.'];
    }
}
