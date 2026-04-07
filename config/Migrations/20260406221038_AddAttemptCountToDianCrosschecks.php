<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAttemptCountToDianCrosschecks extends BaseMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/5/en/migrations.html#the-change-method
     *
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('dian_crosschecks');
        $table->addColumn('attempt_count', 'integer', [
            'default' => 0,
            'null' => false,
            'after' => 'error_message',
        ]);
        $table->update();
    }
}
