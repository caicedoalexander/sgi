<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddProfileImageToEmployees extends BaseMigration
{
    public function up(): void
    {
        $this->table('employees')
            ->addColumn('profile_image', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'notes',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('employees')
            ->removeColumn('profile_image')
            ->update();
    }
}
