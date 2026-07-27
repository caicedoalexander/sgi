<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationApprovalsTable extends Table
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

        $this->setTable('advance_legalization_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceLegalizations', ['foreignKey' => 'advance_legalization_id', 'joinType' => 'INNER']);
        $this->belongsTo('Users', ['foreignKey' => 'user_id', 'joinType' => 'INNER']);
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
            ->integer('advance_legalization_id')
            ->requirePresence('advance_legalization_id', 'create')
            ->notEmptyString('advance_legalization_id');
        $validator
            ->integer('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id');
        $validator
            ->scalar('status')->maxLength('status', 20)->notEmptyString('status');
        $validator
            ->scalar('token_hash')->maxLength('token_hash', 64)->allowEmptyString('token_hash');

        return $validator;
    }
}
