<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyDocumentsTable extends Table
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

        $this->setTable('novelty_documents');
        $this->setDisplayField('file_name');
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
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
            'joinType' => 'LEFT',
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
        $validator->scalar('pipeline_status')->requirePresence('pipeline_status', 'create')->notEmptyString('pipeline_status');
        $validator->scalar('file_path')->requirePresence('file_path', 'create')->notEmptyString('file_path');
        $validator->scalar('file_name')->requirePresence('file_name', 'create')->notEmptyString('file_name');
        $validator->integer('file_size')->requirePresence('file_size', 'create');
        $validator->scalar('mime_type')->requirePresence('mime_type', 'create')->notEmptyString('mime_type');

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
        $rules->add($rules->existsIn('novelty_id', 'EmployeeNovelties'), [
            'errorField' => 'novelty_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), [
            'errorField' => 'liquidation_doc_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), [
            'errorField' => 'uploaded_by',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
