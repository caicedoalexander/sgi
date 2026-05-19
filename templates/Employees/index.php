<?php
/**
 * Empty-state de empleados. Se renderiza solo cuando index() no encuentra
 * un primer empleado para redirigir.
 *
 * @var \App\View\AppView $this
 * @var bool $hasAnyEmployee true si existe al menos un empleado en BD
 *                           (filtros sin matches) vs false (BD totalmente vacia).
 */

use App\Constants\EmployeeStatusConstants;

$this->assign('title', 'Empleados');

$canCreate = !empty($userPermissions['employees']['can_create']);
$activeStatus = $this->request->getQuery('status') ?: EmployeeStatusConstants::ACTIVO;
$navTabs = [
    [EmployeeStatusConstants::ACTIVO, 'Activos'],
    [EmployeeStatusConstants::RETIRADO, 'Retirados'],
    ['all', 'Todos'],
];
$navSearch = (string)$this->request->getQuery('search', '');
$tabBaseQuery = $navSearch !== '' ? ['search' => $navSearch] : [];
?>
<div class="sgi-master-detail">
    <aside class="sgi-md-left">
        <div class="sgi-md-left-head">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div class="sgi-title-card">Empleados</div>
                    <div class="sgi-body-faint mt-1">0 mostrados</div>
                </div>
                <?php if ($canCreate) : ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo',
                        ['action' => 'add'],
                        ['class' => 'btn btn-primary btn-sm', 'escape' => false],
                    ) ?>
                <?php endif; ?>
            </div>
            <form method="get" class="sgi-md-search mb-2" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="text" name="search"
                       value="<?= h($navSearch) ?>"
                       placeholder="Buscar por nombre, CC o correo…"
                       aria-label="Buscar empleados"
                       autocomplete="off">
                <input type="hidden" name="status" value="<?= h($activeStatus) ?>">
            </form>
            <div class="sgi-status-tabs" role="tablist" aria-label="Filtrar por estado">
                <?php foreach ($navTabs as [$status, $label]) :
                    $isActive = ($activeStatus === $status);
                    ?>
                    <?= $this->Html->link(
                        h($label),
                        ['action' => 'index', '?' => $tabBaseQuery + ['status' => $status]],
                        [
                            'class' => 'sgi-status-tab' . ($isActive ? ' is-active' : ''),
                            'escape' => false,
                            'role' => 'tab',
                            'aria-selected' => $isActive ? 'true' : 'false',
                        ],
                    ) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sgi-md-left-list">
            <div class="sgi-doc-empty">
                <i class="bi bi-search sgi-doc-empty-icon" aria-hidden="true"></i>
                <div class="sgi-fg-muted">Sin resultados</div>
            </div>
        </div>
    </aside>

    <section class="sgi-md-right">
        <div class="card">
            <div class="sgi-doc-empty" style="padding:4rem 2rem;text-align:center;">
                <i class="bi bi-people sgi-doc-empty-icon" aria-hidden="true" style="font-size:3rem;"></i>
                <?php if ($hasAnyEmployee) : ?>
                    <h2 class="sgi-title-card mt-3">Sin empleados que coincidan con los filtros</h2>
                    <p class="sgi-body-muted mt-2">
                        Prueba a limpiar la búsqueda o cambiar el estado en el panel izquierdo.
                    </p>
                <?php else : ?>
                    <h2 class="sgi-title-card mt-3">Aún no hay empleados registrados</h2>
                    <p class="sgi-body-muted mt-2">Comienza creando el primer empleado del sistema.</p>
                <?php endif; ?>
                <?php if ($canCreate) : ?>
                <div class="mt-3">
                    <?= $this->Html->link(
                        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear empleado',
                        ['action' => 'add'],
                        ['class' => 'btn btn-primary', 'escape' => false],
                    ) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
