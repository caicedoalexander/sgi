<?php
declare(strict_types=1);

use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

class SeedAdvancesPermissions extends BaseMigration
{
    /**
     * Permission matrix from docs/plans/2026-04-28-anticipos-design.md (§ "Matriz de roles × estados").
     *
     * @var array<string, array{view:int, create:int, edit:int, delete:int}>
     */
    private const MATRIX = [
        'Administrador' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
        'Contabilidad' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Tesorería' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Registro/Revisión' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Contador' => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
        'Coordinador Administrativo y Financiero' => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
    ];

    public function up(): void
    {
        // Sentencias preparadas para evitar concatenación + addslashes (audit MI-001).
        $conn = ConnectionManager::get('default');

        foreach (self::MATRIX as $roleName => $perms) {
            $row = $conn->execute(
                'SELECT id FROM roles WHERE name = :name',
                ['name' => $roleName],
            )->fetch('assoc');
            if (!$row) {
                continue;
            }
            $roleId = (int)$row['id'];

            $existing = $conn->execute(
                'SELECT id FROM permissions WHERE role_id = :role_id AND module = :module',
                ['role_id' => $roleId, 'module' => 'advances'],
            )->fetch('assoc');
            if ($existing) {
                continue;
            }

            $conn->execute(
                'INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES (:role_id, :module, :can_view, :can_create, :can_edit, :can_delete, NOW(), NOW())',
                [
                    'role_id' => $roleId,
                    'module' => 'advances',
                    'can_view' => $perms['view'],
                    'can_create' => $perms['create'],
                    'can_edit' => $perms['edit'],
                    'can_delete' => $perms['delete'],
                ],
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'advances'");
    }
}
