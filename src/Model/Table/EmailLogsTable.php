<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\EmailLogConstants;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmailLogsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('email_logs');
        $this->setDisplayField('subject');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        // Serializa array <-> JSON para la columna payload (MySQL JSON type).
        $this->getSchema()->setColumnType('payload', 'json');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('event_type')->maxLength('event_type', 50)->notEmptyString('event_type')
            ->scalar('to_email')->maxLength('to_email', 255)->notEmptyString('to_email')
            ->scalar('subject')->maxLength('subject', 255)->notEmptyString('subject')
            ->scalar('template')->maxLength('template', 100)->notEmptyString('template')
            ->array('payload')->notEmptyArray('payload')
            ->inList('status', EmailLogConstants::STATUSES)
            ->integer('attempts')->greaterThanOrEqual('attempts', 0)
            ->allowEmptyString('entity_type')
            ->integer('entity_id')->allowEmptyString('entity_id')
            ->maxLength('last_error', EmailLogConstants::ERROR_MESSAGE_MAX_LENGTH)
            ->allowEmptyString('last_error')
            ->allowEmptyDateTime('last_attempt_at')
            ->allowEmptyDateTime('sent_at')
            ->allowEmptyString('created_by');

        return $validator;
    }

    /** Filas pertenecientes a una entidad concreta (factura o novedad). */
    public function findForEntity(SelectQuery $query, string $entityType, int $entityId): SelectQuery
    {
        return $query
            ->where([
                'EmailLogs.entity_type' => $entityType,
                'EmailLogs.entity_id' => $entityId,
            ])
            ->orderBy(['EmailLogs.created' => 'DESC']);
    }

    /** Solo fallidos (para retry masivo). */
    public function findFailed(SelectQuery $query): SelectQuery
    {
        return $query->where(['EmailLogs.status' => EmailLogConstants::STATUS_FAILED]);
    }

    /** Pendientes huérfanos (creados antes del threshold y sin intento reciente). */
    public function findOrphanPending(SelectQuery $query): SelectQuery
    {
        $cutoff = (new DateTime())->modify('-' . EmailLogConstants::ORPHAN_THRESHOLD_SECONDS . ' seconds');

        return $query->where([
            'EmailLogs.status' => EmailLogConstants::STATUS_PENDING,
            'EmailLogs.created <' => $cutoff,
            'OR' => [
                'EmailLogs.last_attempt_at IS' => null,
                'EmailLogs.last_attempt_at <' => $cutoff,
            ],
        ]);
    }
}
