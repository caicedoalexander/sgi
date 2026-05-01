<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmailLogConstants;
use App\Model\Entity\EmailLog;
use App\Model\Table\EmailLogsTable;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

class EmailLogService
{
    private EmailLogsTable $emailLogsTable;
    private StructuredLogger $logger;

    public function __construct()
    {
        /** @var EmailLogsTable $table */
        $table = TableRegistry::getTableLocator()->get('EmailLogs');
        $this->emailLogsTable = $table;
        $this->logger = new StructuredLogger('EmailLog');
    }

    /**
     * Registra una nueva intención de envío con status='pending'. Devuelve el id.
     * El `payload` debe contener al menos las claves 'viewVars' y 'layout'.
     */
    public function recordPending(
        string $eventType,
        ?string $entityType,
        ?int $entityId,
        string $toEmail,
        string $subject,
        string $template,
        array $payload,
        ?int $createdBy,
    ): int {
        $log = $this->emailLogsTable->newEntity([
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'to_email' => $toEmail,
            'subject' => $subject,
            'template' => $template,
            'payload' => $payload,
            'status' => EmailLogConstants::STATUS_PENDING,
            'attempts' => 0,
            'created_by' => $createdBy,
        ]);

        if (!$this->emailLogsTable->save($log)) {
            $this->logger->error('Failed to persist email log row', [
                'errors' => $log->getErrors(),
                'event_type' => $eventType,
                'to' => $toEmail,
            ]);
            // Devolver 0 indica al caller que no se pudo persistir; el caller
            // debe seguir adelante con el envío (preferimos enviar sin log que
            // bloquear el flujo).
            return 0;
        }

        return (int)$log->id;
    }

    /**
     * Marca una fila como enviada. attempts++, sent_at=now, last_attempt_at=now.
     * No-op si $id=0 (caso de fallback descrito en recordPending).
     */
    public function markSent(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $log = $this->emailLogsTable->find()->where(['id' => $id])->first();
        if (!$log instanceof EmailLog) {
            return;
        }

        $now = new DateTime();
        $log->status = EmailLogConstants::STATUS_SENT;
        $log->attempts = $log->attempts + 1;
        $log->last_attempt_at = $now;
        $log->sent_at = $now;
        $log->last_error = null;

        $this->emailLogsTable->save($log);
    }

    /**
     * Marca una fila como fallida. attempts++, last_error truncado.
     * No-op si $id=0.
     */
    public function markFailed(int $id, string $error): void
    {
        if ($id <= 0) {
            return;
        }

        $log = $this->emailLogsTable->find()->where(['id' => $id])->first();
        if (!$log instanceof EmailLog) {
            return;
        }

        $log->status = EmailLogConstants::STATUS_FAILED;
        $log->attempts = $log->attempts + 1;
        $log->last_attempt_at = new DateTime();
        $log->last_error = mb_substr($error, 0, EmailLogConstants::ERROR_MESSAGE_MAX_LENGTH);

        $this->emailLogsTable->save($log);
    }

    /**
     * Marca como failed cualquier fila pending huérfana (creada hace más de
     * ORPHAN_THRESHOLD_SECONDS y sin último intento reciente). Devuelve cuántas
     * filas afectó. Llamado lazy desde EmailLogsController::index y ::retry.
     */
    public function sweepOrphanPendings(): int
    {
        $cutoff = (new DateTime())->modify('-' . EmailLogConstants::ORPHAN_THRESHOLD_SECONDS . ' seconds');

        return $this->emailLogsTable->updateAll(
            [
                'status' => EmailLogConstants::STATUS_FAILED,
                'last_error' => 'Envío inconcluso (proceso interrumpido)',
                'last_attempt_at' => new DateTime(),
                'modified' => new DateTime(),
            ],
            [
                'status' => EmailLogConstants::STATUS_PENDING,
                'created <' => $cutoff,
                'OR' => [
                    'last_attempt_at IS' => null,
                    'last_attempt_at <' => $cutoff,
                ],
            ],
        );
    }

    /** Logs ordenados desc por fecha para una entidad concreta (panel inline). */
    public function forEntity(string $entityType, int $entityId): array
    {
        return $this->emailLogsTable->find('forEntity', entityType: $entityType, entityId: $entityId)
            ->all()
            ->toArray();
    }

    /** Acceso a la table para callers (controller usa paginate sobre la query). */
    public function getTable(): EmailLogsTable
    {
        return $this->emailLogsTable;
    }
}
