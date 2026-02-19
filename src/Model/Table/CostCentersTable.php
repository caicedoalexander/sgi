<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class CostCentersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('cost_centers');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Invoices', [
            'foreignKey' => 'cost_center_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 20)
            ->allowEmptyString('code');

        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code'], message: 'El código ya existe.'), [
            'errorField' => 'code',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }

    public function findCodeList(SelectQuery $query): SelectQuery
    {
        return $query->select(['id', 'code', 'name'])
            ->formatResults(function ($results) {
                return $results->combine('id', function ($row) {
                    return $row->code ? $row->code . ' - ' . $row->name : $row->name;
                });
            });
    }
}
