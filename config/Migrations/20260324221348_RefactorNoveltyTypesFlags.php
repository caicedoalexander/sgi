<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RefactorNoveltyTypesFlags extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_types');

        // Add new columns
        $table->addColumn('requires_boss_approval', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'parent_id',
        ]);
        $table->addColumn('requires_employee_signature_creation', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'requires_boss_approval',
        ]);
        $table->addColumn('requires_employee_signature_review', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'requires_employee_signature_creation',
        ]);

        // Remove old columns
        $table->removeColumn('requires_rrhh');
        $table->removeColumn('requires_contabilidad');
        $table->removeColumn('requires_firmas');
        $table->removeColumn('requires_gdp');
        $table->removeColumn('requires_tesoreria');

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_types');

        $table->addColumn('requires_rrhh', 'boolean', ['default' => true, 'null' => false]);
        $table->addColumn('requires_contabilidad', 'boolean', ['default' => false, 'null' => false]);
        $table->addColumn('requires_firmas', 'boolean', ['default' => true, 'null' => false]);
        $table->addColumn('requires_gdp', 'boolean', ['default' => true, 'null' => false]);
        $table->addColumn('requires_tesoreria', 'boolean', ['default' => true, 'null' => false]);

        $table->removeColumn('requires_boss_approval');
        $table->removeColumn('requires_employee_signature_creation');
        $table->removeColumn('requires_employee_signature_review');

        $table->update();
    }
}
