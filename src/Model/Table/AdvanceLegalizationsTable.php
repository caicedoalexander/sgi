<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AdvanceConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationsTable extends Table
{
    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('advance_legalizations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceInvoices', [
            'className' => 'Invoices',
            'foreignKey' => 'advance_invoice_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SurplusPayments', [
            'className' => 'InvoicePayments',
            'foreignKey' => 'surplus_payment_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UpdatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'updated_by',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('AdvanceLegalizationSignatures', [
            'foreignKey' => 'legalization_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('advance_invoice_id')
            ->requirePresence('advance_invoice_id', 'create')
            ->notEmptyString('advance_invoice_id');

        $validator
            ->scalar('status')
            ->inList('status', AdvanceConstants::PIPELINE_STATUSES)
            ->notEmptyString('status');

        $validator
            ->scalar('case_type')
            ->allowEmptyString('case_type')
            ->inList('case_type', AdvanceConstants::CASE_TYPES);

        $validator
            ->decimal('shortage_amount')
            ->allowEmptyString('shortage_amount');

        $validator
            ->decimal('surplus_amount')
            ->allowEmptyString('surplus_amount');

        $validator
            ->dateTime('shortage_received_at')
            ->allowEmptyDateTime('shortage_received_at');

        $validator
            ->scalar('shortage_receipt_number')
            ->maxLength('shortage_receipt_number', 100)
            ->allowEmptyString('shortage_receipt_number');

        $validator
            ->scalar('shortage_receipt_path')
            ->maxLength('shortage_receipt_path', 500)
            ->allowEmptyString('shortage_receipt_path');

        $validator
            ->dateTime('legalized_at')
            ->allowEmptyDateTime('legalized_at');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules Rules checker instance.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('advance_invoice_id', 'AdvanceInvoices'), [
            'errorField' => 'advance_invoice_id',
        ]);
        $rules->add($rules->isUnique(['advance_invoice_id'], 'Ya existe una legalización para este anticipo.'), [
            'errorField' => 'advance_invoice_id',
        ]);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
