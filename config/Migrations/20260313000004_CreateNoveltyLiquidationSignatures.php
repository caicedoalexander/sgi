<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyLiquidationSignatures extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_liquidation_signatures')) {
            $this->table('novelty_liquidation_signatures')
                ->addColumn('liquidation_doc_id', 'integer', ['null' => false])
                ->addColumn('signer_type', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('signature_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('signed_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('approved_at', 'datetime', ['null' => true, 'default' => null])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('signed_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_liquidation_signatures')) {
            $this->table('novelty_liquidation_signatures')->drop()->save();
        }
    }
}
