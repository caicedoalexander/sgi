<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropPaymentAttachmentsTables extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('invoice_payment_attachments')) {
            $this->table('invoice_payment_attachments')->drop()->update();
        }

        if ($this->hasTable('petty_cash_payment_attachments')) {
            $this->table('petty_cash_payment_attachments')->drop()->update();
        }
    }

    public function down(): void
    {
    }
}
