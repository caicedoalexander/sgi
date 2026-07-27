<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Constants\ApprovalInboxConstants;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\InvoiceApprovalService;
use App\Service\NoveltyApprovalService;
use App\View\Presentation\ApprovalInboxPresentation;
use App\ViewModel\ApprovalViewViewModel;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;

/**
 * Bandeja personal "Mis Aprobaciones" (consulta + acceso autenticado al panel de
 * aprobación). El acceso se gatea por pertenencia al catálogo `approvers` (no por
 * RBAC de rol), de ahí #[NoAuthGate] + gate custom interno. Usa $this->inboxService
 * heredado de AppController.
 */
class ApprovalsController extends AppController
{
    private InvoiceApprovalService $invoiceApprovalService;

    private NoveltyApprovalService $noveltyApprovalService;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->invoiceApprovalService = $this->getContainer()->get(InvoiceApprovalService::class);
        $this->noveltyApprovalService = $this->getContainer()->get(NoveltyApprovalService::class);
    }

    /**
     * Lista las aprobaciones pendientes del usuario.
     *
     * @return void
     */
    #[NoAuthGate(reason: 'Acceso gateado por pertenencia al catálogo approvers, no por RBAC de módulo')]
    public function index(): void
    {
        $userId = $this->_currentUserId();
        $this->_assertIsApprover($userId);

        $statusQuery = $this->request->getQuery('status');
        $moduleQuery = $this->request->getQuery('module');
        $status = in_array($statusQuery, ApprovalInboxConstants::FILTERABLE_STATUSES, true) ? $statusQuery : null;
        $module = in_array($moduleQuery, [ApprovalInboxConstants::MODULE_INVOICE, ApprovalInboxConstants::MODULE_NOVELTY], true) ? $moduleQuery : null;
        $search = $this->request->getQuery('q');
        $page = max(1, (int)$this->request->getQuery('page'));

        $result = $this->inboxService->getInbox($userId, $status, $module, $search, $page);

        $this->set([
            'rows' => array_map(
                [ApprovalInboxPresentation::class, 'forRow'],
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'activeStatus' => $status,
            'activeModule' => $module,
            'search' => $search,
        ]);
    }

    /**
     * Muestra el detalle de una entidad pendiente de aprobación.
     *
     * @param string $module Slug del módulo.
     * @param int $id ID de la entidad.
     * @return void
     */
    #[NoAuthGate(reason: 'Acceso gateado por pertenencia al catálogo approvers + gate de propiedad del item')]
    public function view(string $module, int $id): void
    {
        $userId = $this->_currentUserId();
        $this->_assertIsApprover($userId);

        $found = $this->inboxService->getItemForUser($userId, $module, $id);
        if ($found === null) {
            // 404 deliberado también para ítems ajenos: no revela la existencia de
            // aprobaciones de otros aprobadores (evita enumeración de recursos).
            throw new NotFoundException('Aprobación no encontrada o no disponible.');
        }

        $viewModel = new ApprovalViewViewModel(
            module: $module,
            entity: $found['entity'],
            status: $found['status'],
            observations: $found['observations'],
        );

        $this->set('viewModel', $viewModel);
    }

    /**
     * Acuña un token fresco para la aprobación PENDIENTE del usuario y redirige al
     * panel /approve existente (que revalida sesión e identidad). POST: es un
     * cambio de estado (regenera el token), evita minteo por prefetch.
     *
     * @return \Cake\Http\Response|null
     */
    #[NoAuthGate(reason: 'Acceso gateado por approvers + gate de propiedad/pendiente al acuñar el token')]
    public function approve(string $module, int $id)
    {
        $this->request->allowMethod(['post']);
        $userId = $this->_currentUserId();
        $this->_assertIsApprover($userId);

        $secret = match ($module) {
            ApprovalInboxConstants::MODULE_INVOICE => $this->invoiceApprovalService->regeneratePendingToken($id, $userId),
            ApprovalInboxConstants::MODULE_NOVELTY => $this->noveltyApprovalService->issueApprovalToken($id, $userId),
            default => throw new NotFoundException('Módulo de aprobación desconocido.'),
        };

        if ($secret === null) {
            $this->Flash->error('Esta aprobación ya no está pendiente o no está disponible.');

            return $this->redirect(['action' => 'view', $module, $id]);
        }

        return $this->redirect(ApprovalUrlBuilder::fromRequest($this->request, $secret));
    }

    /**
     * Verifica que el usuario sea aprobador.
     *
     * @param int $userId ID del usuario.
     * @return void
     */
    private function _assertIsApprover(int $userId): void
    {
        if (!$this->inboxService->isApprover($userId)) {
            throw new ForbiddenException('No está habilitado como aprobador.');
        }
    }

    /**
     * Obtiene el ID del usuario autenticado.
     *
     * @return int
     */
    private function _currentUserId(): int
    {
        return (int)$this->Authentication->getIdentity()->getOriginalData()->id;
    }
}
