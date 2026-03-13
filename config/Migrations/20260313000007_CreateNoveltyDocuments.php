<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyDocuments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_documents')) {
            $this->table('novelty_documents')
                ->addColumn('novelty_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('pipeline_status', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('file_size', 'integer', ['null' => false])
                ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('uploaded_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_documents')) {
            $this->table('novelty_documents')->drop()->save();
        }
    }
}
