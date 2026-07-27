<?php
declare(strict_types=1);

namespace App\Command;

use App\Constants\PipelineStepConstants;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;

/**
 * Audita la coherencia entre las dos tablas de permisos:
 *  - `pipeline_permissions` (qué pasos de pipeline puede operar un rol), y
 *  - `permissions` (CRUD `can_view`, que gobierna si el link/bandeja aparece).
 *
 * Detecta el desajuste de "trabajo invisible": un rol que puede operar pasos de
 * un pipeline pero NO tiene `can_view` del módulo mapeado, por lo que el link del
 * sidebar no se renderiza y nunca alcanza su bandeja. Reporta también el caso
 * inverso (informativo): `can_view` sin ningún paso operable → bandeja vacía.
 *
 * Read-only. No modifica datos. Exit code 1 si encuentra inconsistencias
 * bloqueantes (apto para CI / pre-deploy).
 */
class PermissionsAuditCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser base.
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(
            'Audita coherencia entre pipeline_permissions y permissions.can_view ' .
            '(detecta bandejas invisibles por permisos desalineados).',
        );

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args Argumentos de consola.
     * @param \Cake\Console\ConsoleIo $io Console IO.
     * @return int Exit code (0 = sin inconsistencias bloqueantes, 1 = encontradas).
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $roles = TableRegistry::getTableLocator()->get('Roles')->find()
            ->orderBy(['name' => 'ASC'])
            ->all();

        $operableByRole = $this->_operableStepCountByRole();
        $viewByRole = $this->_canViewModulesByRole();

        $blocking = [];
        $informational = [];

        foreach ($roles as $role) {
            $roleId = (int)$role->id;
            $operablePipelines = $operableByRole[$roleId] ?? [];
            $viewModules = $viewByRole[$roleId] ?? [];

            foreach (PipelineStepConstants::MODULE_BY_PIPELINE as $pipeline => $module) {
                $hasSteps = ($operablePipelines[$pipeline] ?? 0) > 0;
                $canView = in_array($module, $viewModules, true);

                if ($hasSteps && !$canView) {
                    $blocking[] = [
                        (string)$role->name,
                        PipelineStepConstants::PIPELINE_LABELS[$pipeline] ?? $pipeline,
                        (string)($operablePipelines[$pipeline] ?? 0),
                        $module,
                    ];
                } elseif (!$hasSteps && $canView) {
                    $informational[] = [
                        (string)$role->name,
                        $module,
                        PipelineStepConstants::PIPELINE_LABELS[$pipeline] ?? $pipeline,
                    ];
                }
            }
        }

        return $this->_report($io, $blocking, $informational);
    }

    /**
     * @return array<int, array<string, int>> [role_id][pipeline] = nº de pasos operables.
     */
    private function _operableStepCountByRole(): array
    {
        $rows = TableRegistry::getTableLocator()->get('PipelinePermissions')->find()
            ->where(['can_operate' => true])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->role_id][$row->pipeline] =
                ($result[(int)$row->role_id][$row->pipeline] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * @return array<int, list<string>> [role_id] = lista de módulos con can_view.
     */
    private function _canViewModulesByRole(): array
    {
        $rows = TableRegistry::getTableLocator()->get('Permissions')->find()
            ->where(['can_view' => true])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->role_id][] = (string)$row->module;
        }

        return $result;
    }

    /**
     * @param \Cake\Console\ConsoleIo $io Console IO.
     * @param array<int, array<int, string>> $blocking Inconsistencias bloqueantes.
     * @param array<int, array<int, string>> $informational Avisos informativos.
     * @return int Exit code.
     */
    private function _report(ConsoleIo $io, array $blocking, array $informational): int
    {
        $io->out();
        $io->info('Auditoría de permisos: pipeline_permissions ↔ permissions.can_view');
        $io->hr();

        if ($blocking !== []) {
            $io->err(sprintf(
                '<error>%d bandeja(s) invisible(s): el rol opera el pipeline pero NO ve el módulo.</error>',
                count($blocking),
            ));
            $io->helper('Table')->output(array_merge(
                [['Rol', 'Pipeline operable', 'Pasos', 'Módulo sin can_view']],
                $blocking,
            ));
        } else {
            $io->success('Sin bandejas invisibles: todo pipeline operable tiene su can_view.');
        }

        if ($informational !== []) {
            $io->out();
            $io->warning(sprintf(
                '%d módulo(s) visible(s) sin pasos operables (bandeja vacía, no bloqueante):',
                count($informational),
            ));
            $io->helper('Table')->output(array_merge(
                [['Rol', 'Módulo con can_view', 'Pipeline sin pasos']],
                $informational,
            ));
        }

        $io->out();

        return $blocking === [] ? self::CODE_SUCCESS : self::CODE_ERROR;
    }
}
