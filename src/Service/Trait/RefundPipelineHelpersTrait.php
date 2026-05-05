<?php
declare(strict_types=1);

namespace App\Service\Trait;

use App\Constants\PipelineStepConstants;
use App\Service\PipelineAuthorizationService;

/**
 * Helpers compartidos entre `RefundService` (pipeline) y `RefundPaymentService`
 * (pagos). Centraliza el check de RBAC sobre el pipeline de refunds y la
 * construcción de mensajes de error a partir de `Entity::getErrors()`.
 *
 * El consumidor debe declarar la propiedad `$pipelineAuth`.
 */
trait RefundPipelineHelpersTrait
{
    private PipelineAuthorizationService $pipelineAuth;

    /**
     * Devuelve true si el rol puede operar el step indicado del pipeline de
     * refunds. `PipelineAuthorizationService::canOperate` mantiene un parámetro
     * `$roleName` por compat con otros callers, pero no lo consulta.
     */
    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->pipelineAuth->canOperate(
            $roleId,
            '',
            PipelineStepConstants::PIPELINE_REFUNDS,
            $step,
        );
    }

    /**
     * Construye un mensaje de error legible incluyendo los errores de validación
     * de la entidad cuando existen, para no perder el contexto en producción.
     *
     * @param string $base Mensaje base.
     * @param array $entityErrors Errores devueltos por `Entity::getErrors()`.
     */
    private static function _buildSaveErrorMessage(string $base, array $entityErrors): string
    {
        $details = [];
        foreach ($entityErrors as $field => $fieldErrors) {
            foreach ((array)$fieldErrors as $msg) {
                if (is_string($msg) && $msg !== '') {
                    $details[] = sprintf('%s: %s', $field, $msg);
                }
            }
        }

        return empty($details) ? $base : ($base . ' ' . implode(', ', $details));
    }

    /**
     * Fecha de hoy en formato ISO. Centralizar el origen del "hoy" simplifica
     * cualquier validación manual reproducible en el futuro.
     */
    private static function _today(): string
    {
        return date('Y-m-d');
    }
}
