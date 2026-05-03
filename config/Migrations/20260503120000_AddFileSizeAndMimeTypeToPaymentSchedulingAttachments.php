<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddFileSizeAndMimeTypeToPaymentSchedulingAttachments extends BaseMigration
{
    public function up(): void
    {
        $this->table('payment_scheduling_attachments')
            ->addColumn('file_size', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'file_name',
            ])
            ->addColumn('mime_type', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'file_size',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('payment_scheduling_attachments')
            ->removeColumn('mime_type')
            ->removeColumn('file_size')
            ->update();
    }
}
