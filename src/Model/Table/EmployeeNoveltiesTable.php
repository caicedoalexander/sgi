<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\NoveltyConstants;
use ArrayObject;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmployeeNoveltiesTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employee_novelties');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('NoveltyTypes', [
            'foreignKey' => 'novelty_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ApprovedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'approved_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('RegisteredByUsers', [
            'className' => 'Users',
            'foreignKey' => 'registered_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('RrhhByUsers', [
            'className' => 'Users',
            'foreignKey' => 'rrhh_by',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('NoveltyMassiveEmployees', [
            'foreignKey' => 'novelty_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyObservations', [
            'foreignKey' => 'novelty_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyDocuments', [
            'foreignKey' => 'novelty_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('employee_id')
            ->allowEmptyString('employee_id');

        $validator
            ->integer('novelty_type_id')
            ->requirePresence('novelty_type_id', 'create')
            ->notEmptyString('novelty_type_id');

        $validator
            ->date('filing_date')
            ->requirePresence('filing_date', 'create')
            ->notEmptyDate('filing_date');

        $validator
            ->date('permission_date')
            ->allowEmptyDate('permission_date');

        $validator
            ->scalar('schedule_type')
            ->inList('schedule_type', NoveltyConstants::SCHEDULE_TYPES)
            ->allowEmptyString('schedule_type');

        $validator
            ->date('start_date')
            ->allowEmptyDate('start_date');

        $validator
            ->date('end_date')
            ->allowEmptyDate('end_date');

        $validator
            ->time('start_time')
            ->allowEmptyTime('start_time');

        $validator
            ->time('end_time')
            ->allowEmptyTime('end_time');

        $validator
            ->boolean('is_paid')
            ->notEmptyString('is_paid');

        $validator
            ->scalar('reason')
            ->allowEmptyString('reason');

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', NoveltyConstants::ALL_STATUSES);

        $validator
            ->scalar('observations')
            ->allowEmptyString('observations');

        return $validator;
    }

    /**
     * @inheritDoc
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        $scheduleType = $data['schedule_type'] ?? null;

        if ($scheduleType === NoveltyConstants::SCHEDULE_HOURS) {
            $data['start_date'] = null;
            $data['end_date'] = null;
        } elseif ($scheduleType === NoveltyConstants::SCHEDULE_DAYS) {
            $data['start_time'] = null;
            $data['end_time'] = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('employee_id', 'Employees'), [
            'errorField' => 'employee_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('novelty_type_id', 'NoveltyTypes'), ['errorField' => 'novelty_type_id']);
        $rules->add($rules->existsIn('approved_by', 'ApprovedByUsers'), [
            'errorField' => 'approved_by',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('registered_by', 'RegisteredByUsers'), ['errorField' => 'registered_by']);

        $rules->add(function ($entity) {
            if ($entity->schedule_type !== NoveltyConstants::SCHEDULE_HOURS) {
                return true;
            }

            return !empty($entity->start_time) && !empty($entity->end_time);
        }, 'hoursRequired', [
            'errorField' => 'start_time',
            'message' => 'Hora de salida y entrada son requeridas para horario por horas.',
        ]);

        $rules->add(function ($entity) {
            $isHours = $entity->schedule_type === NoveltyConstants::SCHEDULE_HOURS;
            if (!$isHours || empty($entity->start_time) || empty($entity->end_time)) {
                return true;
            }

            return (string)$entity->start_time < (string)$entity->end_time;
        }, 'startBeforeEnd', [
            'errorField' => 'start_time',
            'message' => 'La hora de salida debe ser anterior a la hora de entrada.',
        ]);

        $rules->add(function ($entity) {
            if ($entity->schedule_type !== NoveltyConstants::SCHEDULE_DAYS) {
                return true;
            }

            return !empty($entity->start_date) && !empty($entity->end_date);
        }, 'daysRequired', [
            'errorField' => 'start_date',
            'message' => 'Fecha inicio y fecha fin son requeridas para horario por días.',
        ]);

        return $rules;
    }
}
