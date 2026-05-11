<?php
/**
 * Sidebar — sección RRHH.
 *
 * @var \App\View\AppView $this
 * @var \Closure $canView
 * @var \Closure $navLink
 * @var string $currentController
 * @var int    $noveltiesCount
 * @var int    $rejectedNoveltiesCount
 * @var int    $activeNoveltiesCount
 */

$rrhhItems = array_filter([
    $canView('employees') ? 'employees' : null,
    $canView('employee_novelties') ? 'employee_novelties' : null,
]);
if (empty($rrhhItems)) {
    return;
}
?>
<li class="nav-heading">RRHH</li>
    <?php if ($canView('employees')) : ?>
<li class="nav-item">
        <?= $this->Html->link(
            '<i class="bi bi-people-fill me-2" aria-hidden="true"></i>Empleados',
            ['controller' => 'Employees', 'action' => 'index'],
            ['class' => $navLink('Employees'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php
    $noveltiesSubActive = $currentController === 'EmployeeNovelties';
    if ($canView('employee_novelties')) : ?>
<li class="nav-item sidebar-has-submenu">
    <div class="sidebar-collapsible-header">
        <?= $this->Html->link(
            '<i class="bi bi-journal-text me-2" aria-hidden="true"></i><span class="flex-grow-1">Todas las Novedades</span>',
            ['controller' => 'EmployeeNovelties', 'action' => 'all'],
            ['class' => $navLink('EmployeeNovelties', 'all') . ' flex-grow-1 d-flex align-items-center', 'escape' => false],
        ) ?>
        <button class="sidebar-chevron-btn"
                data-bs-toggle="collapse"
                data-bs-target="#novedades-submenu"
                aria-expanded="<?= $noveltiesSubActive ? 'true' : 'false' ?>"
                aria-controls="novedades-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $noveltiesSubActive ? ' show' : '' ?>" id="novedades-submenu">
        <ul class="sidebar-submenu">
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-journal-text me-2" aria-hidden="true"></i>Mis Novedades' .
                    ($noveltiesCount > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $noveltiesCount . '</span>' : ''),
                    ['controller' => 'EmployeeNovelties', 'action' => 'index'],
                    ['class' => $navLink('EmployeeNovelties', 'index') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-x-circle me-2" aria-hidden="true"></i>Rechazadas' .
                    ($rejectedNoveltiesCount > 0 ? ' <span class="badge bg-danger sidebar-badge ms-auto">' . $rejectedNoveltiesCount . '</span>' : ''),
                    ['controller' => 'EmployeeNovelties', 'action' => 'rejected'],
                    ['class' => $navLink('EmployeeNovelties', 'rejected') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
            <li class="nav-item">
                <?= $this->Html->link(
                    '<i class="bi bi-calendar-check me-2" aria-hidden="true"></i>Vigentes' .
                    (($activeNoveltiesCount ?? 0) > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . ($activeNoveltiesCount ?? 0) . '</span>' : ''),
                    ['controller' => 'EmployeeNovelties', 'action' => 'active'],
                    ['class' => $navLink('EmployeeNovelties', 'active') . ' d-flex align-items-center', 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
