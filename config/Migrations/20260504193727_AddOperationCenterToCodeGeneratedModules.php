<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddOperationCenterToCodeGeneratedModules extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('petty_cash_records')) {
            $t = $this->table('petty_cash_records');
            if (!$t->hasColumn('operation_center_id')) {
                $t->addColumn('operation_center_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ])
                ->addIndex(['operation_center_id'])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
            }
        }

        if ($this->hasTable('refunds')) {
            $t = $this->table('refunds');
            if (!$t->hasColumn('operation_center_id')) {
                $t->addColumn('operation_center_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ])
                ->addIndex(['operation_center_id'])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
            }
        }

        if ($this->hasTable('payment_schedulings')) {
            $t = $this->table('payment_schedulings');
            if (!$t->hasColumn('operation_center_id')) {
                $t->addColumn('operation_center_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ])
                ->addIndex(['operation_center_id'])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
            }
            $t->changeColumn('code', 'string', [
                'limit' => 30,
                'null' => false,
            ])->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('payment_schedulings')) {
            $t = $this->table('payment_schedulings');
            if ($t->hasColumn('operation_center_id')) {
                $t->dropForeignKey('operation_center_id')->update();
                $t->removeColumn('operation_center_id')->update();
            }
            $t->changeColumn('code', 'string', [
                'limit' => 20,
                'null' => false,
            ])->update();
        }

        if ($this->hasTable('refunds')) {
            $t = $this->table('refunds');
            if ($t->hasColumn('operation_center_id')) {
                $t->dropForeignKey('operation_center_id')->update();
                $t->removeColumn('operation_center_id')->update();
            }
        }

        if ($this->hasTable('petty_cash_records')) {
            $t = $this->table('petty_cash_records');
            if ($t->hasColumn('operation_center_id')) {
                $t->dropForeignKey('operation_center_id')->update();
                $t->removeColumn('operation_center_id')->update();
            }
        }
    }
}
