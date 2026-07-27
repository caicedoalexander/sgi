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
    /**
     * Estado canónico tipado de este State.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus
     */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    /**
     * Estado siguiente natural en el avance lineal; null si es terminal o bifurcante.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    /**
     * Estado anterior; null si es el primero o si la regresión está bloqueada.
     *
     * @return \App\Constants\Domain\Advance\PipelineStatus|null
     */
    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    /**
     * El cierre del reintegro se gestiona desde la sección de pago (Tesorería
     * confirma la ejecución vía confirmRefundExecuted); devuelve un mensaje
     * explicativo para bloquear cualquier intento de avance genérico.
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización a validar.
     * @return array<string>
     */
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
