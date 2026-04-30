<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PettyCashObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('petty_cash_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('PettyCashRecords', [
            'foreignKey' => 'petty_cash_record_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('petty_cash_record_id')
            ->requirePresence('petty_cash_record_id', 'create')
            ->notEmptyString('petty_cash_record_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message', 'La observación no puede estar vacía.')
            ->add('message', 'minLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\PettyCashConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\PettyCashConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen(trim($value)) >= 10;
                },
                'message' => 'El motivo de la regresión debe tener al menos 10 caracteres.',
            ])
            ->add('message', 'maxLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\PettyCashConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\PettyCashConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen($value) <= 500;
                },
                'message' => 'El motivo de la regresión no puede superar 500 caracteres.',
            ]);

        $validator
            ->scalar('type')
            ->maxLength('type', 20)
            ->inList('type', \App\Constants\PettyCashConstants::OBSERVATION_TYPES, 'Tipo de observación inválido.')
            ->allowEmptyString('type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('petty_cash_record_id', 'PettyCashRecords'), ['errorField' => 'petty_cash_record_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
