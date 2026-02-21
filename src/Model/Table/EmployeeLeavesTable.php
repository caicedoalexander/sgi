<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class EmployeeLeavesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employee_leaves');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('LeaveTypes', [
            'foreignKey' => 'leave_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ApprovedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'approved_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('RequestedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'requested_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('employee_id')
            ->requirePresence('employee_id', 'create')
            ->notEmptyString('employee_id');

        $validator
            ->integer('leave_type_id')
            ->requirePresence('leave_type_id', 'create')
            ->notEmptyString('leave_type_id');

        $validator
            ->date('start_date')
            ->requirePresence('start_date', 'create')
            ->notEmptyDate('start_date');

        $validator
            ->date('end_date')
            ->requirePresence('end_date', 'create')
            ->notEmptyDate('end_date');

        $validator
            ->scalar('status')
            ->inList('status', ['pendiente', 'aprobado', 'rechazado']);

        $validator
            ->scalar('observations')
            ->allowEmptyString('observations');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('employee_id', 'Employees'), ['errorField' => 'employee_id']);
        $rules->add($rules->existsIn('leave_type_id', 'LeaveTypes'), ['errorField' => 'leave_type_id']);
        $rules->add($rules->existsIn('approved_by', 'ApprovedByUsers'), [
            'errorField' => 'approved_by',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('requested_by', 'RequestedByUsers'), ['errorField' => 'requested_by']);

        return $rules;
    }
}
