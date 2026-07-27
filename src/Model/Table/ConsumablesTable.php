<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ConsumablesTable extends Table
{
    /**
     * Initialize table.
     *
     * @param array<string, mixed> $config Configuration array.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('consumables');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('ConsumableMovements', [
            'foreignKey' => 'consumable_id',
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
            ->scalar('reference')
            ->maxLength('reference', 50)
            ->requirePresence('reference', 'create')
            ->notEmptyString('reference', 'La referencia es requerida.');

        $validator
            ->scalar('description')
            ->maxLength('description', 255)
            ->requirePresence('description', 'create')
            ->notEmptyString('description', 'La descripción es requerida.');

        $validator
            ->integer('minimum_stock')
            ->greaterThanOrEqual('minimum_stock', 0)
            ->notEmptyString('minimum_stock');

        $validator
            ->integer('maximum_stock')
            ->greaterThanOrEqual('maximum_stock', 0)
            ->allowEmptyString('maximum_stock');

        $validator->scalar('unit')->maxLength('unit', 20)->allowEmptyString('unit');
        $validator->integer('operation_center_id')->allowEmptyString('operation_center_id');

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
        $rules->add($rules->isUnique(['reference'], 'La referencia ya existe.'), ['errorField' => 'reference']);
        $rules->add(
            $rules->existsIn('operation_center_id', 'OperationCenters'),
            ['errorField' => 'operation_center_id', 'allowNullableNulls' => true],
        );

        return $rules;
    }

    /**
     * Consumibles con stock en o por debajo del mínimo (RN-07).
     */
    public function findLowStock(SelectQuery $query): SelectQuery
    {
        return $query->where(['Consumables.current_stock <= Consumables.minimum_stock']);
    }
}
