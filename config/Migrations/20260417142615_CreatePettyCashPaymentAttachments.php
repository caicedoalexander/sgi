<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePettyCashPaymentAttachments extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('petty_cash_payment_attachments')) {
            $this->table('petty_cash_payment_attachments')->drop()->update();
        }

        $this->table('petty_cash_payment_attachments')
            ->addColumn('petty_cash_payment_id', 'integer', ['null' => false])
            ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('file_path', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('file_size', 'integer', ['null' => true])
            ->addColumn('uploaded_by', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['petty_cash_payment_id'])
            ->addForeignKey('petty_cash_payment_id', 'petty_cash_payments', 'id', [
                'delete' => 'CASCADE', 'update' => 'NO_ACTION',
            ])
            ->addForeignKey('uploaded_by', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('petty_cash_payment_attachments')) {
            $this->table('petty_cash_payment_attachments')->drop()->update();
        }
    }
}
