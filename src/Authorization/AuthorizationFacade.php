<?php
declare(strict_types=1);

namespace App\Authorization;

use App\ValueObject\UserContext;

/**
 * Fachada unificada para chequeos de permisos.
 *
 * Implementación canónica: `DefaultAuthorizationFacade`. Las matrices
 * (`getPermissionsForRoleAsMatrix`, `getPermissionsMatrix`) y `save*` quedan
 * fuera del contrato — solo `RolesController` y
 * `AppController::_setUserPermissions` dependen de los services internos
 * (ver audit PA-004).
 */
interface AuthorizationFacade
{
    public function canCrud(UserContext $u, string $module, CrudAction $a): bool;

    public function canOperate(UserContext $u, string $pipeline, string $step): bool;

    /**
     * @return array<string> Steps del pipeline donde el rol puede operar.
     */
    public function operableSteps(UserContext $u, string $pipeline): array;

    public function invalidate(int $roleId): void;
}
