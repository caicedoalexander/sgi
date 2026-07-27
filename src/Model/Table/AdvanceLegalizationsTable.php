<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
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
        $this->addBehavior('SidebarCache');

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
        $this->hasMany('AdvanceLegalizationDocuments', [
            'foreignKey' => 'legalization_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('AdvanceLegalizationHistories', [
            'foreignKey' => 'legalization_id',
            'dependent' => true,
            'cascadeCallbacks' => false,
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
            ->boolean('accrued')
            ->allowEmptyString('accrued');

        $validator
            ->date('accrual_date')
            ->allowEmptyDate('accrual_date');

        $validator
            ->scalar('ready_for_payment')
            ->inList('ready_for_payment', InvoiceConstants::READY_FOR_PAYMENT_OPTIONS)
            ->allowEmptyString('ready_for_payment');

        $validator
            ->dateTime('shortage_received_at')
            ->allowEmptyDateTime('shortage_received_at');

        $validator
            ->scalar('shortage_receipt_number')
            ->maxLength('shortage_receipt_number', 100)
            ->allowEmptyString('shortage_receipt_number');

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
        // Permitir null (caso exacto / faltante no tienen pago de reintegro)
        // pero validar referencia cuando está fijado (audit SU-007).
        $rules->add(
            $rules->existsIn('surplus_payment_id', 'SurplusPayments', ['allowNullableNulls' => true]),
            ['errorField' => 'surplus_payment_id'],
        );

        // Coherencia case_type ↔ amounts (audit MI-008): cada caso permite
        // exactamente uno de los dos montos (o ninguno, en exacto).
        $rules->add(
            function ($entity): bool {
                if ($entity->case_type === AdvanceConstants::CASE_EXACTO) {
                    return $entity->shortage_amount === null && $entity->surplus_amount === null;
                }
                if ($entity->case_type === AdvanceConstants::CASE_FALTANTE) {
                    return (float)$entity->shortage_amount > 0 && $entity->surplus_amount === null;
                }
                if ($entity->case_type === AdvanceConstants::CASE_SOBRANTE) {
                    return (float)$entity->surplus_amount > 0 && $entity->shortage_amount === null;
                }
                // case_type=null: ambos montos deben ser null.
                return $entity->shortage_amount === null && $entity->surplus_amount === null;
            },
            'caseAmountsCoherence',
            [
                'errorField' => 'case_type',
                'message' => 'Los montos no son coherentes con el caso declarado.',
            ],
        );

        // legalized_at solo puede estar fijado cuando status=legalizada
        // (audit MI-008). En estados intermedios debe ser null.
        $rules->add(
            fn($entity): bool => $entity->legalized_at === null
                || $entity->status === AdvanceConstants::STATUS_LEGALIZADA,
            'legalizedAtRequiresLegalizedStatus',
            [
                'errorField' => 'legalized_at',
                'message' => 'legalized_at solo puede fijarse cuando status=legalizada.',
            ],
        );

        return $rules;
    }
}
