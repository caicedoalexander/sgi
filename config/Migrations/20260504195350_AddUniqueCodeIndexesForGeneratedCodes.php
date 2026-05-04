<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddUniqueCodeIndexesForGeneratedCodes extends BaseMigration
{
    public function up(): void
    {
        foreach (['petty_cash_records', 'refunds', 'payment_schedulings'] as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }
            $t = $this->table($tableName);
            if (!$t->hasIndexByName('uniq_' . $tableName . '_code')) {
                $t->addIndex(['code'], [
                    'unique' => true,
                    'name' => 'uniq_' . $tableName . '_code',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        foreach (['petty_cash_records', 'refunds', 'payment_schedulings'] as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }
            $t = $this->table($tableName);
            if ($t->hasIndexByName('uniq_' . $tableName . '_code')) {
                $t->removeIndexByName('uniq_' . $tableName . '_code')->update();
            }
        }
    }
}
