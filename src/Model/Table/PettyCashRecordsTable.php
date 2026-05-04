<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\PettyCashConstants;
use App\Service\CodeGeneratorService;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PettyCashRecordsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('petty_cash_records');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
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
            'foreignKey' => 'petty_cash_record_id',
        ]);
        $this->hasMany('PettyCashDocuments', [
            'foreignKey' => 'petty_cash_record_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PettyCashObservations', [
            'foreignKey' => 'petty_cash_record_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
        ]);
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
        $entity->code = $generator->generatePettyCashCode((int)$entity->operation_center_id);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->integer('operation_center_id')
            ->requirePresence('operation_center_id', 'create')
            ->notEmptyString('operation_center_id', 'Selecciona un centro de operación.', 'create');

        $validator
            ->scalar('status')
            ->inList('status', PettyCashConstants::STATUSES);

        $validator
            ->decimal('total_amount');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        $validator->integer('banking_entity_id')->allowEmptyString('banking_entity_id');
        $validator->decimal('payment_amount')->allowEmptyString('payment_amount');
        $validator->integer('payment_created_by')->allowEmptyString('payment_created_by');
        $validator->integer('payment_authorized_by')->allowEmptyString('payment_authorized_by');
        $validator->date('payment_authorized_date')->allowEmptyDate('payment_authorized_date');
        $validator->scalar('payment_rejection_reason')->allowEmptyString('payment_rejection_reason');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code'], 'El código ya existe.'), ['errorField' => 'code', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
