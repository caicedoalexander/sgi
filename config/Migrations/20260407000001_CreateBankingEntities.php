<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateBankingEntities extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('banking_entities')) {
            $this->table('banking_entities')
                ->addColumn('code', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('active', 'boolean', ['default' => true, 'null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['code'], ['unique' => true])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('banking_entities')) {
            $this->table('banking_entities')->drop()->save();
        }
    }
}
