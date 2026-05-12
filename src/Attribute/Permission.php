<?php
declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Marca una acción de controller como CRUD del módulo asociado.
 *
 * El módulo se infiere del nombre del controller via
 * `AppController::$controllerModuleMap`. El gate llama
 * `AuthorizationService::isAllowed(roleId, roleName, module, $this->action)`.
 *
 * Valores válidos para $action: 'view', 'add', 'edit', 'delete'.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Permission
{
    /**
     * @param string $action Acción CRUD: 'view', 'add', 'edit' o 'delete'.
     */
    public function __construct(public readonly string $action)
    {
    }
}
