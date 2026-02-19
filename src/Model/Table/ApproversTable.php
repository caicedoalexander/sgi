<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class ApproversTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('approvers');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->integer('operation_center_id')
            ->allowEmptyString('operation_center_id');

        $validator
            ->boolean('active')
            ->notEmptyString('active');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn('operation_center_id', 'OperationCenters'), [
            'errorField' => 'operation_center_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
