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
<li class="sb-section-head">RRHH</li>
    <?php if ($canView('employees')) : ?>
<li>
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-people-fill" aria-hidden="true"></i></span><span class="grow">Empleados</span>',
            ['controller' => 'Employees', 'action' => 'index'],
            ['class' => $navLink('Employees'), 'escape' => false],
        ) ?>
</li>
    <?php endif; ?>
    <?php
    $noveltiesSubActive = $currentController === 'EmployeeNovelties';
    if ($canView('employee_novelties')) : ?>
<li>
    <div class="sb-collapsible-header">
        <?= $this->Html->link(
            '<span class="ic"><i class="bi bi-journal-text" aria-hidden="true"></i></span><span class="grow">Todas las Novedades</span>',
            ['controller' => 'EmployeeNovelties', 'action' => 'all'],
            ['class' => $navLink('EmployeeNovelties', 'all'), 'escape' => false],
        ) ?>
        <button class="sb-chevron"
                data-bs-toggle="collapse"
                data-bs-target="#novedades-submenu"
                aria-expanded="<?= $noveltiesSubActive ? 'true' : 'false' ?>"
                aria-controls="novedades-submenu">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse<?= $noveltiesSubActive ? ' show' : '' ?>" id="novedades-submenu">
        <ul class="sb-submenu">
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-journal-text" aria-hidden="true"></i></span><span class="grow">Mis Novedades</span>' .
                    ($noveltiesCount > 0 ? '<span class="sb-badge is-warning">' . $noveltiesCount . '</span>' : ''),
                    ['controller' => 'EmployeeNovelties', 'action' => 'index'],
                    ['class' => $navLink('EmployeeNovelties', 'index'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-x-circle" aria-hidden="true"></i></span><span class="grow">Rechazadas</span>' .
                    ($rejectedNoveltiesCount > 0 ? '<span class="sb-badge is-danger">' . $rejectedNoveltiesCount . '</span>' : ''),
                    ['controller' => 'EmployeeNovelties', 'action' => 'rejected'],
                    ['class' => $navLink('EmployeeNovelties', 'rejected'), 'escape' => false],
                ) ?>
            </li>
            <li>
                <?= $this->Html->link(
                    '<span class="ic"><i class="bi bi-calendar-check" aria-hidden="true"></i></span><span class="grow">Vigentes</span>' .
                    (($activeNoveltiesCount ?? 0) > 0 ? '<span class="sb-badge is-primary">' . ($activeNoveltiesCount ?? 0) . '</span>' : ''),
                    ['controller' => 'EmployeeNovelties', 'action' => 'active'],
                    ['class' => $navLink('EmployeeNovelties', 'active'), 'escape' => false],
                ) ?>
            </li>
        </ul>
    </div>
</li>
    <?php endif; ?>
