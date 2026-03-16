<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRequiresContabilidadToNoveltyTypes extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_types');
        $table->addColumn('requires_contabilidad', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'requires_rrhh',
        ])->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_types');
        $table->removeColumn('requires_contabilidad')->update();
    }
}
