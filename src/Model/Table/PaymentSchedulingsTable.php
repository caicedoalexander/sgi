<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\PaymentSchedulingConstants;
use App\Service\CodeGeneratorService;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_schedulings');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('PaymentSchedulingItems', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PaymentSchedulingDocuments', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PaymentSchedulingObservations', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('InvoicePayments', [
            'foreignKey' => 'payment_scheduling_id',
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
        $entity->code = $generator->generatePaymentSchedulingCode((int)$entity->operation_center_id);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->scalar('title')
            ->maxLength('title', 255)
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', PaymentSchedulingConstants::PIPELINE_STATUSES);

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
        $rules->add($rules->isUnique(['code']), ['errorField' => 'code']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
