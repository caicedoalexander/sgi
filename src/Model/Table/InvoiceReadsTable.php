<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class InvoiceReadsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoice_reads');
        $this->setPrimaryKey('id');

        $this->belongsTo('Invoices', ['foreignKey' => 'invoice_id']);
        $this->belongsTo('Users', ['foreignKey' => 'user_id']);
    }

    /**
     * Marca una factura como leída por el usuario (insert o update).
     */
    public function markAsRead(int $invoiceId, int $userId): void
    {
        $this->getConnection()->execute(
            'INSERT INTO invoice_reads (invoice_id, user_id, last_visited_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_visited_at = NOW()',
            [$invoiceId, $userId],
        );
    }
}
