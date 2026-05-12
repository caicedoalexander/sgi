<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\AuthorizationService;
use App\Service\PipelineAuthorizationService;
use App\Service\SidebarCounterService;
use Cake\Controller\Controller;
use Cake\Core\ContainerInterface;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use LogicException;
use ReflectionMethod;
use RuntimeException;

class AppController extends Controller
{
    private static ?ContainerInterface $appContainer = null;

    protected AuthorizationService $authService;

    protected SidebarCounterService $counterService;

    protected PipelineAuthorizationService $pipelineAuth;

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
        $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
    }

    /**
     * Map controller names to module keys used in permissions table.
     */
    /**
     * Acciones que son operaciones de pipeline-step. Cada controlador con flujo
     * de pipeline puede sobreescribir esta lista para indicar qué acciones se
     * autorizan exclusivamente por `pipeline_permissions` (rol×paso) y NO por
     * el CRUD del módulo. El control de acceso fino lo hace el `actionPolicy`
     * del controlador; aquí solo se desacopla del chequeo CRUD obligatorio.
     *
     * Las acciones de entrada (view/index) y de mutación CRUD pura (add/edit/
     * delete) siguen pasando por el chequeo del módulo.
     *
     * @var array<int, string>
     */
    protected array $pipelineActions = [];

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
            'index', 'view', 'export', 'exportConfig', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents', 'allEvents', 'legalization', 'downloadDocument', 'pendingLegalization', 'overdue', 'pending' => 'view',
            'add', 'addFolder', 'uploadDocument', 'import', 'importExcel', 'importUpload', 'importProcess', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment', 'addPayment', 'uploadLiquidationDocument' => 'add',
            'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'regenerateApiKey', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'confirmPayment', 'confirmRefundPayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'linkCandidates', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion', 'retry', 'retryAllFailed', 'resendApproval', 'updateLiquidationDocument' => 'edit',
            'delete', 'deleteDocument', 'removeItem', 'deleteAttachment' => 'delete',
            default => throw new LogicException(sprintf(
                "Action '%s' has no permission mapping in AppController::_actionToPermission(). " .
                'Register it explicitly in the match, add it to the controller\'s $pipelineActions, ' .
                'or extend the bypass in _enforcePermission().',
                $action,
            )),
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
     * Aplica el gate de permisos según el atributo del método de la acción.
     *
     * Flujo:
     *  1. Resolver el método del controller actual.
     *  2. Buscar uno de los 3 atributos (#[NoAuthGate], #[Permission], #[PipelineAction]).
     *  3. Si hay atributo, aplicar su regla.
     *  4. Si no hay atributo, caer al fallback legacy (controllerModuleMap +
     *     _actionToPermission + $pipelineActions) — se eliminará en commit 6.
     */
    protected function _enforcePermission(object $user): void
    {
        $action = $this->request->getParam('action');

        $attribute = $this->_resolveAuthAttribute($action);
        if ($attribute !== null) {
            $this->_applyAuthAttribute($user, $attribute);

            return;
        }

        // ─── Fallback legacy — eliminar en commit 6 ────────────────────────
        $controllerName = $this->request->getParam('controller');

        if (!isset($this->controllerModuleMap[$controllerName])) {
            return;
        }

        if ($controllerName === 'Users' && in_array($action, ['login', 'logout'], true)) {
            return;
        }

        if ($controllerName === 'EmailLogs' && $action === 'retry') {
            return;
        }

        if (in_array($action, $this->pipelineActions, true)) {
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
     * Resuelve el atributo de autorización del método de la acción actual.
     *
     * @param string $action Nombre de la acción del controller.
     * @return \App\Attribute\Permission|\App\Attribute\PipelineAction|\App\Attribute\NoAuthGate|null
     */
    private function _resolveAuthAttribute(string $action): Permission|PipelineAction|NoAuthGate|null
    {
        if (!method_exists($this, $action)) {
            return null;
        }

        $method = new ReflectionMethod($this, $action);

        foreach ([NoAuthGate::class, Permission::class, PipelineAction::class] as $attrClass) {
            $attrs = $method->getAttributes($attrClass);
            if ($attrs !== []) {
                return $attrs[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * Aplica la regla del atributo resuelto.
     *
     * @param object $user Usuario autenticado (entity con role_id).
     * @param object $attribute \App\Attribute\Permission|\App\Attribute\PipelineAction|\App\Attribute\NoAuthGate.
     */
    private function _applyAuthAttribute(object $user, object $attribute): void
    {
        if ($attribute instanceof NoAuthGate) {
            return;
        }

        if ($attribute instanceof Permission) {
            $controllerName = $this->request->getParam('controller');
            $module = $this->controllerModuleMap[$controllerName] ?? null;
            if ($module === null) {
                throw new LogicException(sprintf(
                    "Controller '%s' has #[Permission] but no entry in \$controllerModuleMap.",
                    $controllerName,
                ));
            }

            if (!$this->_checkPermission($module, $attribute->action)) {
                throw new ForbiddenException(
                    sprintf('No tiene permisos para %s en %s.', $attribute->action, $module),
                );
            }

            return;
        }

        if ($attribute instanceof PipelineAction) {
            if ($attribute->step === null) {
                // Acción dinámica — el método decide vía canOperate inline o
                // denialReasonForAdvance. Solo se salta el gate CRUD.
                return;
            }

            $roleId = (int)$user->role_id;
            if (!$this->pipelineAuth->canOperate($roleId, $attribute->pipeline, $attribute->step)) {
                throw new ForbiddenException(
                    sprintf(
                        'No tiene permisos para operar el paso "%s" del pipeline "%s".',
                        $attribute->step,
                        $attribute->pipeline,
                    ),
                );
            }
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
        $counters = $this->counterService->getCounters((int)$user->role_id);

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
