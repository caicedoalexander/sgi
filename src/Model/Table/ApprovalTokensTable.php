<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class ApprovalTokensTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('approval_tokens');
        $this->setDisplayField('token');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('token')
            ->maxLength('token', 64)
            ->requirePresence('token', 'create')
            ->notEmptyString('token');

        $validator
            ->scalar('entity_type')
            ->maxLength('entity_type', 50)
            ->requirePresence('entity_type', 'create')
            ->notEmptyString('entity_type');

        $validator
            ->integer('entity_id')
            ->requirePresence('entity_id', 'create')
            ->notEmptyString('entity_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
