<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ContractTypeConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmployeesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employees');
        $this->setDisplayField('first_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('EmployeeStatuses', [
            'foreignKey' => 'employee_status_id',
        ]);
        $this->belongsTo('MaritalStatuses', [
            'foreignKey' => 'marital_status_id',
        ]);
        $this->belongsTo('EducationLevels', [
            'foreignKey' => 'education_level_id',
        ]);
        $this->belongsTo('Positions', [
            'foreignKey' => 'position_id',
        ]);
        $this->belongsTo('SupervisorPositions', [
            'className' => 'Positions',
            'foreignKey' => 'supervisor_position_id',
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
        ]);
        $this->belongsTo('CostCenters', [
            'foreignKey' => 'cost_center_id',
        ]);
        $this->belongsTo('TemporaryOrganizations', [
            'foreignKey' => 'temporary_organization_id',
        ]);
        $this->hasMany('EmployeeFolders', [
            'foreignKey' => 'employee_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('EmployeeNovelties', [
            'foreignKey' => 'employee_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('document_type')
            ->maxLength('document_type', 20)
            ->notEmptyString('document_type');

        $validator
            ->scalar('document_number')
            ->maxLength('document_number', 30)
            ->requirePresence('document_number', 'create')
            ->notEmptyString('document_number');

        $validator
            ->scalar('first_name')
            ->maxLength('first_name', 100)
            ->requirePresence('first_name', 'create')
            ->notEmptyString('first_name');

        $validator
            ->scalar('last_name')
            ->maxLength('last_name', 100)
            ->requirePresence('last_name', 'create')
            ->notEmptyString('last_name');

        $validator
            ->date('birth_date')
            ->allowEmptyDate('birth_date');

        $validator
            ->scalar('gender')
            ->maxLength('gender', 20)
            ->allowEmptyString('gender');

        $validator
            ->email('email')
            ->allowEmptyString('email');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 30)
            ->allowEmptyString('phone');

        $validator
            ->date('hire_date')
            ->allowEmptyDate('hire_date');

        $validator
            ->date('termination_date')
            ->allowEmptyDate('termination_date');

        $validator
            ->decimal('salary')
            ->allowEmptyString('salary');

        $validator
            ->scalar('contract_type')
            ->inList('contract_type', ContractTypeConstants::ALL, 'Tipo de contrato inválido.')
            ->allowEmptyString('contract_type');

        $validator
            ->scalar('vest_number')
            ->maxLength('vest_number', 20)
            ->allowEmptyString('vest_number');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['document_number'], message: 'El número de documento ya existe.'), [
            'errorField' => 'document_number',
        ]);
        $rules->add($rules->existsIn('employee_status_id', 'EmployeeStatuses'), ['errorField' => 'employee_status_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('marital_status_id', 'MaritalStatuses'), ['errorField' => 'marital_status_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('education_level_id', 'EducationLevels'), ['errorField' => 'education_level_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('position_id', 'Positions'), ['errorField' => 'position_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('supervisor_position_id', 'SupervisorPositions'), ['errorField' => 'supervisor_position_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('operation_center_id', 'OperationCenters'), ['errorField' => 'operation_center_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('cost_center_id', 'CostCenters'), ['errorField' => 'cost_center_id', 'allowNullableNulls' => true]);
        $rules->add($rules->existsIn('temporary_organization_id', 'TemporaryOrganizations'), ['errorField' => 'temporary_organization_id', 'allowNullableNulls' => true]);

        $rules->add(function ($entity) {
            if ($entity->contract_type === ContractTypeConstants::OBRA_LABOR && empty($entity->temporary_organization_id)) {
                return false;
            }

            return true;
        }, 'requireOrgTemporal', [
            'errorField' => 'temporary_organization_id',
            'message' => 'Debe seleccionar una organización temporal cuando el tipo de contrato es OBRA O LABOR DETERMINADA.',
        ]);

        return $rules;
    }
}
