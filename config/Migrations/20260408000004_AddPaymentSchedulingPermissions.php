<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentSchedulingPermissions extends BaseMigration
{
    public function up(): void
    {
        // Permisos del módulo payment_schedulings se asignan manualmente desde la UI.
    }

    public function down(): void
    {
    }
}
