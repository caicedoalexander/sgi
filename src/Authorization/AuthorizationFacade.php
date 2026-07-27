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
    /**
     * @param \App\ValueObject\UserContext $u Contexto del usuario actual.
     * @param string $module Slug del módulo CRUD.
     * @param \App\Authorization\CrudAction $a Acción CRUD solicitada.
     * @return bool true si el rol puede ejecutar la acción sobre el módulo.
     */
    public function canCrud(UserContext $u, string $module, CrudAction $a): bool;

    /**
     * @param \App\ValueObject\UserContext $u Contexto del usuario actual.
     * @param string $pipeline Slug del pipeline.
     * @param string $step Paso del pipeline.
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(UserContext $u, string $pipeline, string $step): bool;

    /**
     * @param \App\ValueObject\UserContext $u Contexto del usuario actual.
     * @param string $pipeline Slug del pipeline.
     * @return array<string> Steps del pipeline donde el rol puede operar.
     */
    public function operableSteps(UserContext $u, string $pipeline): array;

    /**
     * @param int $roleId ID del rol cuya caché de permisos se invalida.
     * @return void
     */
    public function invalidate(int $roleId): void;
}
