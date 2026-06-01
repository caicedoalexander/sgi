<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropIsEquivalentDocumentFromInvoices extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoices');
        if ($table->hasColumn('is_equivalent_document')) {
            $table->removeColumn('is_equivalent_document')->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if (!$table->hasColumn('is_equivalent_document')) {
            $table->addColumn('is_equivalent_document', 'boolean', [
                'default' => false,
                'null'    => false,
                'after'   => 'document_type',
            ])->update();
        }

        // Reversibilidad mínima: marcar las filas con document_type='Recibo de Caja'.
        // No se restaura el document_type original (no hay forma de recuperarlo).
        $this->execute(
            "UPDATE invoices SET is_equivalent_document = 1 WHERE document_type = 'Recibo de Caja'"
        );
    }
}
