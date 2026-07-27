<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AssetAlertConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AssetAlertsTable extends Table
{
    /**
     * Initializa la tabla: nombre, clave primaria, comportamiento timestamp y asociaciones.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('asset_alerts');
        $this->setDisplayField('message');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Assets', [
            'foreignKey' => 'asset_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Consumables', [
            'foreignKey' => 'consumable_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('AssetMovements', [
            'foreignKey' => 'asset_movement_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Reglas de validación: alert_type y message requeridos, inList para tipos/prioridades/estados.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('alert_type')
            ->requirePresence('alert_type', 'create')
            ->inList('alert_type', AssetAlertConstants::TYPES, 'Tipo de alerta inválido.');

        $validator
            ->scalar('priority')
            ->inList('priority', AssetAlertConstants::PRIORITIES, 'Prioridad inválida.')
            ->notEmptyString('priority');

        $validator
            ->scalar('status')
            ->inList('status', AssetAlertConstants::STATUSES, 'Estado inválido.')
            ->notEmptyString('status');

        $validator
            ->scalar('message')
            ->maxLength('message', 255)
            ->requirePresence('message', 'create')
            ->notEmptyString('message');

        $validator->integer('asset_id')->allowEmptyString('asset_id');
        $validator->integer('consumable_id')->allowEmptyString('consumable_id');
        $validator->integer('asset_movement_id')->allowEmptyString('asset_movement_id');
        $validator->dateTime('notified_at')->allowEmptyDateTime('notified_at');
        $validator->dateTime('resolved_at')->allowEmptyDateTime('resolved_at');

        return $validator;
    }

    /**
     * Alertas abiertas, prioridad alta primero, luego más recientes.
     */
    public function findOpen(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['AssetAlerts.status' => AssetAlertConstants::STATUS_ABIERTA])
            ->orderBy(['AssetAlerts.priority' => 'ASC', 'AssetAlerts.created' => 'DESC']);
    }

    /**
     * Alertas por estado.
     */
    public function findByStatus(SelectQuery $query, string $status): SelectQuery
    {
        return $query
            ->where(['AssetAlerts.status' => $status])
            ->orderBy(['AssetAlerts.created' => 'DESC']);
    }
}
