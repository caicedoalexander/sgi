<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CleanupLiquidationSignatures extends BaseMigration
{
    public function up(): void
    {
        // Remove all jefe_inmediato signature records
        $this->execute("DELETE FROM novelty_liquidation_signatures WHERE signer_type = 'jefe_inmediato'");
    }

    public function down(): void
    {
        // Cannot restore deleted records — no-op
    }
}
