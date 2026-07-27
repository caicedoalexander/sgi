<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundApprovalsTable extends Table
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

        $this->setTable('refund_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Refunds', ['foreignKey' => 'refund_id', 'joinType' => 'INNER']);
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
            ->integer('refund_id')->requirePresence('refund_id', 'create')->notEmptyString('refund_id');
        $validator
            ->integer('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id');
        $validator
            ->scalar('status')->maxLength('status', 20)->notEmptyString('status');
        $validator
            ->scalar('token_hash')->maxLength('token_hash', 64)->allowEmptyString('token_hash');

        return $validator;
    }
}
