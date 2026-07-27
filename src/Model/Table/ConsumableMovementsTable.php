<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ConsumableMovementsTable extends Table
{
    /**
     * Initialize table.
     *
     * @param array<string, mixed> $config Configuration array.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('consumable_movements');
        $this->setDisplayField('movement_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Consumables', [
            'foreignKey' => 'consumable_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('PerformedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'performed_by_user_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('RelatedAssets', [
            'className' => 'Assets',
            'foreignKey' => 'related_asset_id',
            'joinType' => 'LEFT',
        ]);
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
            ->integer('consumable_id')
            ->requirePresence('consumable_id', 'create')
            ->notEmptyString('consumable_id');

        $validator
            ->scalar('movement_type')
            ->requirePresence('movement_type', 'create')
            ->inList('movement_type', ConsumableConstants::MOVEMENT_TYPES, 'Tipo de movimiento inválido.');

        $validator
            ->integer('quantity')
            ->requirePresence('quantity', 'create')
            ->notEmptyString('quantity');

        $validator
            ->integer('balance_after')
            ->requirePresence('balance_after', 'create')
            ->notEmptyString('balance_after');

        $validator
            ->dateTime('movement_date')
            ->requirePresence('movement_date', 'create')
            ->notEmptyDateTime('movement_date');

        $validator
            ->integer('performed_by_user_id')
            ->requirePresence('performed_by_user_id', 'create')
            ->notEmptyString('performed_by_user_id');

        $validator
            ->scalar('source')
            ->requirePresence('source', 'create')
            ->inList('source', AssetConstants::SOURCES, 'Origen inválido.')
            ->notEmptyString('source');

        $validator->scalar('reason')->allowEmptyString('reason');
        $validator->integer('related_asset_id')->allowEmptyString('related_asset_id');
        $validator
            ->scalar('requested_by_phone')
            ->maxLength('requested_by_phone', 30)
            ->allowEmptyString('requested_by_phone');

        return $validator;
    }

    /**
     * Build entity rules.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules checker instance.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('consumable_id', 'Consumables'), ['errorField' => 'consumable_id']);
        $rules->add(
            $rules->existsIn('performed_by_user_id', 'PerformedByUsers'),
            ['errorField' => 'performed_by_user_id'],
        );

        return $rules;
    }

    /**
     * Movimientos de stock de un consumible, más reciente primero.
     */
    public function findForConsumable(SelectQuery $query, int $consumableId): SelectQuery
    {
        return $query
            ->where(['ConsumableMovements.consumable_id' => $consumableId])
            ->orderBy(['ConsumableMovements.movement_date' => 'DESC', 'ConsumableMovements.id' => 'DESC']);
    }
}
