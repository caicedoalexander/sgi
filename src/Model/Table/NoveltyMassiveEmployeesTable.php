<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyMassiveEmployeesTable extends Table
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

        $this->setTable('novelty_massive_employees');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('EmployeeNovelties', [
            'foreignKey' => 'novelty_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
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
        $validator->integer('novelty_id')->requirePresence('novelty_id', 'create')->notEmptyString('novelty_id');
        $validator->integer('employee_id')->requirePresence('employee_id', 'create')->notEmptyString('employee_id');

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
        $rules->add($rules->existsIn('novelty_id', 'EmployeeNovelties'), ['errorField' => 'novelty_id']);
        $rules->add($rules->existsIn('employee_id', 'Employees'), ['errorField' => 'employee_id']);

        return $rules;
    }
}
