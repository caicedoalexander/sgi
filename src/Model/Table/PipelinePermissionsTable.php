<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class PipelinePermissionsTable extends Table
{
    /**
     * @param array $config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('pipeline_permissions');
        $this->setDisplayField('step');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('role_id')
            ->requirePresence('role_id', 'create')
            ->notEmptyString('role_id');

        $validator
            ->scalar('pipeline')
            ->maxLength('pipeline', 40)
            ->requirePresence('pipeline', 'create')
            ->notEmptyString('pipeline');

        $validator
            ->scalar('step')
            ->maxLength('step', 40)
            ->requirePresence('step', 'create')
            ->notEmptyString('step');

        $validator
            ->boolean('can_operate')
            ->requirePresence('can_operate', 'create')
            ->notEmptyString('can_operate');

        return $validator;
    }
}
