<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\NoveltyConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyLiquidationSignaturesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_liquidation_signatures');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SignedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'signed_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('liquidation_doc_id')
            ->requirePresence('liquidation_doc_id', 'create')
            ->notEmptyString('liquidation_doc_id');

        $validator
            ->scalar('signer_type')
            ->inList('signer_type', NoveltyConstants::SIGNER_TYPES)
            ->requirePresence('signer_type', 'create')
            ->notEmptyString('signer_type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), ['errorField' => 'liquidation_doc_id']);
        $rules->add($rules->existsIn('signed_by', 'SignedByUsers'), [
            'errorField' => 'signed_by',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
