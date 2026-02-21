<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MigrateObservationsData extends BaseMigration
{
    public function up(): void
    {
        // Move existing observations data to invoice_observations table
        $this->execute("
            INSERT INTO invoice_observations (invoice_id, user_id, message, created)
            SELECT i.id, i.registered_by, i.observations, i.created
            FROM invoices i
            WHERE i.observations IS NOT NULL AND i.observations != ''
        ");

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
