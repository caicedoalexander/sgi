<?php
declare(strict_types=1);

namespace App\Authorization;

/**
 * Acciones CRUD reconocidas por `AuthorizationFacade::canCrud`.
 *
 * Los valores string coinciden con los slugs usados en la tabla `permissions`
 * y en el atributo `#[Permission(action: '...')]` para preservar compat.
 */
enum CrudAction: string
{
    case View = 'view';
    case Add = 'add';
    case Edit = 'edit';
    case Delete = 'delete';
}
