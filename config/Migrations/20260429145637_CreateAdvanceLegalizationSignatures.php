<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizationSignatures extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('advance_legalization_signatures')) {
            return;
        }

        $this->table('advance_legalization_signatures')
            ->addColumn('legalization_id', 'integer', ['null' => false])
            ->addColumn('signed_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('signed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('document_path', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('document_name', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('signature_status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending'])
            ->addColumn('rejection_reason', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['legalization_id'])
            ->addForeignKey('legalization_id', 'advance_legalizations', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('signed_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('advance_legalization_signatures')) {
            $this->table('advance_legalization_signatures')->drop()->save();
        }
    }
}
