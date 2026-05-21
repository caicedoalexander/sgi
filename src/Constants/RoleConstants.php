<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Nombres de roles del sistema.
 *
 * Si se renombra un rol en la BD, actualizar aquí y todo el sistema
 * tomará el nuevo nombre automáticamente.
 */
final class RoleConstants
{
    public const ADMIN = 'Administrador';

    /**
     * Roles que el código referencia por nombre. Renombrarlos o eliminarlos
     * rompe el sistema, por lo que la UI los marca como "Sistema" y bloquea
     * su eliminación.
     *
     * @var list<string>
     */
    public const SYSTEM_ROLES = [
        self::ADMIN,
    ];

    /**
     * @param string $name Nombre del rol.
     * @return bool true si es un rol del sistema (no eliminable).
     */
    public static function isSystem(string $name): bool
    {
        return in_array($name, self::SYSTEM_ROLES, true);
    }
}
