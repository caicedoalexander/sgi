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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SGI - <?= $this->fetch('title') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $this->Url->build('/favicon.svg') ?>">
    <link rel="icon" type="image/x-icon" href="<?= $this->Url->build('/favicon.ico') ?>">
    <!-- Bootstrap primero, luego nuestros estilos para poder sobreescribir -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <?= $this->Html->css('styles') ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <style>
        :root { --sidebar-width: 260px; }
        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: var(--sidebar-width);
            overflow-y: auto;
            z-index: 100;
            display: flex;
            flex-direction: column;
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
            <a href="<?= $this->Url->build('/') ?>" class="d-flex align-items-center mb-3 text-white text-decoration-none">
                <div class="d-flex align-items-center justify-content-center me-2"
                     style="width:36px;height:36px;background-color:var(--primary-color);flex-shrink:0;">
                    <i class="bi bi-building text-white" style="font-size:1rem;"></i>
                </div>
                <div>
                    <div class="fw-bold text-white lh-1" style="font-size:1.05rem;letter-spacing:-.02em;">SGI</div>
                    <div style="font-size:.55rem;letter-spacing:.1em;color:rgba(255,255,255,.3);text-transform:uppercase;margin-top:3px;">Sistema de Gestión Interna</div>
                </div>
            </a>

            <!-- Divisor -->
            <div style="height:1px;background:rgba(255,255,255,.07);margin-bottom:.75rem;"></div>

            <?php
            $canView = function (string $module) use ($userPermissions): bool {
                return !empty($userPermissions[$module]['can_view']);
            };
            $navLink = function (string $controller) use ($currentController): string {
                return 'nav-link' . ($currentController === $controller ? ' active' : '');
            };
            ?>

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
                    $canView('approvers') ? 'approvers' : null,
                ]);
                if (!empty($facturacionItems)): ?>
                <li class="nav-heading">Facturación</li>
                <?php if ($canView('invoices')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-receipt me-2"></i>Facturas' .
                        (!empty($sidebarCounters) ? ' <span class="badge bg-success sidebar-badge ms-auto">' . array_sum($sidebarCounters) . '</span>' : ''),
                        ['controller' => 'Invoices', 'action' => 'index'],
                        ['class' => $navLink('Invoices') . ' d-flex align-items-center', 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php if ($canView('approvers')): ?>
                <li class="nav-item">
                    <?= $this->Html->link(
                        '<i class="bi bi-person-check me-2"></i>Aprobadores',
                        ['controller' => 'Approvers', 'action' => 'index'],
                        ['class' => $navLink('Approvers'), 'escape' => false]
                    ) ?>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                $rrhhItems = array_filter([
                    $canView('employees') ? 'employees' : null,
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
                <?php endif; ?>

                <?php
                $catalogoItems = array_filter([
                    $canView('providers') ? 'providers' : null,
                    $canView('operation_centers') ? 'operation_centers' : null,
                    $canView('expense_types') ? 'expense_types' : null,
                    $canView('cost_centers') ? 'cost_centers' : null,
                    $canView('positions') ? 'positions' : null,
                    $canView('employee_statuses') ? 'employee_statuses' : null,
                    $canView('marital_statuses') ? 'marital_statuses' : null,
                    $canView('education_levels') ? 'education_levels' : null,
                    $canView('default_folders') ? 'default_folders' : null,
                ]);
                if (!empty($catalogoItems)): ?>
                <li class="nav-heading">Catálogos</li>
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
                <?php endif; ?>

                <?php
                $adminItems = array_filter([
                    $canView('users') ? 'users' : null,
                    $canView('roles') ? 'roles' : null,
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
                <?php endif; ?>
            </ul>

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

        <!-- Contenido -->
        <?php
        $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        $dias  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        $now   = new \DateTime();
        $topbarDate = $dias[(int)$now->format('w')] . ' ' . $now->format('d') . ' ' . $meses[(int)$now->format('n')] . ' ' . $now->format('Y');
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

    <?= $this->element('copcsa') ?>

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