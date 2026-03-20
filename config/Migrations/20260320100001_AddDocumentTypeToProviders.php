<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddDocumentTypeToProviders extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('providers');
        $table->renameColumn('nit', 'document_number');
        $table->addColumn('document_type', 'string', [
            'limit' => 20,
            'default' => 'NIT',
            'null' => false,
            'after' => 'id',
        ]);
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('providers');
        $table->removeColumn('document_type');
        $table->renameColumn('document_number', 'nit');
        $table->update();
    }
}
