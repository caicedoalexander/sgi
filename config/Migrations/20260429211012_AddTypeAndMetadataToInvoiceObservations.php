<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTypeAndMetadataToInvoiceObservations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoice_observations');

        if (!$table->hasColumn('type')) {
            $table->addColumn('type', 'string', [
                'limit' => 20,
                'default' => 'general',
                'null' => false,
                'after' => 'message',
            ]);
        }

        if (!$table->hasColumn('metadata')) {
            $table->addColumn('metadata', 'json', [
                'null' => true,
                'after' => 'type',
            ]);
        }

        $table->update();

        $indexTable = $this->table('invoice_observations');
        if (!$indexTable->hasIndex(['invoice_id', 'type'])) {
            $indexTable->addIndex(['invoice_id', 'type'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoice_observations');
        if ($table->hasIndex(['invoice_id', 'type'])) {
            $table->removeIndex(['invoice_id', 'type'])->update();
        }
        $table->removeColumn('metadata')->removeColumn('type')->update();
    }
}
