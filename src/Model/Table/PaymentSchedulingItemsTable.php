<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingItemsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_scheduling_items');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PaymentSchedulings', [
            'foreignKey' => 'payment_scheduling_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Invoices', [
            'foreignKey' => 'invoice_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('payment_scheduling_id')
            ->requirePresence('payment_scheduling_id', 'create')
            ->notEmptyString('payment_scheduling_id');

        $validator
            ->integer('invoice_id')
            ->requirePresence('invoice_id', 'create')
            ->notEmptyString('invoice_id');

        $validator
            ->integer('banking_entity_id')
            ->requirePresence('banking_entity_id', 'create')
            ->notEmptyString('banking_entity_id');

        $validator
            ->decimal('amount')
            ->requirePresence('amount', 'create')
            ->notEmptyString('amount');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('payment_scheduling_id', 'PaymentSchedulings'), ['errorField' => 'payment_scheduling_id']);
        $rules->add($rules->existsIn('invoice_id', 'Invoices'), ['errorField' => 'invoice_id']);
        $rules->add($rules->existsIn('banking_entity_id', 'BankingEntities'), ['errorField' => 'banking_entity_id']);

        return $rules;
    }
}
