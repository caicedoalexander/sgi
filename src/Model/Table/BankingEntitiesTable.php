<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class BankingEntitiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('banking_entities');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('InvoicePayments', [
            'foreignKey' => 'banking_entity_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 20)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->boolean('active');

        return $validator;
    }

    public function findCodeList(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['BankingEntities.active' => true])
            ->orderBy(['BankingEntities.name' => 'ASC'])
            ->formatResults(function ($results) {
                return $results->combine('id', function ($row) {
                    return $row->code . ' - ' . $row->name;
                });
            });
    }
}
