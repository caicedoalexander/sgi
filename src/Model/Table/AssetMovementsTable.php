<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetMovementsTable extends Table
{
    /**
     * Initialize the table.
     *
     * @param array $config The configuration array.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_movements');
        $this->setDisplayField('movement_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Assets', [
            'foreignKey' => 'asset_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('FromEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'from_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ToEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'to_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('FromOperationCenters', [
            'className' => 'OperationCenters',
            'foreignKey' => 'from_operation_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ToOperationCenters', [
            'className' => 'OperationCenters',
            'foreignKey' => 'to_operation_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PerformedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'performed_by_user_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('RequestedByEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'requested_by_employee_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Validation rules.
     *
     * @param \Cake\Validation\Validator $validator The validator.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('asset_id')
            ->requirePresence('asset_id', 'create')
            ->notEmptyString('asset_id');

        $validator
            ->scalar('movement_type')
            ->requirePresence('movement_type', 'create')
            ->inList(
                'movement_type',
                AssetConstants::MOVEMENT_TYPES,
                'Tipo de movimiento inválido.',
            );

        $validator
            ->dateTime('movement_date')
            ->requirePresence('movement_date', 'create')
            ->notEmptyDateTime('movement_date');

        $validator
            ->integer('performed_by_user_id')
            ->requirePresence('performed_by_user_id', 'create')
            ->notEmptyString('performed_by_user_id');

        $validator
            ->scalar('acta_status')
            ->inList(
                'acta_status',
                AssetConstants::ACTA_STATUSES,
                'Estado de acta inválido.',
            )
            ->allowEmptyString('acta_status');

        $validator
            ->scalar('source')
            ->requirePresence('source', 'create')
            ->inList('source', AssetConstants::SOURCES, 'Origen inválido.')
            ->notEmptyString('source');

        $validator->scalar('reason')->allowEmptyString('reason');
        $validator
            ->scalar('requested_by_phone')
            ->maxLength('requested_by_phone', 30)
            ->allowEmptyString('requested_by_phone');
        $validator->integer('from_employee_id')->allowEmptyString('from_employee_id');
        $validator->integer('to_employee_id')->allowEmptyString('to_employee_id');
        $validator
            ->integer('from_operation_center_id')
            ->allowEmptyString('from_operation_center_id');
        $validator
            ->integer('to_operation_center_id')
            ->allowEmptyString('to_operation_center_id');
        $validator
            ->integer('requested_by_employee_id')
            ->allowEmptyString('requested_by_employee_id');

        return $validator;
    }

    /**
     * Build rules for database constraints.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules checker.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('asset_id', 'Assets'), ['errorField' => 'asset_id']);
        $rules->add(
            $rules->existsIn('performed_by_user_id', 'PerformedByUsers'),
            ['errorField' => 'performed_by_user_id'],
        );

        return $rules;
    }

    /**
     * Movimientos de un activo, más reciente primero.
     */
    public function findForAsset(SelectQuery $query, int $assetId): SelectQuery
    {
        return $query
            ->where(['AssetMovements.asset_id' => $assetId])
            ->orderBy(['AssetMovements.movement_date' => 'DESC', 'AssetMovements.id' => 'DESC']);
    }
}
