<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddDocumentTypeToNoveltyDocuments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_documents');
        $table->addColumn('document_type', 'string', [
            'limit' => 20,
            'default' => 'support',
            'null' => false,
            'after' => 'liquidation_doc_id',
        ]);
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_documents');
        $table->removeColumn('document_type');
        $table->update();
    }
}
