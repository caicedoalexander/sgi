<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\I18n\DateTime;
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
        $existing = $this->find()
            ->where(['invoice_id' => $invoiceId, 'user_id' => $userId])
            ->first();

        if ($existing) {
            $existing->last_visited_at = new DateTime();
            $this->save($existing);
        } else {
            $entity = $this->newEntity([
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'last_visited_at' => new DateTime(),
            ]);
            $this->save($entity);
        }
    }
}
