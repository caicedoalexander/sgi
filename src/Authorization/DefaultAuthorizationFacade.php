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
    public function __construct(
        private readonly AuthorizationService $crud,
        private readonly PipelineAuthorizationService $pipeline,
    ) {
    }

    public function canCrud(UserContext $u, string $module, CrudAction $a): bool
    {
        return $this->crud->isAllowed($u->roleId, $u->roleName, $module, $a->value);
    }

    public function canOperate(UserContext $u, string $pipeline, string $step): bool
    {
        return $this->pipeline->canOperate($u->roleId, $pipeline, $step);
    }

    public function operableSteps(UserContext $u, string $pipeline): array
    {
        return $this->pipeline->getOperableSteps($u->roleId, $pipeline);
    }

    public function invalidate(int $roleId): void
    {
        $this->crud->invalidate($roleId);
        $this->pipeline->invalidate($roleId);
    }
}
