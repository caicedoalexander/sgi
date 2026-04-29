<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AdvanceConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationSignaturesTable extends Table
{
    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('advance_legalization_signatures');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceLegalizations', [
            'foreignKey' => 'legalization_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SignedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'signed_by_user_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('legalization_id')
            ->requirePresence('legalization_id', 'create')
            ->notEmptyString('legalization_id');

        $validator
            ->scalar('document_path')
            ->maxLength('document_path', 500)
            ->requirePresence('document_path', 'create')
            ->notEmptyString('document_path');

        $validator
            ->scalar('signature_status')
            ->inList('signature_status', AdvanceConstants::SIGNATURE_STATUSES);

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules Rules checker instance.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('legalization_id', 'AdvanceLegalizations'), [
            'errorField' => 'legalization_id',
        ]);

        return $rules;
    }
}
