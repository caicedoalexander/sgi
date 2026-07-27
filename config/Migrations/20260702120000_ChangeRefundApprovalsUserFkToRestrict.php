<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Alinea el FK `refund_approvals.user_id` con la convención de auditoría del
 * módulo de reintegros (refund_histories.user_id, refunds.created_by, …): usa
 * `ON DELETE RESTRICT` en vez de `CASCADE`, para que un borrado de usuario no
 * elimine en cascada el rastro de quién aprobó/rechazó un reintegro. `update`
 * se mantiene en `CASCADE` (igual que el resto del módulo).
 */
class ChangeRefundApprovalsUserFkToRestrict extends BaseMigration
{
    public function up(): void
    {
        $this->table('refund_approvals')
            ->dropForeignKey('user_id')
            ->update();

        $this->table('refund_approvals')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->update();
    }

    public function down(): void
    {
        $this->table('refund_approvals')
            ->dropForeignKey('user_id')
            ->update();

        $this->table('refund_approvals')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->update();
    }
}
