<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\EmailLogConstants;
use App\Service\EmailLogService;

/**
 * Bitácora de envíos de correo (Plan 2 — W8). Solo Administrador.
 *
 * Las acciones retry y retryAllFailed se agregan en tareas posteriores.
 */
class EmailLogsController extends AppController
{
    private EmailLogService $emailLogService;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->emailLogService = new EmailLogService();
    }

    /**
     * Listado paginado de logs de correo con filtros (status, event_type, fechas, destinatario).
     *
     * @return void
     */
    public function index(): void
    {
        // Sweep lazy de huérfanos antes de listar.
        $this->emailLogService->sweepOrphanPendings();

        $table = $this->emailLogService->getTable();

        $query = $table->find();

        // Filtros
        $status = (string)$this->request->getQuery('status', '');
        if ($status !== '' && in_array($status, EmailLogConstants::STATUSES, true)) {
            $query = $query->where(['EmailLogs.status' => $status]);
        }

        $eventType = (string)$this->request->getQuery('event_type', '');
        if ($eventType !== '' && array_key_exists($eventType, EmailLogConstants::EVENT_LABELS)) {
            $query = $query->where(['EmailLogs.event_type' => $eventType]);
        }

        $from = (string)$this->request->getQuery('from', '');
        if ($from !== '') {
            $query = $query->where(['EmailLogs.created >=' => $from . ' 00:00:00']);
        }

        $to = (string)$this->request->getQuery('to', '');
        if ($to !== '') {
            $query = $query->where(['EmailLogs.created <=' => $to . ' 23:59:59']);
        }

        $email = (string)$this->request->getQuery('email', '');
        if ($email !== '') {
            $query = $query->where(['EmailLogs.to_email LIKE' => '%' . $email . '%']);
        }

        $query = $query->orderBy(['EmailLogs.created' => 'DESC']);

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $emailLogs = $this->paginate($query);

        $this->set(compact('emailLogs', 'status', 'eventType', 'from', 'to', 'email'));
        $this->set('statusOptions', EmailLogConstants::STATUS_LABELS);
        $this->set('eventOptions', EmailLogConstants::EVENT_LABELS);
    }
}
