<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class SystemSettingsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('system_settings');
        $this->setDisplayField('setting_key');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('setting_key')
            ->maxLength('setting_key', 100)
            ->requirePresence('setting_key', 'create')
            ->notEmptyString('setting_key');

        $validator
            ->scalar('setting_value')
            ->allowEmptyString('setting_value');

        $validator
            ->scalar('setting_group')
            ->maxLength('setting_group', 50)
            ->notEmptyString('setting_group');

        return $validator;
    }
}
