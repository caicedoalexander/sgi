<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\NoveltyConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyLiquidationDocsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_liquidation_docs');
        $this->setDisplayField('liquidation_number');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PerformedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'performed_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('EmployeeNovelties', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => false,
        ]);
        $this->hasMany('NoveltyLiquidationSignatures', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyObservations', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyDocuments', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('LiquidationDocPayments', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('liquidation_number')
            ->maxLength('liquidation_number', 50)
            ->requirePresence('liquidation_number', 'create')
            ->notEmptyString('liquidation_number');

        $validator
            ->scalar('period')
            ->inList('period', NoveltyConstants::PERIODS)
            ->requirePresence('period', 'create')
            ->notEmptyString('period');

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', NoveltyConstants::ALL_STATUSES);

        $validator
            ->date('document_date')
            ->requirePresence('document_date', 'create')
            ->notEmptyDate('document_date');

        $validator
            ->integer('performed_by')
            ->requirePresence('performed_by', 'create')
            ->notEmptyString('performed_by');

        $validator
            ->boolean('passes_for_payment')
            ->allowEmptyString('passes_for_payment');

        $validator
            ->scalar('payment_status')
            ->inList('payment_status', NoveltyConstants::PAYMENT_STATUSES)
            ->allowEmptyString('payment_status');

        $validator
            ->date('payment_date')
            ->allowEmptyDate('payment_date');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['liquidation_number']), [
            'errorField' => 'liquidation_number',
            'message' => 'Este número de liquidación ya existe.',
        ]);
        $rules->add($rules->existsIn('performed_by', 'PerformedByUsers'), ['errorField' => 'performed_by']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
