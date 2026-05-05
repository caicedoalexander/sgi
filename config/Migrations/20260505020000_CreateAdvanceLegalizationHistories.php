<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizationHistories extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('advance_legalization_histories')) {
            return;
        }

        $this->table('advance_legalization_histories')
            ->addColumn('legalization_id', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => true])
            ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
            ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['legalization_id'])
            ->addForeignKey(
                'legalization_id',
                'advance_legalizations',
                'id',
                ['delete' => 'CASCADE', 'update' => 'NO_ACTION'],
            )
            ->addForeignKey(
                'user_id',
                'users',
                'id',
                ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'],
            )
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('advance_legalization_histories')) {
            $this->table('advance_legalization_histories')->drop()->save();
        }
    }
}
