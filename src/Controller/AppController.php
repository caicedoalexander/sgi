<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\AuthorizationService;
use App\Service\SidebarCounterService;
use Cake\Controller\Controller;
use Cake\Core\ContainerInterface;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use RuntimeException;

class AppController extends Controller
{
    private static ?ContainerInterface $appContainer = null;

    protected AuthorizationService $authService;

    protected SidebarCounterService $counterService;

    /**
     * Stash the application container so controllers can resolve services on demand.
     * Called once per request from Application::services().
     */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$appContainer = $container;
    }

    /**
     * Resolve the application container. Available to controllers and traits
     * (e.g. ExcelWizardTrait) that need to fetch services lazily.
     */
    public function getContainer(): ContainerInterface
    {
        if (self::$appContainer === null) {
            throw new RuntimeException('Container not initialized. Application::services() must run first.');
        }

        return self::$appContainer;
    }

    public function initialize(): void
    {
        parent::initialize();

        $this->authService = $this->getContainer()->get(AuthorizationService::class);
        $this->counterService = $this->getContainer()->get(SidebarCounterService::class);

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
    }

    /**
     * Map controller names to module keys used in permissions table.
     */
    protected array $controllerModuleMap = [
        'Invoices' => 'invoices',
        'Providers' => 'providers',
        'OperationCenters' => 'operation_centers',
        'ExpenseTypes' => 'expense_types',
        'CostCenters' => 'cost_centers',
        'Users' => 'users',
        'Roles' => 'roles',
        'Approvers' => 'approvers',
        'InvoiceHistories' => 'invoices',
        'Employees' => 'employees',
        'MaritalStatuses' => 'marital_statuses',
        'EducationLevels' => 'education_levels',
        'Positions' => 'positions',
        'DefaultFolders' => 'default_folders',
        'SystemSettings' => 'system_settings',
        'TemporaryOrganizations' => 'temporary_organizations',
        'DianCrosschecks' => 'dian_crosschecks',
        'EmployeeNovelties' => 'employee_novelties',
        'NoveltyDocuments' => 'employee_novelties',
        'NoveltyTypes' => 'novelty_types',
        'LeaveDocumentTemplates' => 'leave_document_templates',
        'PettyCashRecords' => 'petty_cash',
        'Refunds' => 'refunds',
        'NoveltyLiquidationDocs' => 'novelty_liquidation_docs',
        'BankingEntities' => 'banking_entities',
        'InvoicePayments' => 'invoices',
        'LiquidationDocPayments' => 'novelty_liquidation_docs',
        'PaymentSchedulings' => 'payment_schedulings',
        'PaymentRegistry' => 'payment_registry',
        'Advances' => 'advances',
        'EmailLogs' => 'email_logs',
    ];

    /**
     * Map CakePHP action names to permission actions.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view', 'export', 'exportConfig', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents', 'allEvents', 'legalization', 'downloadDocument' => 'view',
            'add', 'addFolder', 'uploadDocument', 'import', 'importExcel', 'importUpload', 'importProcess', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment', 'addPayment' => 'add',
            'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'regenerateApiKey', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'confirmPayment', 'confirmRefundPayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'linkCandidates', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion', 'retry', 'retryAllFailed' => 'edit',
            'delete', 'deleteDocument', 'removeItem', 'deleteAttachment' => 'delete',
            default => 'view',
        };
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $identity = $this->Authentication->getIdentity();

        // Pass current user to all views
        $this->set('currentUser', $identity?->getOriginalData());

        if ($identity) {
            $user = $identity->getOriginalData();
            $this->_setSidebarCounters($user);
            $this->_setUserPermissions($user);
            $this->_enforcePermission($user);
        }
    }

    /**
     * Calculate and pass user permissions to all views for sidebar filtering.
     *
     * Para Administrador, mergea ADMIN_BYPASS_MODULES con can_view/create/edit/delete=true
     * para que esos módulos sean siempre visibles en el sidebar aunque la BD
     * no tenga la fila correspondiente.
     */
    protected function _setUserPermissions(object $user): void
    {
        $roleName = $this->_getUserRoleName($user);
        $perms = $this->authService->getPermissionsForRoleAsMatrix((int)$user->role_id);

        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            foreach (AuthorizationService::ADMIN_BYPASS_MODULES as $module) {
                $perms[$module] = [
                    'can_view' => true,
                    'can_create' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                ];
            }
        }

        $this->set('userPermissions', $perms);
    }

    /**
     * Automatically enforce permissions based on current controller/action.
     */
    protected function _enforcePermission(object $user): void
    {
        $controllerName = $this->request->getParam('controller');
        $action = $this->request->getParam('action');

        // Skip controllers not in the permission map (Pages, Error, etc.)
        if (!isset($this->controllerModuleMap[$controllerName])) {
            return;
        }

        // Skip login/logout actions
        if ($controllerName === 'Users' && in_array($action, ['login', 'logout'])) {
            return;
        }

        // EmailLogs::retry valida permisos internamente (delega a invoices.can_edit
        // o employee_novelties.can_edit según entity_type). Saltarse el check de
        // módulo aquí para no bloquear a usuarios no-admin desde el panel inline.
        if ($controllerName === 'EmailLogs' && $action === 'retry') {
            return;
        }

        $module = $this->controllerModuleMap[$controllerName];
        $permAction = $this->_actionToPermission($action);

        if (!$this->_checkPermission($module, $permAction)) {
            throw new ForbiddenException(
                sprintf('No tiene permisos para %s en %s.', $permAction, $module),
            );
        }
    }

    /**
     * Get the role name for a user from session/entity data.
     */
    protected function _getUserRoleName(object $user): string
    {
        return $user?->role?->name ?? '';
    }

    /**
     * Construye condiciones de filtro por `pipeline_status` (o columna análoga)
     * para los listados "Mis Registros" de los módulos con pipeline.
     *
     * Si la lista de estados visibles está vacía (rol sin permisos sembrados),
     * retorna una condición imposible (`1 = 0`) para garantizar 0 resultados.
     * Centraliza el patrón usado por Invoices/Refunds/PettyCash/etc. y evita
     * el anti-pattern de valores centinela mágicos.
     *
     * @param string $field Columna calificada, ej. `Invoices.pipeline_status`.
     * @param array<int, string> $statuses Lista de estados visibles para el rol.
     * @return array<string|int, mixed> Condiciones para aplicar en `$query->where(...)`.
     */
    protected function _visibleStatusConditions(string $field, array $statuses): array
    {
        if ($statuses === []) {
            return ['1 = 0'];
        }

        return [$field . ' IN' => $statuses];
    }

    protected function _setSidebarCounters(object $user): void
    {
        $roleName = $this->_getUserRoleName($user);
        $counters = $this->counterService->getCounters($roleName);

        foreach ($counters as $key => $value) {
            $this->set($key, $value);
        }
    }

    protected function _requireAuth(): void
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            $this->Flash->error('Debe iniciar sesión para acceder.');
            $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }
    }

    protected function _checkPermission(string $module, string $action): bool
    {
        $identity = $this->Authentication->getIdentity();
        if (!$identity) {
            return false;
        }

        $user = $identity->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        return $this->authService->isAllowed((int)$user->role_id, $roleName, $module, $action);
    }

    protected function _isJsonRequest(): bool
    {
        return $this->request->is('ajax')
            || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    protected function _jsonResponse(array $data, int $status = 200): Response
    {
        $this->autoRender = false;

        return $this->response
            ->withType('application/json')
            ->withStatus($status)
            ->withStringBody(json_encode($data));
    }

    /**
     * Returns a redirect to the controller that owns the user-facing flow for
     * the given invoice. Anticipos live under /advances; everything else lives
     * under /invoices.
     */
    protected function _redirectForInvoice(
        int|Invoice $invoiceOrId,
        string $action,
        mixed ...$args,
    ): Response {
        $invoice = $invoiceOrId instanceof Invoice
            ? $invoiceOrId
            : $this->fetchTable('Invoices')->get($invoiceOrId);

        $controller = $invoice->document_type === InvoiceConstants::DOCTYPE_ANTICIPO
            ? 'Advances'
            : 'Invoices';

        return $this->redirect(['controller' => $controller, 'action' => $action, ...$args]);
    }

    /**
     * Optimistic concurrency guard para acciones que avanzan estado del pipeline.
     *
     * Compara el `expected_status` enviado por el form con el estado actual de la
     * entidad. Si difieren (otra pestaña ya avanzó, o el usuario hizo back+resubmit),
     * flash error y devuelve false; el caller hace `return $this->redirect(...)`.
     *
     * @param string $current Estado actual de la entidad.
     * @param string $errorMessage Mensaje de flash si no coincide.
     * @return bool true si el guard pasa, false si falló (caller debe redirect).
     */
    protected function _ensureExpectedStatus(
        string $current,
        string $errorMessage = 'El registro cambió de estado. Recargue la página antes de avanzar.',
    ): bool {
        $expected = (string)$this->request->getData('expected_status', '');
        if ($expected !== '' && $expected !== $current) {
            $this->Flash->error($errorMessage);

            return false;
        }

        return true;
    }
}
