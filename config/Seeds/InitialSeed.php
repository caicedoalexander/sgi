<?php
declare(strict_types=1);

use Migrations\BaseSeed;

class InitialSeed extends BaseSeed
{
    public function run(): void
    {
        // Roles
        $roles = $this->table('roles');
        $roles->insert([
            ['name' => 'Admin', 'description' => 'Administrador del sistema', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Contabilidad', 'description' => 'Área de contabilidad', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Tesorería', 'description' => 'Área de tesorería', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
            ['name' => 'Aux. Personal', 'description' => 'Auxiliar de personal', 'created' => date('Y-m-d H:i:s'), 'modified' => date('Y-m-d H:i:s')],
        ])->save();

        // Usuario Admin
        $users = $this->table('users');
        $users->insert([
            [
                'role_id' => 1,
                'username' => 'admin',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'full_name' => 'Administrador',
                'email' => 'admin@copcsa.com',
                'active' => 1,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ],
        ])->save();
    }
}
