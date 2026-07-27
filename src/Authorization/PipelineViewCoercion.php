<?php
declare(strict_types=1);

namespace App\Authorization;

use App\Constants\PipelineStepConstants;

/**
 * Garantiza el invariante "operar implica ver": si un rol recibe ≥1 paso
 * operable de un pipeline en el POST de Roles, se fuerza `can_view` del módulo
 * CRUD mapeado (PipelineStepConstants::MODULE_BY_PIPELINE), de modo que su
 * bandeja no quede invisible en el sidebar.
 *
 * Transformación pura sobre el array del formulario; no toca BD. Se aplica en
 * RolesController::add/edit antes de persistir las dos matrices de permisos.
 */
final class PipelineViewCoercion
{
    /**
     * @param array $data Datos del POST (claves 'permissions' y 'pipeline_permissions').
     * @return array Datos con `can_view` forzado en los módulos cuyos pipelines tienen pasos marcados.
     */
    public static function apply(array $data): array
    {
        $pipelinePerms = $data['pipeline_permissions'] ?? [];

        foreach (PipelineStepConstants::MODULE_BY_PIPELINE as $pipeline => $module) {
            if (!self::hasAnyStep($pipelinePerms[$pipeline] ?? [])) {
                continue;
            }
            // Solo añade can_view; preserva can_create/can_edit/can_delete si ya venían.
            $data['permissions'][$module]['can_view'] = '1';
        }

        return $data;
    }

    /**
     * @param mixed $steps Submatriz de pasos del pipeline (step => valor de checkbox).
     * @return bool true si al menos un paso viene marcado.
     */
    private static function hasAnyStep(mixed $steps): bool
    {
        if (!is_array($steps)) {
            return false;
        }
        foreach ($steps as $value) {
            if (!empty($value)) {
                return true;
            }
        }

        return false;
    }
}
