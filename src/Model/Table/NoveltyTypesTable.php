<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyTypesTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_types');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('ParentNoveltyTypes', [
            'className' => 'NoveltyTypes',
            'foreignKey' => 'parent_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('ChildNoveltyTypes', [
            'className' => 'NoveltyTypes',
            'foreignKey' => 'parent_id',
            'dependent' => false,
        ]);
        $this->hasMany('NoveltyTypeContractTemplates', [
            'foreignKey' => 'novelty_type_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('EmployeeNovelties', [
            'foreignKey' => 'novelty_type_id',
            'dependent' => false,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->integer('parent_id')
            ->allowEmptyString('parent_id');

        return $validator;
    }

    /**
     * @inheritDoc
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('parent_id', 'ParentNoveltyTypes'), [
            'errorField' => 'parent_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
