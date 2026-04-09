<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingAttachmentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_scheduling_attachments');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PaymentSchedulings', [
            'foreignKey' => 'payment_scheduling_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('payment_scheduling_id')
            ->requirePresence('payment_scheduling_id', 'create')
            ->notEmptyString('payment_scheduling_id');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 500)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->scalar('file_name')
            ->maxLength('file_name', 255)
            ->requirePresence('file_name', 'create')
            ->notEmptyString('file_name');

        $validator
            ->integer('uploaded_by')
            ->requirePresence('uploaded_by', 'create')
            ->notEmptyString('uploaded_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('payment_scheduling_id', 'PaymentSchedulings'), ['errorField' => 'payment_scheduling_id']);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), ['errorField' => 'uploaded_by']);

        return $rules;
    }
}
