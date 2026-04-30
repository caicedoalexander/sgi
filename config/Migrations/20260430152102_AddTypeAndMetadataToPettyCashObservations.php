<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTypeAndMetadataToPettyCashObservations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('petty_cash_observations');

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

        $indexTable = $this->table('petty_cash_observations');
        if (!$indexTable->hasIndex(['petty_cash_record_id', 'type'])) {
            $indexTable->addIndex(['petty_cash_record_id', 'type'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('petty_cash_observations');
        if ($table->hasIndex(['petty_cash_record_id', 'type'])) {
            $table->removeIndex(['petty_cash_record_id', 'type'])->update();
        }
        $table->removeColumn('metadata')->removeColumn('type')->update();
    }
}
