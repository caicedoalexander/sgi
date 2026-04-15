<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\PettyCashConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PettyCashRecordsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('petty_cash_records');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('Invoices', [
            'foreignKey' => 'petty_cash_record_id',
        ]);
        $this->hasMany('PettyCashDocuments', [
            'foreignKey' => 'petty_cash_record_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PettyCashObservations', [
            'foreignKey' => 'petty_cash_record_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PettyCashPayments', [
            'foreignKey' => 'petty_cash_record_id',
            'dependent' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->scalar('status')
            ->inList('status', PettyCashConstants::STATUSES);

        $validator
            ->decimal('total_amount');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
