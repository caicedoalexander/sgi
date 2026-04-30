<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTypeAndMetadataToPaymentSchedulingObservations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('payment_scheduling_observations');

        $columns = $table->getColumns();
        $columnNames = array_map(fn($c) => $c->getName(), $columns);

        if (!in_array('type', $columnNames, true)) {
            $table->addColumn('type', 'string', [
                'limit' => 20,
                'default' => 'general',
                'null' => false,
                'after' => 'message',
            ]);
        }

        if (!in_array('metadata', $columnNames, true)) {
            $table->addColumn('metadata', 'json', [
                'null' => true,
                'after' => 'type',
            ]);
        }

        $table->update();

        $indexTable = $this->table('payment_scheduling_observations');
        if (!$indexTable->hasIndex(['payment_scheduling_id', 'type'])) {
            $indexTable->addIndex(['payment_scheduling_id', 'type'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('payment_scheduling_observations');
        if ($table->hasIndex(['payment_scheduling_id', 'type'])) {
            $table->removeIndex(['payment_scheduling_id', 'type'])->update();
        }
        $table->removeColumn('metadata')->removeColumn('type')->update();
    }
}
