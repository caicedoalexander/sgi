<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RenameFiremasAprobacionToRevisionFirmas extends BaseMigration
{
    public function up(): void
    {
        // Rename pipeline_status 'firmas_aprobacion' → 'revision_firmas' in all relevant tables
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'revision_firmas' WHERE pipeline_status = 'firmas_aprobacion'");
        $this->execute("UPDATE novelty_liquidation_docs SET pipeline_status = 'revision_firmas' WHERE pipeline_status = 'firmas_aprobacion'");
        $this->execute("UPDATE novelty_documents SET pipeline_status = 'revision_firmas' WHERE pipeline_status = 'firmas_aprobacion'");
    }

    public function down(): void
    {
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'firmas_aprobacion' WHERE pipeline_status = 'revision_firmas'");
        $this->execute("UPDATE novelty_liquidation_docs SET pipeline_status = 'firmas_aprobacion' WHERE pipeline_status = 'revision_firmas'");
        $this->execute("UPDATE novelty_documents SET pipeline_status = 'firmas_aprobacion' WHERE pipeline_status = 'revision_firmas'");
    }
}
