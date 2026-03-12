<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class UpdatePettyCashStatuses extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('petty_cash_records')) {
            return;
        }

        // Rename 'borrador' to 'agrupacion'
        $this->execute("UPDATE petty_cash_records SET status = 'agrupacion' WHERE status = 'borrador'");

        // Rename 'causado' to 'contabilidad'
        $this->execute("UPDATE petty_cash_records SET status = 'contabilidad' WHERE status = 'causado'");

        // Update column default
        $this->execute("ALTER TABLE petty_cash_records ALTER COLUMN status SET DEFAULT 'agrupacion'");
    }

    public function down(): void
    {
        if (!$this->hasTable('petty_cash_records')) {
            return;
        }

        // Revert 'agrupacion' to 'borrador'
        $this->execute("UPDATE petty_cash_records SET status = 'borrador' WHERE status = 'agrupacion'");

        // Revert 'contabilidad' and 'tesoreria' to 'causado'
        $this->execute("UPDATE petty_cash_records SET status = 'causado' WHERE status IN ('contabilidad', 'tesoreria')");
    }
}
