<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetDocumentsTable extends Table
{
    /**
     * Initialize the table.
     *
     * @param array<string, mixed> $config Configuration array
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_documents');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Assets', [
            'foreignKey' => 'asset_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('AssetMovements', [
            'foreignKey' => 'asset_movement_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Configure validation rules for asset document.
     *
     * @param \Cake\Validation\Validator $validator Validator instance
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('asset_id')
            ->requirePresence('asset_id', 'create')
            ->notEmptyString('asset_id');

        $validator
            ->scalar('document_type')
            ->requirePresence('document_type', 'create')
            ->inList('document_type', AssetConstants::DOCUMENT_TYPES, 'Tipo de documento inválido.');

        $validator
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 255)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->integer('uploaded_by')
            ->requirePresence('uploaded_by', 'create')
            ->notEmptyString('uploaded_by');

        $validator->integer('file_size')->allowEmptyString('file_size');
        $validator->scalar('mime_type')->maxLength('mime_type', 100)->allowEmptyString('mime_type');
        $validator->integer('asset_movement_id')->allowEmptyString('asset_movement_id');

        return $validator;
    }

    /**
     * Configure business rules for asset document.
     *
     * @param \Cake\ORM\RulesChecker $rules RulesChecker instance
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('asset_id', 'Assets'), ['errorField' => 'asset_id']);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), ['errorField' => 'uploaded_by']);
        $rules->add(
            $rules->existsIn('asset_movement_id', 'AssetMovements'),
            ['errorField' => 'asset_movement_id', 'allowNullableNulls' => true],
        );

        return $rules;
    }

    /**
     * Documentos de un activo, más reciente primero.
     */
    public function findForAsset(SelectQuery $query, int $assetId): SelectQuery
    {
        return $query
            ->where(['AssetDocuments.asset_id' => $assetId])
            ->orderBy(['AssetDocuments.created' => 'DESC']);
    }
}
