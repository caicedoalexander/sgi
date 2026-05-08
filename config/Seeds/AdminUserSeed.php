<?php
declare(strict_types=1);

use Migrations\BaseSeed;

/**
 * Seed para crear el usuario Administrador inicial.
 *
 * Uso:
 *   php bin/cake.php migrations seed --seed AdminUserSeed
 *
 * Idempotente: si el usuario `admin` ya existe, no hace nada.
 * Requiere que el rol `Administrador` exista (lo crea InitialSeed o el seed de roles).
 */
class AdminUserSeed extends BaseSeed
{
    private const USERNAME = 'admin';
    private const PASSWORD = 'Admin2024*';
    private const FULL_NAME = 'Administrador';
    private const EMAIL = 'admin@copcsa.com';
    private const ROLE_NAME = 'Administrador';

    public function run(): void
    {
        $existing = $this->fetchRow(
            "SELECT id FROM users WHERE username = '" . self::USERNAME . "'"
        );
        if ($existing) {
            return;
        }

        $role = $this->fetchRow(
            "SELECT id FROM roles WHERE name = '" . self::ROLE_NAME . "'"
        );
        if (!$role) {
            throw new RuntimeException(
                'No existe el rol "' . self::ROLE_NAME . '". Crea los roles antes de correr este seed.'
            );
        }
        $roleId = (int)($role['id'] ?? $role[0]);

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
