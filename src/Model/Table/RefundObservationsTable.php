<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\RefundConstants;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('refund_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Refunds', [
            'foreignKey' => 'refund_id',
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
            ->integer('refund_id')
            ->requirePresence('refund_id', 'create')
            ->notEmptyString('refund_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('type')
            ->inList('type', RefundConstants::OBSERVATION_TYPES)
            ->notEmptyString('type');

        $validator
            ->scalar('message')
            ->maxLength('message', 5000)
            ->requirePresence('message', 'create')
            ->notEmptyString('message');

        $validator->allowEmptyArray('metadata');

        return $validator;
    }
}
