<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ContractTypeConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyTypeContractTemplatesTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_type_contract_templates');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('NoveltyTypes', [
            'foreignKey' => 'novelty_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('TemporaryOrganizations', [
            'foreignKey' => 'temporary_organization_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('LeaveDocumentTemplates', [
            'foreignKey' => 'leave_document_template_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * @inheritDoc
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('novelty_type_id')
            ->allowEmptyString('novelty_type_id');

        $validator
            ->scalar('contract_type')
            ->inList('contract_type', ContractTypeConstants::ALL, 'Tipo de contrato inválido.')
            ->notEmptyString('contract_type');

        $validator
            ->integer('leave_document_template_id')
            ->notEmptyString('leave_document_template_id');

        $validator
            ->integer('temporary_organization_id')
            ->allowEmptyString('temporary_organization_id');

        return $validator;
    }

    /**
     * @inheritDoc
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('novelty_type_id', 'NoveltyTypes'), ['errorField' => 'novelty_type_id']);
        $rules->add(
            $rules->existsIn('leave_document_template_id', 'LeaveDocumentTemplates'),
            ['errorField' => 'leave_document_template_id'],
        );
        $rules->add($rules->existsIn('temporary_organization_id', 'TemporaryOrganizations'), [
            'errorField' => 'temporary_organization_id',
            'allowNullableNulls' => true,
        ]);

        $rules->add(function ($entity) {
            $isObraLabor = $entity->contract_type === ContractTypeConstants::OBRA_LABOR;
            if (!$isObraLabor && !empty($entity->temporary_organization_id)) {
                return false;
            }

            return true;
        }, 'orgOnlyForObraLabor', [
            'errorField' => 'temporary_organization_id',
            'message' => 'La organización temporal solo aplica para OBRA O LABOR DETERMINADA.',
        ]);

        return $rules;
    }
}
