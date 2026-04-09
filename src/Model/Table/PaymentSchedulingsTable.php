<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\PaymentSchedulingConstants;
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
        $this->hasMany('PaymentSchedulingAttachments', [
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
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 20)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

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

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code']), ['errorField' => 'code']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }

    /**
     * Genera el siguiente código PRO-XXX secuencial.
     */
    public function generateNextCode(): string
    {
        $last = $this->find()
            ->select(['code'])
            ->where(['code LIKE' => PaymentSchedulingConstants::CODE_PREFIX . '-%'])
            ->order(['id' => 'DESC'])
            ->first();

        $nextNumber = 1;
        if ($last) {
            $parts = explode('-', $last->code);
            $nextNumber = (int)($parts[1] ?? 0) + 1;
        }

        return PaymentSchedulingConstants::CODE_PREFIX . '-' . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
