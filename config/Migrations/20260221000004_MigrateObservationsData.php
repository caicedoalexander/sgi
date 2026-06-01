<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MigrateObservationsData extends BaseMigration
{
    public function up(): void
    {
        // Drop observations column from invoices
        $this->table('invoices')
            ->removeColumn('observations')
            ->update();
    }

    public function down(): void
    {
        // Re-add observations column
        $this->table('invoices')
            ->addColumn('observations', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->update();
    }
}
