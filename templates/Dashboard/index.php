<?php
/**
 * @var \App\View\AppView $this
 * @var array $counters
 * @var array $userPermissions
 * @var object|null $currentUser
 */
$this->assign('title', 'Inicio');
$userPermissions = $userPermissions ?? [];
$counters = $counters ?? [];

$canView = function (string $module) use ($userPermissions): bool {
    return !empty($userPermissions[$module]['can_view']);
};

$dias   = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses  = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
           'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$hoy    = new DateTime();
$fecha  = $dias[$hoy->format('w')] . ', ' . $hoy->format('j') . ' de ' . $meses[(int)$hoy->format('n') - 1] . ' de ' . $hoy->format('Y');
?>

<!-- Encabezado -->
<div class="mb-5">
    <p class="mb-1 text-uppercase fw-semibold"
       style="font-size:.65rem;letter-spacing:.14em;color:var(--primary-color);">
        Compañía Operadora Portuaria Cafetera S.A.
    </p>
    <span class="sgi-page-title" style="font-size:1.8rem">
        <?= $currentUser ? 'Bienvenido, ' . h($currentUser->full_name) : 'Bienvenido' ?>
    </span>
    <p class="mb-0 text-muted" style="font-size:.82rem;"><?= $fecha ?></p>
</div>

<!-- Contadores principales -->
<div class="row g-3 mb-5">

    <?php if ($canView('invoices')): ?>
    <div class="col-3">
        <a href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'index']) ?>"
           class="d-block text-decoration-none">
            <div class="sgi-stat-card p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-uppercase fw-semibold"
                          style="font-size:.6rem;letter-spacing:.12em;color:#aaa;">Facturas</span>
                    <i class="bi bi-receipt" style="color:var(--primary-color);font-size:1rem;opacity:.8;"></i>
                </div>
                <div class="fw-bold lh-1" style="font-size:2.4rem;letter-spacing:-.05em;color:#111;">
                    <?= $this->Number->format($counters['invoices'] ?? 0) ?>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canView('employees')): ?>
    <div class="col-3">
        <a href="<?= $this->Url->build(['controller' => 'Employees', 'action' => 'index']) ?>"
           class="d-block text-decoration-none">
            <div class="sgi-stat-card p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-uppercase fw-semibold"
                          style="font-size:.6rem;letter-spacing:.12em;color:#aaa;">Empleados Activos</span>
                    <i class="bi bi-people-fill" style="color:var(--primary-color);font-size:1rem;opacity:.8;"></i>
                </div>
                <div class="fw-bold lh-1" style="font-size:2.4rem;letter-spacing:-.05em;color:#111;">
                    <?= $this->Number->format($counters['employees'] ?? 0) ?>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canView('providers')): ?>
    <div class="col-3">
        <a href="<?= $this->Url->build(['controller' => 'Providers', 'action' => 'index']) ?>"
           class="d-block text-decoration-none">
            <div class="sgi-stat-card accent-secondary p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-uppercase fw-semibold"
                          style="font-size:.6rem;letter-spacing:.12em;color:#aaa;">Proveedores</span>
                    <i class="bi bi-truck" style="color:var(--secondary-color);font-size:1rem;opacity:.8;"></i>
                </div>
                <div class="fw-bold lh-1" style="font-size:2.4rem;letter-spacing:-.05em;color:#111;">
                    <?= $this->Number->format($counters['providers'] ?? 0) ?>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($canView('users')): ?>
    <div class="col-3">
        <a href="<?= $this->Url->build(['controller' => 'Users', 'action' => 'index']) ?>"
           class="d-block text-decoration-none">
            <div class="sgi-stat-card accent-dark p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-uppercase fw-semibold"
                          style="font-size:.6rem;letter-spacing:.12em;color:#aaa;">Usuarios</span>
                    <i class="bi bi-person-gear" style="color:var(--bg-dark);font-size:1rem;opacity:.8;"></i>
                </div>
                <div class="fw-bold lh-1" style="font-size:2.4rem;letter-spacing:-.05em;color:#111;">
                    <?= $this->Number->format($counters['users'] ?? 0) ?>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

</div>

<!-- Accesos rápidos -->
<div>
    <!-- Separador con etiqueta -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.6rem;letter-spacing:.14em;color:#bbb;">Accesos Rápidos</span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>

    <div class="row g-2">
        <?php
        $quickLinks = [
            ['module' => 'invoices',          'label' => 'Facturas',               'icon' => 'bi-receipt',        'controller' => 'Invoices',          'group' => 'primary'],
            ['module' => 'employees',          'label' => 'Empleados',              'icon' => 'bi-people-fill',    'controller' => 'Employees',         'group' => 'primary'],
            ['module' => 'providers',          'label' => 'Proveedores',            'icon' => 'bi-truck',          'controller' => 'Providers',         'group' => 'secondary'],
            ['module' => 'approvers',          'label' => 'Aprobadores',            'icon' => 'bi-person-check',   'controller' => 'Approvers',         'group' => 'primary'],
            ['module' => 'operation_centers',  'label' => 'Centros de Operación',   'icon' => 'bi-geo-alt',        'controller' => 'OperationCenters',  'group' => 'dark'],
            ['module' => 'expense_types',      'label' => 'Tipos de Gasto',         'icon' => 'bi-tags',           'controller' => 'ExpenseTypes',      'group' => 'dark'],
            ['module' => 'cost_centers',       'label' => 'Centros de Costos',      'icon' => 'bi-diagram-3',      'controller' => 'CostCenters',       'group' => 'dark'],
            ['module' => 'positions',          'label' => 'Cargos',                 'icon' => 'bi-briefcase',      'controller' => 'Positions',         'group' => 'dark'],
            ['module' => 'employee_statuses',  'label' => 'Estados Empleado',       'icon' => 'bi-card-checklist', 'controller' => 'EmployeeStatuses',  'group' => 'dark'],
            ['module' => 'marital_statuses',   'label' => 'Estados Civiles',        'icon' => 'bi-heart',          'controller' => 'MaritalStatuses',   'group' => 'dark'],
            ['module' => 'education_levels',   'label' => 'Niveles Educativos',     'icon' => 'bi-mortarboard',    'controller' => 'EducationLevels',   'group' => 'dark'],
            ['module' => 'default_folders',    'label' => 'Carpetas por Defecto',   'icon' => 'bi-folder',         'controller' => 'DefaultFolders',    'group' => 'dark'],
            ['module' => 'users',              'label' => 'Usuarios',               'icon' => 'bi-people',         'controller' => 'Users',             'group' => 'dark'],
            ['module' => 'roles',              'label' => 'Roles',                  'icon' => 'bi-shield-lock',    'controller' => 'Roles',             'group' => 'dark'],
        ];

        $groupColors = [
            'primary'   => 'var(--primary-color)',
            'secondary' => 'var(--secondary-color)',
            'dark'      => 'var(--bg-dark)',
        ];

        foreach ($quickLinks as $link):
            if (!$canView($link['module'])) continue;
            $iconColor = $groupColors[$link['group']];
        ?>
        <div class="col-2">
            <a href="<?= $this->Url->build(['controller' => $link['controller'], 'action' => 'index']) ?>"
               class="sgi-quick-tile d-block text-decoration-none p-3">
                <i class="bi <?= h($link['icon']) ?> d-block mb-2"
                   style="font-size:1.2rem;color:<?= $iconColor ?>;opacity:.85;"></i>
                <span class="fw-medium d-block" style="font-size:.78rem;color:#444;line-height:1.3;">
                    <?= h($link['label']) ?>
                </span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
