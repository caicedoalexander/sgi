<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LiquidationDocPaymentsTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('liquidation_doc_payments');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
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

    /**
     * @inheritDoc
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('liquidation_doc_id')
            ->requirePresence('liquidation_doc_id', 'create')
            ->notEmptyString('liquidation_doc_id');

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

    /**
     * @inheritDoc
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'),
            ['errorField' => 'liquidation_doc_id'],
        );
        $rules->add($rules->existsIn('banking_entity_id', 'BankingEntities'), ['errorField' => 'banking_entity_id']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
