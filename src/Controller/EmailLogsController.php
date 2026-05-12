<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
use App\Authorization\CrudAction;
use App\Constants\EmailLogConstants;
use App\Model\Entity\EmailLog;
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

    public function initialize(): void
    {
        parent::initialize();
        $this->emailLogService = $this->getContainer()->get(EmailLogService::class);
    }

    /**
     * Listado paginado de logs de correo con filtros (status, event_type, fechas, destinatario).
     *
     * @return void
     */
    #[Permission(action: 'view')]
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
     *   - Si pertenece a una factura: requiere invoices.can_edit.
     *   - Si pertenece a una novedad: requiere employee_novelties.can_edit.
     *
     * @param string|null $id ID del email log a reintentar.
     * @return \Cake\Http\Response
     */
    #[NoAuthGate(reason: 'Permission delegated internally to entity-specific module (invoices.can_edit or employee_novelties.can_edit) via _canRetry()')]
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
        $context = $this->_userContext();
        if ($context === null) {
            return false;
        }

        if ($logRow->entity_type === EmailLogConstants::ENTITY_INVOICE) {
            return $this->authFacade->canCrud($context, 'invoices', CrudAction::Edit);
        }

        if ($logRow->entity_type === EmailLogConstants::ENTITY_NOVELTY) {
            return $this->authFacade->canCrud($context, 'employee_novelties', CrudAction::Edit);
        }

        return false;
    }

    /**
     * Reintento masivo. Solo Administrador (gated por _enforcePermission con
     * email_logs.can_edit, que solo está asignado al admin).
     *
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'edit')]
    public function retryAllFailed(): Response
    {
        $this->request->allowMethod(['post']);

        $stats = $this->emailLogService->retryAllFailed();

        if ($stats['success'] === 0 && $stats['failed'] === 0) {
            $this->Flash->info('No había correos fallidos para reintentar.');
        } else {
            $msg = sprintf(
                'Reintentos: %d exitosos, %d fallidos.',
                $stats['success'],
                $stats['failed'],
            );
            if ($stats['failed'] > 0) {
                $this->Flash->warning($msg);
            } else {
                $this->Flash->success($msg);
            }
        }

        return $this->redirect(['action' => 'index']);
    }
}
