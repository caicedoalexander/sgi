<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class PermissionsTable extends Table
{
    public const MODULES = [
        'invoices',
        'providers',
        'operation_centers',
        'expense_types',
        'cost_centers',
        'users',
        'roles',
        'approvers',
    ];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('permissions');
        $this->setDisplayField('module');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('role_id')
            ->requirePresence('role_id', 'create')
            ->notEmptyString('role_id');

        $validator
            ->scalar('module')
            ->maxLength('module', 50)
            ->requirePresence('module', 'create')
            ->notEmptyString('module');

        $validator->boolean('can_view');
        $validator->boolean('can_create');
        $validator->boolean('can_edit');
        $validator->boolean('can_delete');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('role_id', 'Roles'), ['errorField' => 'role_id']);

        return $rules;
    }
}
