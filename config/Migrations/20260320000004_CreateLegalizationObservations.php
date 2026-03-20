<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationObservations extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_observations')) {
            $table = $this->table('legalization_observations');
            $table
                ->addColumn('legalization_record_id', 'integer', ['signed' => true])
                ->addColumn('user_id', 'integer', ['signed' => true])
                ->addColumn('message', 'text')
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_observations')) {
            $this->table('legalization_observations')->drop()->save();
        }
    }
}
