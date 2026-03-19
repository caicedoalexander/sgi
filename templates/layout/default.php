<?php
/**
 * @var \App\View\AppView $this
 * @var object|null $currentUser
 * @var array $sidebarCounters
 * @var array $userPermissions
 */
$sidebarCounters = $sidebarCounters ?? [];
$currentUser = $currentUser ?? null;
$userPermissions = $userPermissions ?? [];
$currentController = $this->request->getParam('controller');
$rejectedInvoicesCount = $rejectedInvoicesCount ?? 0;
$pettyCashCount = $pettyCashCount ?? 0;
$noveltiesCount = $noveltiesCount ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?> | SGI · COPCSA</title>
    <link rel="icon" type="image/png" href="<?= $this->Url->build('/img/copcsa.png') ?>">
    <!-- Bootstrap primero, luego nuestros estilos para poder sobreescribir -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <?= $this->Html->css('styles') ?>
    <?php if ($this->request->getAttribute('csrfToken')): ?>
    <meta name="csrfToken" content="<?= $this->request->getAttribute('csrfToken') ?>">
    <?php endif; ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <style>
        :root { --sidebar-width: 260px; }
        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: max-content;
            min-width: 200px;
            overflow-y: hidden;
            z-index: 1030;
            display: flex;
            flex-direction: column;
        }
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        .content-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <nav class="sidebar d-flex flex-column flex-shrink-0 p-3 bg-dark">

            <!-- Logo -->
            <?= $this->element('sgi_logo') ?>

            <!-- Divisor -->
            <div style="height:1px;background:rgba(255,255,255,.07);margin-bottom:.75rem;"></div>

            <?php
            $canView = function (string $module) use ($userPermissions): bool {
                return !empty($userPermissions[$module]['can_view']);
            };
            $currentAction = $this->request->getParam('action');
            $navLink = function (string $controller, ?string $action = null) use ($currentController, $currentAction): string {
                $match = $currentController === $controller;
                if ($action !== null) {
                    $match = $match && $currentAction === $action;
                }
                return 'nav-link' . ($match ? ' active' : '');
            };
            ?>

            <div class="sidebar-nav">
            <ul class="nav nav-pills flex-column mb-3">
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-house-door me-2"></i>Inicio',
                        ['controller' => 'Dashboard', 'action' => 'index'],
                        ['class' => $navLink('Dashboard'), 'escape' => false]
                    ) ?>
                </li>

                <?php
                $facturacionItems = array_filter([
                    $canView('invoices') ? 'invoices' : null,
                    $canView('novelty_liquidation_docs') ? 'novelty_liquidation_docs' : null,
                ]);
                $facturacionSubActive = in_array($this->request->getParam('controller'), ['Invoices', 'PettyCashRecords']);
                if (!empty($facturacionItems)): ?>
                <li class="nav-heading">Facturación</li>
                <?php if ($canView('invoices')): ?>
                <li class="nav-item sidebar-has-submenu">
                    <div class="sidebar-collapsible-header">
                        <?= $this->Html->link(
                            '<i class="bi bi-receipt-cutoff me-2"></i><span class="flex-grow-1">Todas las Facturas</span>',
                            ['controller' => 'Invoices', 'action' => 'all'],
                            ['class' => $navLink('Invoices', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false]
                        ) ?>
                        <button class="sidebar-chevron-btn"
                                data-bs-toggle="collapse"
                                data-bs-target="#facturacion-submenu"
                                aria-expanded="<?= $facturacionSubActive ? 'true' : 'false' ?>"
                                aria-controls="facturacion-submenu">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                    <div class="collapse<?= $facturacionSubActive ? ' show' : '' ?>"
                         id="facturacion-submenu">
                        <ul class="sidebar-submenu">
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-receipt me-2"></i>Mis Facturas' .
                                    (!empty($sidebarCounters) ? ' <span class="badge bg-success sidebar-badge ms-auto">' . array_sum($sidebarCounters) . '</span>' : ''),
                                    ['controller' => 'Invoices', 'action' => 'index'],
                                    ['class' => $navLink('Invoices', 'index') . ' d-flex align-items-center', 'escape' => false]
                                ) ?>
                            </li>
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-x-circle me-2"></i>Facturas Rechazadas' .
                                    ($rejectedInvoicesCount > 0 ? ' <span class="badge bg-danger sidebar-badge ms-auto">' . $rejectedInvoicesCount . '</span>' : ''),
                                    ['controller' => 'Invoices', 'action' => 'rejected'],
                                    ['class' => $navLink('Invoices', 'rejected') . ' d-flex align-items-center', 'escape' => false]
                                ) ?>
                            </li>
                            <?php if ($canView('petty_cash')): ?>
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-wallet2 me-2"></i>Caja Menor' .
                                    ($pettyCashCount > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $pettyCashCount . '</span>' : ''),
                                    ['controller' => 'PettyCashRecords', 'action' => 'index'],
                                    ['class' => $navLink('PettyCashRecords') . ' d-flex align-items-center', 'escape' => false]
                                ) ?>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>
                <?php endif; ?>
                <?php if ($canView('novelty_liquidation_docs')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-file-earmark-text me-2"></i>Docs. Liquidación',
                        ['controller' => 'NoveltyLiquidationDocs', 'action' => 'index'],
                        ['class' => $navLink('NoveltyLiquidationDocs') . ' d-flex align-items-center', 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                $rrhhItems = array_filter([
                    $canView('employees') ? 'employees' : null,
                    $canView('employee_novelties') ? 'employee_novelties' : null,
                ]);
                if (!empty($rrhhItems)): ?>
                <li class="nav-heading">RRHH</li>
                <?php if ($canView('employees')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-people-fill me-2"></i>Empleados',
                        ['controller' => 'Employees', 'action' => 'index'],
                        ['class' => $navLink('Employees'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('employee_novelties')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-journal-text me-2"></i>Novedades' .
                        ($noveltiesCount > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $noveltiesCount . '</span>' : ''),
                        ['controller' => 'EmployeeNovelties', 'action' => 'index'],
                        ['class' => $navLink('EmployeeNovelties') . ' d-flex align-items-center', 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                $catalogoItems = array_filter([
                    $canView('approvers') ? 'approvers' : null,
                    $canView('providers') ? 'providers' : null,
                    $canView('operation_centers') ? 'operation_centers' : null,
                    $canView('expense_types') ? 'expense_types' : null,
                    $canView('cost_centers') ? 'cost_centers' : null,
                    $canView('positions') ? 'positions' : null,
                    $canView('employee_statuses') ? 'employee_statuses' : null,
                    $canView('marital_statuses') ? 'marital_statuses' : null,
                    $canView('education_levels') ? 'education_levels' : null,
                    $canView('default_folders') ? 'default_folders' : null,
                    $canView('novelty_types') ? 'novelty_types' : null,
                    $canView('temporary_organizations') ? 'temporary_organizations' : null,
                    $canView('leave_document_templates') ? 'leave_document_templates' : null,
                ]);
                if (!empty($catalogoItems)): ?>
                <li class="nav-heading">Catálogos</li>
                <?php if ($canView('approvers')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-person-check me-2"></i>Aprobadores',
                        ['controller' => 'Approvers', 'action' => 'index'],
                        ['class' => $navLink('Approvers'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('providers')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-truck me-2"></i>Proveedores',
                        ['controller' => 'Providers', 'action' => 'index'],
                        ['class' => $navLink('Providers'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('operation_centers')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-geo-alt me-2"></i>Centros de Operación',
                        ['controller' => 'OperationCenters', 'action' => 'index'],
                        ['class' => $navLink('OperationCenters'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('expense_types')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-tags me-2"></i>Tipos de Gasto',
                        ['controller' => 'ExpenseTypes', 'action' => 'index'],
                        ['class' => $navLink('ExpenseTypes'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('cost_centers')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-diagram-3 me-2"></i>Centros de Costos',
                        ['controller' => 'CostCenters', 'action' => 'index'],
                        ['class' => $navLink('CostCenters'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('positions')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-briefcase me-2"></i>Cargos',
                        ['controller' => 'Positions', 'action' => 'index'],
                        ['class' => $navLink('Positions'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('employee_statuses')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-card-checklist me-2"></i>Estados de Empleado',
                        ['controller' => 'EmployeeStatuses', 'action' => 'index'],
                        ['class' => $navLink('EmployeeStatuses'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('marital_statuses')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-heart me-2"></i>Estados Civiles',
                        ['controller' => 'MaritalStatuses', 'action' => 'index'],
                        ['class' => $navLink('MaritalStatuses'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('education_levels')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-mortarboard me-2"></i>Niveles Educativos',
                        ['controller' => 'EducationLevels', 'action' => 'index'],
                        ['class' => $navLink('EducationLevels'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('default_folders')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-folder me-2"></i>Carpetas por Defecto',
                        ['controller' => 'DefaultFolders', 'action' => 'index'],
                        ['class' => $navLink('DefaultFolders'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('novelty_types')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-list-check me-2"></i>Tipos de Novedad',
                        ['controller' => 'NoveltyTypes', 'action' => 'index'],
                        ['class' => $navLink('NoveltyTypes'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('temporary_organizations')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-building-gear me-2"></i>Org. Temporales',
                        ['controller' => 'TemporaryOrganizations', 'action' => 'index'],
                        ['class' => $navLink('TemporaryOrganizations'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('leave_document_templates')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-file-earmark-ruled me-2"></i>Plantillas Documento',
                        ['controller' => 'LeaveDocumentTemplates', 'action' => 'index'],
                        ['class' => $navLink('LeaveDocumentTemplates'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                $adminItems = array_filter([
                    $canView('users') ? 'users' : null,
                    $canView('roles') ? 'roles' : null,
                    $canView('system_settings') ? 'system_settings' : null,
                ]);
                if (!empty($adminItems)): ?>
                <li class="nav-heading">Administración</li>
                <?php if ($canView('users')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-people me-2"></i>Usuarios',
                        ['controller' => 'Users', 'action' => 'index'],
                        ['class' => $navLink('Users'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('roles')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-shield-lock me-2"></i>Roles',
                        ['controller' => 'Roles', 'action' => 'index'],
                        ['class' => $navLink('Roles'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('system_settings')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-gear me-2"></i>Configuración',
                        ['controller' => 'SystemSettings', 'action' => 'index'],
                        ['class' => $navLink('SystemSettings'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php endif; ?>
            </ul>
            </div>

            <!-- Footer de usuario -->
            <div class="sidebar-footer d-flex align-items-center justify-content-between">
                <?php if ($currentUser): ?>
                    <div class="d-flex align-items-center" style="min-width:0;">
                        <div class="d-flex align-items-center justify-content-center me-2"
                             style="width:32px;height:32px;background-color:var(--primary-color);flex-shrink:0;">
                            <i class="bi bi-person text-white" style="font-size:.95rem;"></i>
                        </div>
                        <div class="overflow-hidden">
                            <div class="text-white fw-medium text-truncate" style="font-size:.82rem;"><?= h($currentUser->full_name) ?></div>
                            <div style="font-size:.7rem;color:rgba(255,255,255,.35);"><?= h($currentUser->role->name ?? '') ?></div>
                        </div>
                    </div>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-right"></i>',
                        ['controller' => 'Users', 'action' => 'logout'],
                        ['class' => 'sgi-sidebar-logout', 'escape' => false]
                    ) ?>
                <?php endif; ?>
            </div>
        </nav>
        <script>(function(){var s=document.querySelector('.sidebar');if(s)document.documentElement.style.setProperty('--sidebar-width',s.offsetWidth+'px');})();</script>

        <!-- Contenido -->
        <?php
        $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
        $now   = new \DateTime();
        $topbarDate = $dias[(int)$now->format('w')] . ', ' . $now->format('d') . ' de ' . $meses[(int)$now->format('n')] . ' del ' . $now->format('Y');
        ?>
        <div class="content-wrapper flex-grow-1">
            <nav class="sgi-topbar sticky-top d-flex align-items-center justify-content-between px-4">
                <span class="sgi-topbar-title"><?= $this->fetch('title') ?></span>
                <div class="sgi-topbar-date d-none d-md-flex align-items-center gap-2">
                    <i class="bi bi-calendar3" style="font-size:.75rem"></i>
                    <?= $topbarDate ?>
                </div>
            </nav>
            <main class="p-4">
                <?= $this->fetch('content') ?>
            </main>
        </div>
    </div>

    <!-- Flash notifications fijas -->
    <div id="sgi-flash-container">
        <?= $this->Flash->render() ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/es.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/autonumeric@4.10.5/dist/autoNumeric.min.js"></script>
    <?= $this->Html->script('sgi-common', ['block' => false]) ?>
    <?= $this->fetch('script') ?>
</body>
</html>