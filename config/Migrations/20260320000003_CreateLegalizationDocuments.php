<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationDocuments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_documents')) {
            $table = $this->table('legalization_documents');
            $table
                ->addColumn('legalization_record_id', 'integer', ['signed' => true])
                ->addColumn('document_type', 'string', ['limit' => 100, 'null' => true, 'default' => null])
                ->addColumn('file_path', 'string', ['limit' => 255])
                ->addColumn('file_name', 'string', ['limit' => 255])
                ->addColumn('file_size', 'integer', ['null' => true, 'default' => null])
                ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true, 'default' => null])
                ->addColumn('uploaded_by', 'integer', ['null' => true, 'default' => null, 'signed' => true])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_documents')) {
            $this->table('legalization_documents')->drop()->save();
        }
    }
}
