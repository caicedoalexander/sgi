<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class UpdateContractTypeValues extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE employees SET contract_type = 'FIJO' WHERE contract_type = 'Fijo'");
        $this->execute("UPDATE employees SET contract_type = 'INDEFINIDO' WHERE contract_type = 'Indefinido'");
        $this->execute("UPDATE employees SET contract_type = 'OBRA O LABOR DETERMINADA' WHERE contract_type = 'Temporal'");
        $this->execute("ALTER TABLE employees MODIFY contract_type VARCHAR(50) DEFAULT NULL");
    }

    public function down(): void
    {
        $this->execute("UPDATE employees SET contract_type = 'Fijo' WHERE contract_type = 'FIJO'");
        $this->execute("UPDATE employees SET contract_type = 'Indefinido' WHERE contract_type = 'INDEFINIDO'");
        $this->execute("UPDATE employees SET contract_type = 'Temporal' WHERE contract_type = 'OBRA O LABOR DETERMINADA'");
        $this->execute("ALTER TABLE employees MODIFY contract_type VARCHAR(20) DEFAULT NULL");
    }
}
