<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MakePettyCashCodeOptional extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('petty_cash_records');
        $table->changeColumn('code', 'string', ['limit' => 30, 'null' => true, 'default' => null])
              ->update();
    }

    public function down(): void
    {
        $table = $this->table('petty_cash_records');
        $table->changeColumn('code', 'string', ['limit' => 30, 'null' => false])
              ->update();
    }
}
