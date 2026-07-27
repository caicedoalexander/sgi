<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class User extends Entity
{
    protected array $_accessible = [
        'role_id' => true,
        'username' => true,
        'password' => true,
        'full_name' => true,
        'email' => true,
        'active' => true,
    ];

    protected array $_hidden = [
        'password',
    ];

    /**
     * Mutator: hashea la contraseña con password_hash() antes de persistirla.
     *
     * @param string $password Contraseña en texto plano.
     * @return string Hash de la contraseña.
     */
    protected function _setPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
