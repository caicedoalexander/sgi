<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use App\Service\CodeGeneratorService;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetsTable extends Table
{
    /**
     * @param array<string, mixed> $config Configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('assets');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AssetCategories', [
            'foreignKey' => 'asset_category_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ResponsibleEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'responsible_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CostCenters', [
            'foreignKey' => 'cost_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('AssetMovements', [
            'foreignKey' => 'asset_id',
        ]);
        $this->hasMany('AssetDocuments', [
            'foreignKey' => 'asset_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    /**
     * @param \Cake\Event\EventInterface $event Event.
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     * @param \ArrayObject $options Options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$entity->isNew() || !empty($entity->code)) {
            return;
        }
        if (empty($entity->operation_center_id)) {
            return;
        }

        $generator = new CodeGeneratorService();
        $entity->code = $generator->generateAssetCode((int)$entity->operation_center_id);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->scalar('serial_number')
            ->maxLength('serial_number', 100)
            ->allowEmptyString('serial_number');

        $validator
            ->integer('asset_category_id')
            ->requirePresence('asset_category_id', 'create')
            ->notEmptyString('asset_category_id', 'Selecciona una categoría.');

        $validator
            ->integer('operation_center_id')
            ->requirePresence('operation_center_id', 'create')
            ->notEmptyString('operation_center_id', 'Selecciona un centro de operación.');

        $validator->scalar('brand')->maxLength('brand', 100)->allowEmptyString('brand');
        $validator->scalar('model')->maxLength('model', 100)->allowEmptyString('model');
        $validator->scalar('description')->allowEmptyString('description');
        $validator->scalar('observations')->allowEmptyString('observations');
        $validator->integer('cost_center_id')->allowEmptyString('cost_center_id');
        $validator->integer('responsible_employee_id')->allowEmptyString('responsible_employee_id');
        $validator->date('acquisition_date')->allowEmptyDate('acquisition_date');

        $validator
            ->scalar('status')
            ->inList('status', AssetConstants::STATUSES);

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules Rules checker.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['code'], 'El código ya existe.'),
            ['errorField' => 'code', 'allowNullableNulls' => true],
        );
        $rules->add(
            $rules->existsIn('asset_category_id', 'AssetCategories'),
            ['errorField' => 'asset_category_id'],
        );
        $rules->add(
            $rules->existsIn('operation_center_id', 'OperationCenters'),
            ['errorField' => 'operation_center_id'],
        );
        $rules->add(
            $rules->existsIn('responsible_employee_id', 'ResponsibleEmployees'),
            ['errorField' => 'responsible_employee_id', 'allowNullableNulls' => true],
        );
        $rules->add(
            $rules->existsIn('cost_center_id', 'CostCenters'),
            ['errorField' => 'cost_center_id', 'allowNullableNulls' => true],
        );

        return $rules;
    }

    /**
     * Filtra el listado de activos. `$options` admite: status, category_id,
     * responsible_employee_id, operation_center_id, q (texto parcial sobre
     * code/serial_number/brand/model).
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query base.
     * @param array<string, mixed> $options Filtros.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findFiltered(SelectQuery $query, array $options = []): SelectQuery
    {
        // CakePHP 5 named-arg call: find('filtered', options: [...]) wraps the
        // array under the key 'options'. Unwrap it for backwards-compatible handling.
        if (isset($options['options']) && is_array($options['options'])) {
            $options = $options['options'];
        }

        if (!empty($options['status'])) {
            $query->where(['Assets.status' => $options['status']]);
        }
        if (!empty($options['category_id'])) {
            $query->where(['Assets.asset_category_id' => (int)$options['category_id']]);
        }
        if (!empty($options['responsible_employee_id'])) {
            $query->where(['Assets.responsible_employee_id' => (int)$options['responsible_employee_id']]);
        }
        if (!empty($options['operation_center_id'])) {
            $query->where(['Assets.operation_center_id' => (int)$options['operation_center_id']]);
        }
        if (!empty($options['q'])) {
            $term = '%' . trim((string)$options['q']) . '%';
            $query->where([
                'OR' => [
                    'Assets.code LIKE' => $term,
                    'Assets.serial_number LIKE' => $term,
                    'Assets.brand LIKE' => $term,
                    'Assets.model LIKE' => $term,
                ],
            ]);
        }

        return $query;
    }
}
