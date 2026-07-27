<?php
declare(strict_types=1);

namespace App\Authorization;

use App\Service\AuthorizationService;
use App\Service\PipelineAuthorizationService;
use App\ValueObject\UserContext;

/**
 * Implementación canónica del `AuthorizationFacade`. Compone los services
 * existentes (`AuthorizationService` para CRUD, `PipelineAuthorizationService`
 * para steps de pipeline) sin replicar su lógica interna.
 */
final class DefaultAuthorizationFacade implements AuthorizationFacade
{
    /**
     * @param \App\Service\AuthorizationService $crud Service de permisos CRUD.
     * @param \App\Service\PipelineAuthorizationService $pipeline Service de permisos de pipeline.
     */
    public function __construct(
        private readonly AuthorizationService $crud,
        private readonly PipelineAuthorizationService $pipeline,
    ) {
    }

    /**
     * @param \App\ValueObject\UserContext $u Contexto del usuario actual.
     * @param string $module Slug del módulo CRUD.
     * @param \App\Authorization\CrudAction $a Acción CRUD solicitada.
     * @return bool true si el rol puede ejecutar la acción sobre el módulo.
     */
    public function canCrud(UserContext $u, string $module, CrudAction $a): bool
    {
        return $this->crud->isAllowed($u->roleId, $u->roleName, $module, $a->value);
    }

    /**
     * @param \App\ValueObject\UserContext $u Contexto del usuario actual.
     * @param string $pipeline Slug del pipeline.
     * @param string $step Paso del pipeline.
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(UserContext $u, string $pipeline, string $step): bool
    {
        return $this->pipeline->canOperate($u->roleId, $pipeline, $step);
    }

    /**
     * @param \App\ValueObject\UserContext $u Contexto del usuario actual.
     * @param string $pipeline Slug del pipeline.
     * @return array<string> Steps del pipeline donde el rol puede operar.
     */
    public function operableSteps(UserContext $u, string $pipeline): array
    {
        return $this->pipeline->getOperableSteps($u->roleId, $pipeline);
    }

    /**
     * @param int $roleId ID del rol cuya caché de permisos se invalida.
     * @return void
     */
    public function invalidate(int $roleId): void
    {
        $this->crud->invalidate($roleId);
        $this->pipeline->invalidate($roleId);
    }
}
