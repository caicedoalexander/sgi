<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LeaveTemplateFieldsTable extends Table
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

        $this->setTable('leave_template_fields');
        $this->setDisplayField('field_key');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('LeaveDocumentTemplates', [
            'foreignKey' => 'leave_document_template_id',
            'joinType' => 'INNER',
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
            ->integer('leave_document_template_id')
            ->requirePresence('leave_document_template_id', 'create')
            ->notEmptyString('leave_document_template_id');

        $validator
            ->scalar('field_key')
            ->maxLength('field_key', 100)
            ->requirePresence('field_key', 'create')
            ->notEmptyString('field_key');

        $validator
            ->decimal('x')
            ->requirePresence('x', 'create')
            ->notEmptyString('x');

        $validator
            ->decimal('y')
            ->requirePresence('y', 'create')
            ->notEmptyString('y');

        $validator
            ->integer('font_size')
            ->allowEmptyString('font_size');

        $validator
            ->scalar('font_style')
            ->maxLength('font_style', 10)
            ->allowEmptyString('font_style');

        $validator
            ->scalar('alignment')
            ->maxLength('alignment', 1)
            ->allowEmptyString('alignment');

        $validator
            ->scalar('field_type')
            ->maxLength('field_type', 20)
            ->allowEmptyString('field_type');

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
        $rules->add($rules->existsIn('leave_document_template_id', 'LeaveDocumentTemplates'), [
            'errorField' => 'leave_document_template_id',
        ]);

        return $rules;
    }
}
