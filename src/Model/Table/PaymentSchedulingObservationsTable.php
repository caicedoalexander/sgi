<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingObservationsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_scheduling_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PaymentSchedulings', [
            'foreignKey' => 'payment_scheduling_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('payment_scheduling_id')
            ->requirePresence('payment_scheduling_id', 'create')
            ->notEmptyString('payment_scheduling_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message')
            ->add('message', 'minLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? PaymentSchedulingConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen(trim($value)) >= 10;
                },
                'message' => 'El motivo de la regresión debe tener al menos 10 caracteres.',
            ])
            ->add('message', 'maxLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? PaymentSchedulingConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen($value) <= 500;
                },
                'message' => 'El motivo de la regresión no puede superar 500 caracteres.',
            ]);

        $validator
            ->scalar('type')
            ->maxLength('type', 20)
            ->inList('type', PaymentSchedulingConstants::OBSERVATION_TYPES, 'Tipo de observación inválido.')
            ->allowEmptyString('type');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('payment_scheduling_id', 'PaymentSchedulings'), ['errorField' => 'payment_scheduling_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
