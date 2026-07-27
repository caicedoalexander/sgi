<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationDocumentsTable extends Table
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

        $this->setTable('advance_legalization_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceLegalizations', [
            'foreignKey' => 'legalization_id',
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
            ->integer('legalization_id')
            ->requirePresence('legalization_id', 'create')
            ->notEmptyString('legalization_id');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 255)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->scalar('file_name')
            ->maxLength('file_name', 255)
            ->requirePresence('file_name', 'create')
            ->notEmptyString('file_name');

        $validator
            ->scalar('document_type')
            ->maxLength('document_type', 100)
            ->allowEmptyString('document_type');

        $validator
            ->scalar('mime_type')
            ->maxLength('mime_type', 100)
            ->allowEmptyString('mime_type');

        $validator
            ->integer('file_size')
            ->allowEmptyString('file_size');

        $validator
            ->integer('uploaded_by')
            ->allowEmptyString('uploaded_by');

        return $validator;
    }
}
