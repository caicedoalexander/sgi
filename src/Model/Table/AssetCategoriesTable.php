<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetCategoriesTable extends Table
{
    /**
     * Initialize the table.
     *
     * @param array<string, mixed> $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_categories');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Assets', [
            'foreignKey' => 'asset_category_id',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->requirePresence('code', 'create')
            ->notEmptyString('code', 'El código es requerido.');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name', 'El nombre es requerido.');

        $validator
            ->scalar('description')
            ->allowEmptyString('description');

        $validator
            ->boolean('active')
            ->notEmptyString('active');

        return $validator;
    }

    /**
     * Build rules.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules checker
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code'], 'El código ya existe.'), ['errorField' => 'code']);

        return $rules;
    }

    /**
     * Solo categorías activas, ordenadas por nombre.
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['AssetCategories.active' => true])->orderBy(['AssetCategories.name' => 'ASC']);
    }
}
