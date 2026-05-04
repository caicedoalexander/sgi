<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\RefundConstants;
use App\Service\CodeGeneratorService;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('refunds');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('BeneficiaryEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'beneficiary_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('BeneficiaryProviders', [
            'className' => 'Providers',
            'foreignKey' => 'beneficiary_provider_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PaymentCreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'payment_created_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PaymentAuthorizedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'payment_authorized_by',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('Invoices', [
            'foreignKey' => 'refund_id',
        ]);
        $this->hasMany('RefundObservations', [
            'foreignKey' => 'refund_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('RefundDocuments', [
            'foreignKey' => 'refund_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->scalar('status')
            ->inList('status', RefundConstants::STATUSES);

        $validator->decimal('total_amount');

        $validator
            ->scalar('beneficiary_type')
            ->inList('beneficiary_type', RefundConstants::BENEFICIARY_TYPES, 'Tipo de beneficiario inválido.')
            ->allowEmptyString('beneficiary_type');

        $validator->integer('beneficiary_employee_id')->allowEmptyString('beneficiary_employee_id');
        $validator->integer('beneficiary_provider_id')->allowEmptyString('beneficiary_provider_id');

        $validator->boolean('accrued');
        $validator->date('accrual_date')->allowEmptyDate('accrual_date');
        $validator->scalar('ready_for_payment')->allowEmptyString('ready_for_payment');

        $validator->integer('banking_entity_id')->allowEmptyString('banking_entity_id');
        $validator->decimal('payment_amount')->allowEmptyString('payment_amount');
        $validator->date('payment_date')->allowEmptyDate('payment_date');
        $validator->integer('payment_created_by')->allowEmptyString('payment_created_by');
        $validator->integer('payment_authorized_by')->allowEmptyString('payment_authorized_by');
        $validator->date('payment_authorized_date')->allowEmptyDate('payment_authorized_date');
        $validator->scalar('payment_status')->allowEmptyString('payment_status');
        $validator->scalar('payment_rejection_reason')->allowEmptyString('payment_rejection_reason');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        $validator
            ->integer('operation_center_id')
            ->requirePresence('operation_center_id', 'create')
            ->notEmptyString('operation_center_id', 'Selecciona un centro de operación.', 'create');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code'], 'El código ya existe.'), ['errorField' => 'code', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        // Beneficiary XOR rule: if type is set, exactly the matching FK must be set.
        $rules->add(function ($entity) {
            $type = $entity->beneficiary_type;
            if ($type === null || $type === '') {
                return true; // No beneficiary set yet (allowed in agrupacion).
            }
            if ($type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
                return !empty($entity->beneficiary_employee_id) && empty($entity->beneficiary_provider_id);
            }
            if ($type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
                return !empty($entity->beneficiary_provider_id) && empty($entity->beneficiary_employee_id);
            }

            return false;
        }, 'beneficiaryConsistency', [
            'errorField' => 'beneficiary_type',
            'message' => 'El beneficiario seleccionado no coincide con el tipo.',
        ]);

        return $rules;
    }

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$entity->isNew() || !empty($entity->code)) {
            return;
        }
        if (empty($entity->operation_center_id)) {
            return;
        }

        $generator = new CodeGeneratorService();
        $entity->code = $generator->generateRefundCode((int)$entity->operation_center_id);
    }
}
