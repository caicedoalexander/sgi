<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LegalizationPaymentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('legalization_payments');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('LegalizationRecords', [
            'foreignKey' => 'legalization_record_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('AuthorizedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'authorized_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('legalization_record_id')
            ->requirePresence('legalization_record_id', 'create')
            ->notEmptyString('legalization_record_id');

        $validator
            ->integer('banking_entity_id')
            ->requirePresence('banking_entity_id', 'create')
            ->notEmptyString('banking_entity_id');

        $validator
            ->decimal('amount')
            ->requirePresence('amount', 'create')
            ->notEmptyString('amount');

        $validator
            ->date('payment_date')
            ->requirePresence('payment_date', 'create')
            ->notEmptyDate('payment_date');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        $validator
            ->boolean('authorized');

        $validator
            ->integer('authorized_by')
            ->allowEmptyString('authorized_by');

        $validator
            ->date('authorized_date')
            ->allowEmptyDate('authorized_date');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('legalization_record_id', 'LegalizationRecords'), ['errorField' => 'legalization_record_id']);
        $rules->add($rules->existsIn('banking_entity_id', 'BankingEntities'), ['errorField' => 'banking_entity_id']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
