<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class InvoiceHistoriesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoice_histories');
        $this->setDisplayField('field_changed');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Invoices', [
            'foreignKey' => 'invoice_id',
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
            ->integer('invoice_id')
            ->requirePresence('invoice_id', 'create')
            ->notEmptyString('invoice_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('field_changed')
            ->maxLength('field_changed', 100)
            ->requirePresence('field_changed', 'create')
            ->notEmptyString('field_changed');

        $validator
            ->scalar('old_value')
            ->allowEmptyString('old_value');

        $validator
            ->scalar('new_value')
            ->allowEmptyString('new_value');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('invoice_id', 'Invoices'), ['errorField' => 'invoice_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
