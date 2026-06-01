<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * Seed inicial: crea el rol Administrador (id=1) y su usuario `admin`.
 *
 * Uso:
 *   php bin/cake.php migrations seed --seed AdminUserSeed
 *
 * Es el ÚNICO registro que la BD debe tener al iniciar. El resto de roles y
 * permisos se gestionan desde la UI (el Administrador bypassa los módulos
 * `users` y `roles`). Idempotente: no duplica el rol ni el usuario.
 */
class AdminUserSeed extends BaseSeed
{
    private const USERNAME = 'admin';
    private const PASSWORD = 'Admin2024*';
    private const FULL_NAME = 'Administrador';
    private const EMAIL = 'admin@copcsa.com';
    private const ROLE_NAME = 'Administrador';
    private const ROLE_ID = 1;

    public function run(): void
    {
        $existing = $this->fetchRow(
            "SELECT id FROM users WHERE username = '" . self::USERNAME . "'"
        );
        if ($existing) {
            return;
        }

        // Crea el rol Administrador (id=1) si aún no existe.
        $role = $this->fetchRow(
            "SELECT id FROM roles WHERE name = '" . self::ROLE_NAME . "'"
        );
        if (!$role) {
            $this->table('roles')->insert([
                [
                    'id' => self::ROLE_ID,
                    'name' => self::ROLE_NAME,
                    'created' => date('Y-m-d H:i:s'),
                    'modified' => date('Y-m-d H:i:s'),
                ],
            ])->saveData();
            $roleId = self::ROLE_ID;
        } else {
            $roleId = (int)($role['id'] ?? $role[0]);
        }

        $this->table('users')->insert([
            [
                'role_id' => $roleId,
                'username' => self::USERNAME,
                'password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
                'full_name' => self::FULL_NAME,
                'email' => self::EMAIL,
                'active' => 1,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();
    }
}
