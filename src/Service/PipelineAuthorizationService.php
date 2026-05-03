<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PipelineStepConstants;
use Cake\ORM\TableRegistry;

/**
 * Resuelve si un rol puede operar (avanzar, regresar, editar campos, ver
 * sección) en un paso específico de un pipeline.
 *
 * Espejo de patrón a `AuthorizationService` para módulos CRUD: misma
 * estructura de cache por request, mismo bypass para Admin.
 */
class PipelineAuthorizationService
{
    /**
     * @var array<int, array<string, array<string, bool>>> cache[role_id][pipeline][step] = bool
     */
    private array $cache = [];

    /**
     * @param int $roleId
     * @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02.
     * @param string $pipeline
     * @param string $step
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
    {
        $perms = $this->_loadForRole($roleId);

        return (bool)($perms[$pipeline][$step] ?? false);
    }

    /**
     * @param int $roleId
     * @param string $roleName Conservado para compat con callers; no se consulta tras cleanup 2026-05-02.
     * @param string $pipeline
     * @return array<string> Pasos del pipeline donde el rol puede operar.
     */
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
    {
        $perms = $this->_loadForRole($roleId);
        $stepsForPipeline = $perms[$pipeline] ?? [];

        return array_values(array_filter(
            PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [],
            static fn(string $step): bool => !empty($stepsForPipeline[$step]),
        ));
    }

    /**
     * Devuelve la matriz completa para alimentar la UI de Roles/edit.
     *
     * @param int $roleId
     * @return array<string, array<string, bool>> matrix[pipeline][step] = bool
     */
    public function getPermissionsMatrix(int $roleId): array
    {
        $perms = $this->_loadForRole($roleId);
        $matrix = [];

        foreach (PipelineStepConstants::STEPS_BY_PIPELINE as $pipeline => $steps) {
            $matrix[$pipeline] = [];
            foreach ($steps as $step) {
                $matrix[$pipeline][$step] = (bool)($perms[$pipeline][$step] ?? false);
            }
        }

        return $matrix;
    }

    /**
     * Persiste la matriz para un rol. Ignora pares (pipeline, step) inválidos.
     *
     * Estrategia: para cada par válido, upsert por (role_id, pipeline, step).
     * No borra filas existentes — solo actualiza el flag `can_operate` (false
     * cuando el checkbox no viene en el POST).
     *
     * @param int $roleId
     * @param array $data
     * @return void
     */
    public function savePermissions(int $roleId, array $data): void
    {
        $table = TableRegistry::getTableLocator()->get('PipelinePermissions');

        foreach (PipelineStepConstants::STEPS_BY_PIPELINE as $pipeline => $steps) {
            $pipelineData = $data[$pipeline] ?? [];

            foreach ($steps as $step) {
                if (!PipelineStepConstants::isValid($pipeline, $step)) {
                    continue;
                }

                $existing = $table->find()
                    ->where([
                        'role_id' => $roleId,
                        'pipeline' => $pipeline,
                        'step' => $step,
                    ])
                    ->first();

                $payload = [
                    'role_id' => $roleId,
                    'pipeline' => $pipeline,
                    'step' => $step,
                    'can_operate' => !empty($pipelineData[$step]),
                ];

                if ($existing) {
                    $existing = $table->patchEntity($existing, $payload);
                    $table->save($existing);
                } else {
                    $entity = $table->newEntity($payload);
                    $table->save($entity);
                }
            }
        }

        unset($this->cache[$roleId]);
    }

    /**
     * @param int $roleId
     * @return array<string, array<string, bool>> perms[pipeline][step] = bool
     */
    private function _loadForRole(int $roleId): array
    {
        if (isset($this->cache[$roleId])) {
            return $this->cache[$roleId];
        }

        $rows = TableRegistry::getTableLocator()
            ->get('PipelinePermissions')
            ->find()
            ->where(['role_id' => $roleId])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->pipeline][$row->step] = (bool)$row->can_operate;
        }

        $this->cache[$roleId] = $result;

        return $result;
    }
}
