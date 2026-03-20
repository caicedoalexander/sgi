<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LegalizationDocumentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('legalization_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('LegalizationRecords', [
            'foreignKey' => 'legalization_record_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
        ]);
    }

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

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn('legalization_record_id', 'LegalizationRecords'),
            ['errorField' => 'legalization_record_id'],
        );
        $rules->add(
            $rules->existsIn('uploaded_by', 'UploadedByUsers'),
            ['errorField' => 'uploaded_by', 'allowNullableNulls' => true],
        );

        return $rules;
    }
}
