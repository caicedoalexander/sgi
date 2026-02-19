<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class ProvidersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('providers');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Invoices', [
            'foreignKey' => 'provider_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('nit')
            ->maxLength('nit', 20)
            ->requirePresence('nit', 'create')
            ->notEmptyString('nit');

        $validator
            ->scalar('name')
            ->maxLength('name', 200)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->boolean('active')
            ->notEmptyString('active');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['nit']), ['errorField' => 'nit']);

        return $rules;
    }
}
