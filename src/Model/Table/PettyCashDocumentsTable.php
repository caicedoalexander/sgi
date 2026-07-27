<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PettyCashDocumentsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('petty_cash_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PettyCashRecords', [
            'foreignKey' => 'petty_cash_record_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
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
            ->scalar('file_path')
            ->maxLength('file_path', 255)
            ->notEmptyString('file_path');

        $validator
            ->scalar('file_name')
            ->maxLength('file_name', 255)
            ->notEmptyString('file_name');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('petty_cash_record_id', 'PettyCashRecords'), ['errorField' => 'petty_cash_record_id']);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), ['errorField' => 'uploaded_by', 'allowNullableNulls' => true]);

        return $rules;
    }
}
