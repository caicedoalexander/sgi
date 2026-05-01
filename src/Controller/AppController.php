<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\AuthorizationService;
use App\Service\SidebarCounterService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Response;

class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();

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
        'EmployeeStatuses' => 'employee_statuses',
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
            'index', 'view', 'export', 'exportConfig', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents', 'allEvents', 'legalization' => 'view',
            'add', 'addFolder', 'uploadDocument', 'import', 'importExcel', 'importUpload', 'importProcess', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment', 'addPayment' => 'add',
            'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion', 'retry', 'retryAllFailed' => 'edit',
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
     */
    protected function _setUserPermissions(object $user): void
    {
        $roleName = $this->_getUserRoleName($user);

        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            // Admin sees everything
            $perms = [];
            foreach (array_keys(AuthorizationService::MODULES) as $module) {
                $perms[$module] = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true];
            }
            $this->set('userPermissions', $perms);

            return;
        }

        $authService = new AuthorizationService();
        $this->set('userPermissions', $authService->getPermissionsForRoleAsMatrix((int)$user->role_id));
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

        $module = $this->controllerModuleMap[$controllerName];
        $permAction = $this->_actionToPermission($action);

        if (!$this->_checkPermission($module, $permAction)) {
            $this->Flash->error('No tiene permisos para acceder a esta función.');
            // Avoid redirect loop: if already on dashboard, redirect to login
            if ($controllerName === 'Dashboard' && $action === 'index') {
                $this->redirect(['controller' => 'Users', 'action' => 'login']);
            } else {
                $this->redirect($this->request->referer() ?: ['controller' => 'Dashboard', 'action' => 'index']);
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

    protected function _setSidebarCounters(object $user): void
    {
        $roleName = $this->_getUserRoleName($user);
        $counterService = new SidebarCounterService();
        $counters = $counterService->getCounters($roleName);

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

        // Admin always allowed
        if ($roleName === AuthorizationService::ROLE_ADMIN) {
            return true;
        }

        $authService = new AuthorizationService();

        return $authService->isAllowed((int)$user->role_id, $roleName, $module, $action);
    }

    protected function _isJsonRequest(): bool
    {
        return $this->request->is('ajax')
            || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    protected function _jsonResponse(array $data): Response
    {
        $this->autoRender = false;

        return $this->response
            ->withType('application/json')
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
}
