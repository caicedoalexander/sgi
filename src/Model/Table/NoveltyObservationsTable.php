<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('EmployeeNovelties', [
            'foreignKey' => 'novelty_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message', 'La observación no puede estar vacía.');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn('novelty_id', 'EmployeeNovelties'), [
            'errorField' => 'novelty_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), [
            'errorField' => 'liquidation_doc_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
