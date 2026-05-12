<?php
declare(strict_types=1);

namespace App\ValueObject;

/**
 * Identificador del actor para chequeos de autorización.
 *
 * `roleName` se conserva solo para el admin bypass actual
 * (`AuthorizationService::ADMIN_BYPASS_MODULES`). Cuando PA-007 caiga, el
 * campo desaparece. Si el caller solo necesita `canOperate` (no `canCrud`),
 * puede dejar `roleName` vacío — el campo no se consulta en esa ruta.
 */
final readonly class UserContext
{
    public function __construct(
        public int $roleId,
        public string $roleName = '',
    ) {
    }

    /**
     * Compone el contexto desde la entidad User (o cualquier objeto con
     * `role_id` y `role->name` accesibles por propiedad). Pasar
     * `$identity->getOriginalData()` desde un controller con Authentication.
     */
    public static function fromIdentity(object $identity): self
    {
        return new self(
            roleId: (int)($identity->role_id ?? 0),
            roleName: (string)($identity->role?->name ?? ''),
        );
    }
}
