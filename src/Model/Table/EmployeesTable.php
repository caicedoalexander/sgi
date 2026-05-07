<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ContractTypeConstants;
use App\Constants\EmployeeStatusConstants;
use App\Constants\NoveltyConstants;
use App\Model\Excel\ExcelExportableInterface;
use App\Model\Excel\ExcelExportableTrait;
use App\Service\EmployeeDocumentService;
use App\Service\EmployeeHistoryService;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmployeesTable extends Table implements ExcelExportableInterface
{
    use ExcelExportableTrait;

    private ?EmployeeDocumentService $documentService = null;

    private ?EmployeeHistoryService $historyService = null;

    /**
     * @param \App\Service\EmployeeDocumentService $service
     * @return void
     */
    public function setDocumentService(EmployeeDocumentService $service): void
    {
        $this->documentService = $service;
    }

    /**
     * @param \App\Service\EmployeeHistoryService $service
     * @return void
     */
    public function setHistoryService(EmployeeHistoryService $service): void
    {
        $this->historyService = $service;
    }

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employees');
        $this->setDisplayField('first_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

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
        $this->hasMany('EmployeeHistories', [
            'foreignKey' => 'employee_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('EmployeeObservations', [
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
            ->scalar('last_name1')
            ->maxLength('last_name1', 100)
            ->requirePresence('last_name1', 'create')
            ->notEmptyString('last_name1');

        $validator
            ->scalar('last_name2')
            ->maxLength('last_name2', 100)
            ->allowEmptyString('last_name2');

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
            ->scalar('status')
            ->inList('status', EmployeeStatusConstants::STATUSES, 'Estado de empleado inválido.')
            ->notEmptyString('status');

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

    /**
     * Finder that adds a conditional contain for today's active novelties.
     * Usage: ->find('withCurrentNovelty')
     */
    public function findWithCurrentNovelty(SelectQuery $query): SelectQuery
    {
        $today = date('Y-m-d');

        return $query->contain([
            'EmployeeNovelties' => function (SelectQuery $q) use ($today) {
                return $q
                    ->where([
                        'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_RECHAZADA,
                        'OR' => [
                            // Single-day: permission_date = today, no start/end range
                            [
                                'EmployeeNovelties.permission_date' => $today,
                                'EmployeeNovelties.start_date IS' => null,
                            ],
                            // Multi-day range: today within start_date..end_date
                            [
                                'EmployeeNovelties.start_date <=' => $today,
                                'EmployeeNovelties.end_date >=' => $today,
                            ],
                        ],
                    ])
                    ->contain(['NoveltyTypes'])
                    ->order(['EmployeeNovelties.created' => 'DESC'])
                    ->limit(1);
            },
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getExcelFields(): array
    {
        return [
            'document_type' => ['label' => 'Tipo de documento', 'type' => 'string'],
            'document_number' => [
                'label' => 'Cédula', 'type' => 'string', 'required' => true, 'is_key' => true,
                'aliases' => ['empleado'],
            ],
            'first_name' => ['label' => 'Nombres', 'type' => 'string', 'required_new' => true],
            'last_name1' => [
                'label' => 'Primer Apellido', 'type' => 'string', 'required_new' => true,
                'aliases' => ['apellido1', 'apellido 1', 'primer apellido', 'apellidos'],
            ],
            'last_name2' => [
                'label' => 'Segundo Apellido', 'type' => 'string',
                'aliases' => ['apellido2', 'apellido 2', 'segundo apellido'],
            ],
            'birth_date' => [
                'label' => 'Fecha de nacimiento', 'type' => 'date',
                'aliases' => ['fecha nacimiento del empleado'],
            ],
            'gender' => ['label' => 'Género', 'type' => 'string', 'aliases' => ['genero del empleado']],
            'email' => ['label' => 'Correo electrónico', 'type' => 'string', 'aliases' => ['email del contacto']],
            'phone' => ['label' => 'Teléfono', 'type' => 'string', 'aliases' => ['celular del contacto']],
            'address' => [
                'label' => 'Dirección', 'type' => 'string',
                'aliases' => ['dirección del contacto', 'direccion del contacto'],
            ],
            'city' => ['label' => 'Ciudad', 'type' => 'string'],
            'hire_date' => ['label' => 'Fecha de ingreso', 'type' => 'date', 'aliases' => ['fecha ingreso']],
            'termination_date' => ['label' => 'Fecha de retiro', 'type' => 'date'],
            'salary' => ['label' => 'Salario', 'type' => 'decimal'],
            'contract_type' => ['label' => 'Tipo de contrato', 'type' => 'string'],
            'vest_number' => ['label' => 'Número de chaleco', 'type' => 'string'],
            'eps' => ['label' => 'EPS', 'type' => 'string'],
            'pension_fund' => ['label' => 'Fondo de pensión', 'type' => 'string'],
            'arl' => ['label' => 'ARL', 'type' => 'string'],
            'severance_fund' => ['label' => 'Fondo de cesantías', 'type' => 'string'],
            'notes' => ['label' => 'Observaciones', 'type' => 'string'],

            'position_id' => [
                'label' => 'Código Cargo', 'type' => 'string',
                'fk' => true, 'fk_table' => 'Positions', 'fk_code' => 'code',
                'aliases' => ['id cargo'],
            ],
            'supervisor_position_id' => [
                'label' => 'Código Cargo supervisor', 'type' => 'string',
                'fk' => true, 'fk_table' => 'Positions', 'fk_code' => 'code',
                'aliases' => ['cargo jefe inmediato'],
            ],
            'operation_center_id' => [
                'label' => 'Código Centro de operación', 'type' => 'string',
                'fk' => true, 'fk_table' => 'OperationCenters', 'fk_code' => 'code',
                'aliases' => ['id c.o.'],
            ],
            'cost_center_id' => [
                'label' => 'Código Centro de costos', 'type' => 'string',
                'fk' => true, 'fk_table' => 'CostCenters', 'fk_code' => 'code',
                'aliases' => ['id ccosto'],
            ],
            'status' => [
                'label' => 'Estado empleado', 'type' => 'string',
            ],
            'marital_status_id' => [
                'label' => 'Estado civil', 'type' => 'string',
                'fk' => true, 'fk_table' => 'MaritalStatuses', 'fk_code' => 'name',
            ],
            'education_level_id' => [
                'label' => 'Nivel educativo', 'type' => 'string',
                'fk' => true, 'fk_table' => 'EducationLevels', 'fk_code' => 'name',
            ],
            'temporary_organization_id' => [
                'label' => 'NIT Temporal', 'type' => 'string',
                'fk' => true, 'fk_table' => 'TemporaryOrganizations', 'fk_code' => 'nit',
            ],

            'position' => [
                'label' => 'Cargo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'position_id',
                'aliases' => ['descripcion del cargo'],
            ],
            'supervisor_position' => [
                'label' => 'Cargo supervisor', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'supervisor_position_id',
                'aliases' => ['descripción cargo jefe inmediato', 'descripcion cargo jefe inmediato'],
            ],
            'operation_center' => [
                'label' => 'Centro de operación', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'OperationCenters', 'fk_target' => 'operation_center_id',
                'aliases' => ['descripcion c.o.'],
            ],
            'cost_center' => [
                'label' => 'Centro de costos', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'CostCenters', 'fk_target' => 'cost_center_id',
                'aliases' => ['descripcion ccosto'],
            ],
            'marital_status' => [
                'label' => 'Estado civil', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'MaritalStatuses', 'fk_target' => 'marital_status_id',
                'aliases' => ['estado civil del empleado'],
            ],
            'education_level' => [
                'label' => 'Nivel educativo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'EducationLevels', 'fk_target' => 'education_level_id',
                'aliases' => ['nivel educativo del empleado'],
            ],
            'temporary_organization' => [
                'label' => 'Temporal', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'TemporaryOrganizations',
                'fk_target' => 'temporary_organization_id',
            ],
        ];
    }

    /**
     * @return string
     */
    public function getExcelSheetTitle(): string
    {
        return 'Empleados';
    }

    /**
     * @return string
     */
    public function getExcelDownloadSlug(): string
    {
        return 'empleados';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getExcelExportContains(): array
    {
        return [
            'Positions', 'SupervisorPositions',
            'OperationCenters', 'CostCenters', 'MaritalStatuses',
            'EducationLevels', 'TemporaryOrganizations',
        ];
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity
     * @param int $userId
     * @return void
     */
    public function onExcelImportCreated(EntityInterface $entity, int $userId): void
    {
        ($this->documentService ?? new EmployeeDocumentService())
            ->createDefaultFolders((int)$entity->id);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $original
     * @param \Cake\Datasource\EntityInterface $entity
     * @param int $userId
     * @return void
     */
    public function onExcelImportUpdated(EntityInterface $original, EntityInterface $entity, int $userId): void
    {
        ($this->historyService ?? new EmployeeHistoryService())
            ->recordChanges($original, $entity, $userId);
    }
}
