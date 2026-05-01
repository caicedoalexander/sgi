<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\EmailLogConstants;
use App\Model\Entity\EmailLog;
use App\Service\AuthorizationService;
use App\Service\EmailLogService;
use Cake\Http\Response;

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

    /**
     * Reenvía un correo concreto. Permisos:
     *   - Si el log no tiene entity_id (smtp_test futuro u otros): solo Administrador.
     *   - Si pertenece a una factura: requiere invoices.can_edit.
     *   - Si pertenece a una novedad: requiere employee_novelties.can_edit.
     *   - Administrador siempre puede (bypass del AuthorizationService).
     *
     * @param string|null $id ID del email log a reintentar.
     * @return \Cake\Http\Response
     */
    public function retry(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);

        if ($id === null) {
            $this->Flash->error('ID inválido.');

            return $this->redirect(['action' => 'index']);
        }

        $logRow = $this->emailLogService->getTable()
            ->find()
            ->where(['id' => (int)$id])
            ->first();

        if (!$logRow) {
            $this->Flash->error('No se encontró el registro de correo.');

            return $this->redirect(['action' => 'index']);
        }

        if (!$this->_canRetry($logRow)) {
            $this->Flash->error('No tiene permiso para reintentar este correo.');

            return $this->redirect($this->request->referer() ?: ['action' => 'index']);
        }

        $result = $this->emailLogService->retry((int)$id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Correo reenviado.');
        } else {
            $this->Flash->error($result->errors[0] ?? 'Reintento falló.');
        }

        return $this->redirect($this->request->referer() ?: ['action' => 'index']);
    }

    /**
     * Verifica si el usuario actual puede reintentar la fila $logRow.
     * Reusa los permisos de la entidad (invoices.can_edit / employee_novelties.can_edit)
     * para respetar el contexto del panel inline.
     */
    private function _canRetry(EmailLog $logRow): bool
    {
        $user = $this->Authentication->getIdentity()?->getOriginalData();
        if (!$user) {
            return false;
        }

        // Admin pasa siempre.
        $roleName = $this->_getUserRoleName($user);
        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            return true;
        }

        // Resto: depende de la entidad.
        $authorizationService = new AuthorizationService();
        $roleId = (int)($user->role_id ?? 0);

        if ($logRow->entity_type === EmailLogConstants::ENTITY_INVOICE) {
            return $authorizationService->isAllowed($roleId, $roleName, 'invoices', 'edit');
        }

        if ($logRow->entity_type === EmailLogConstants::ENTITY_NOVELTY) {
            return $authorizationService->isAllowed($roleId, $roleName, 'employee_novelties', 'edit');
        }

        // Sin entity_type → solo admin (que ya retornó arriba).
        return false;
    }
}
