<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class InvoicesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoices');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Providers', [
            'foreignKey' => 'provider_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ExpenseTypes', [
            'foreignKey' => 'expense_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CostCenters', [
            'foreignKey' => 'cost_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ConfirmedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'confirmed_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('RegisteredByUsers', [
            'className' => 'Users',
            'foreignKey' => 'registered_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ApproverUsers', [
            'className' => 'Users',
            'foreignKey' => 'approver_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('InvoiceHistories', [
            'foreignKey' => 'invoice_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('invoice_number')
            ->maxLength('invoice_number', 50)
            ->allowEmptyString('invoice_number');

        $validator
            ->date('registration_date')
            ->requirePresence('registration_date', 'create')
            ->notEmptyDate('registration_date');

        $validator
            ->date('issue_date')
            ->requirePresence('issue_date', 'create')
            ->notEmptyDate('issue_date');

        $validator
            ->date('due_date')
            ->requirePresence('due_date', 'create')
            ->notEmptyDate('due_date');

        $validator
            ->scalar('document_type')
            ->maxLength('document_type', 50)
            ->requirePresence('document_type', 'create')
            ->notEmptyString('document_type')
            ->inList('document_type', [
                'Factura', 'Nota Debito', 'Caja menor', 'Tarjeta de Crédito',
                'Reintegro', 'Legalización', 'Recibo', 'Anticipo',
            ]);

        $validator
            ->scalar('purchase_order')
            ->maxLength('purchase_order', 50)
            ->allowEmptyString('purchase_order');

        $validator
            ->scalar('detail')
            ->requirePresence('detail', 'create')
            ->notEmptyString('detail');

        $validator
            ->decimal('amount')
            ->requirePresence('amount', 'create')
            ->notEmptyString('amount');

        $validator
            ->integer('provider_id')
            ->requirePresence('provider_id', 'create')
            ->notEmptyString('provider_id');

        $validator
            ->integer('operation_center_id')
            ->requirePresence('operation_center_id', 'create')
            ->notEmptyString('operation_center_id');

        $validator
            ->integer('expense_type_id')
            ->requirePresence('expense_type_id', 'create')
            ->notEmptyString('expense_type_id');

        $validator
            ->integer('cost_center_id')
            ->allowEmptyString('cost_center_id');

        $validator
            ->integer('registered_by')
            ->requirePresence('registered_by', 'create')
            ->notEmptyString('registered_by');

        $validator
            ->integer('approver_id')
            ->allowEmptyString('approver_id');

        $validator
            ->scalar('area_approval')
            ->inList('area_approval', ['Pendiente', 'Aprobada', 'Rechazada']);

        $validator
            ->scalar('dian_validation')
            ->inList('dian_validation', ['Pendiente', 'Aprobada', 'Rechazado']);

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', ['revision', 'area_approved', 'accrued', 'treasury', 'paid']);

        $validator
            ->scalar('ready_for_payment')
            ->allowEmptyString('ready_for_payment')
            ->inList('ready_for_payment', [
                'Si', 'No', 'Anticipo Empleado', 'Anticipo Proveedor',
                'Pago prioritario', 'Pago PSE', 'No Legalización', 'Reintegro',
            ]);

        $validator
            ->scalar('payment_status')
            ->allowEmptyString('payment_status')
            ->inList('payment_status', ['Pago total', 'Pago Parcial']);

        $validator
            ->date('area_approval_date')
            ->allowEmptyDate('area_approval_date');

        $validator
            ->boolean('accrued');

        $validator
            ->date('accrual_date')
            ->allowEmptyDate('accrual_date');

        $validator
            ->date('payment_date')
            ->allowEmptyDate('payment_date');

        $validator
            ->scalar('observations')
            ->allowEmptyString('observations');

        $validator
            ->integer('confirmed_by')
            ->allowEmptyString('confirmed_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['invoice_number'], message: 'El número de factura ya existe.'), [
            'errorField' => 'invoice_number',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('provider_id', 'Providers'), ['errorField' => 'provider_id']);
        $rules->add($rules->existsIn('operation_center_id', 'OperationCenters'), ['errorField' => 'operation_center_id']);
        $rules->add($rules->existsIn('expense_type_id', 'ExpenseTypes'), ['errorField' => 'expense_type_id']);
        $rules->add($rules->existsIn('cost_center_id', 'CostCenters'), [
            'errorField' => 'cost_center_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('registered_by', 'RegisteredByUsers'), ['errorField' => 'registered_by']);
        $rules->add($rules->existsIn('confirmed_by', 'ConfirmedByUsers'), [
            'errorField' => 'confirmed_by',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('approver_id', 'ApproverUsers'), [
            'errorField' => 'approver_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
