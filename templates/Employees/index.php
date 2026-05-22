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

$navTabColor = fn(string $status) => match ($status) {
    EmployeeStatusConstants::RETIRADO => 'var(--danger-color)',
    EmployeeStatusConstants::ACTIVO   => 'var(--info-text)',
    default                           => 'var(--primary-color)',
};
?>

<div style="flex:1;min-width:0;display:grid;grid-template-columns:320px 1fr;gap:0;overflow:hidden;align-items:stretch;">

<!-- ═════════ COLUMNA IZQUIERDA · DIRECTORIO ═════════ -->
<aside style="display:flex;flex-direction:column;overflow:hidden;background:#fff;">

    <div style="padding:16px 16px 10px;">
        <div class="d-flex justify-content-between align-items-start gap-2" style="margin-bottom:12px;">
            <div class="min-w-0">
                <div style="font-size:15px;font-weight:700;color:var(--text-strong);">Empleados</div>
                <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">0 en el directorio</div>
            </div>
            <div class="dropdown">
                <button type="button" class="btn btn-primary btn-sm dropdown-toggle"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    Acciones
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php if ($canCreate) : ?>
                    <li><?= $this->Html->link(
                        '<i class="bi bi-person-plus" aria-hidden="true"></i>Nuevo empleado',
                        ['action' => 'add'],
                        ['class' => 'dropdown-item', 'escape' => false],
                    ) ?></li>
                    <li>
                        <button type="button" class="dropdown-item"
                                data-bs-toggle="modal" data-bs-target="#importExcelModal">
                            <i class="bi bi-download" aria-hidden="true"></i>Importar
                        </button>
                    </li>
                    <?php endif; ?>
                    <li>
                        <button type="button" class="dropdown-item"
                                data-bs-toggle="modal" data-bs-target="#exportExcelModal">
                            <i class="bi bi-box-arrow-up" aria-hidden="true"></i>Exportar
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <form method="get" class="emp-nav-search input" role="search"
              style="height:34px;padding:0 12px;background:var(--bg-subtle);outline:none;">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="search"
                   value="<?= h($navSearch) ?>"
                   placeholder="Buscar por nombre, CC o correo…"
                   aria-label="Buscar empleados"
                   autocomplete="off">
            <?php if ($navSearch !== '') : ?>
            <a href="<?= $this->Url->build(['action' => 'index', '?' => ['status' => $activeStatus]]) ?>"
               style="display:flex;color:var(--text-faint);" title="Limpiar búsqueda">
                <i class="bi bi-x" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <input type="hidden" name="status" value="<?= h($activeStatus) ?>">
        </form>
    </div>

    <!-- Chips de estado -->
    <div style="display:flex;gap:4px;padding:0 16px 8px;">
        <?php foreach ($navTabs as [$status, $label]) :
            $isActive = ($activeStatus === $status);
            ?>
            <?= $this->Html->link(
                ($isActive ? '<span class="dot" style="background:' . $navTabColor($status) . ';"></span>' : '') . h($label),
                ['action' => 'index', '?' => $tabBaseQuery + ['status' => $status]],
                [
                    'class' => 'chip' . ($isActive ? ' is-active' : ''),
                    'escape' => false,
                    'role' => 'tab',
                    'aria-selected' => $isActive ? 'true' : 'false',
                    'style' => 'padding:4px 10px;font-size:10.5px;'
                        . ($isActive ? 'color:' . $navTabColor($status) . ';' : ''),
                ],
            ) ?>
        <?php endforeach; ?>
    </div>

    <!-- Lista vacía -->
    <div style="flex:1;min-height:0;overflow:auto;border-top:1px solid var(--rule);">
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-search" aria-hidden="true"></i>
            </div>
            <div class="es-title">Sin resultados</div>
            <div class="es-msg">Ajusta la búsqueda o los filtros del directorio.</div>
        </div>
    </div>
</aside>

<!-- ═════════ COLUMNA DERECHA · DETALLE ═════════ -->
<section style="overflow:auto;padding:20px 24px;background:var(--background-color);
                display:flex;flex-direction:column;">
    <div class="sgi-card">
        <div class="empty-state" style="padding:64px 32px;">
            <div class="es-icon es-icon-primary">
                <i class="bi bi-person" aria-hidden="true"></i>
            </div>
            <?php if ($hasAnyEmployee) : ?>
                <div class="es-title">Sin empleados que coincidan con los filtros</div>
                <div class="es-msg">Prueba a limpiar la búsqueda o cambiar el estado en el panel izquierdo.</div>
            <?php else : ?>
                <div class="es-title">Aún no hay empleados registrados</div>
                <div class="es-msg">Comienza creando el primer empleado del sistema.</div>
            <?php endif; ?>
            <?php if ($canCreate) : ?>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear empleado',
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary', 'escape' => false],
                ) ?>
            <?php endif; ?>
        </div>
    </div>
</section>
</div>

<?= $this->element('excel_wizard/modals', [
    'module' => 'Employees',
    'entityName' => 'Empleados',
    'downloadSlug' => 'empleados',
    'importable' => true,
]) ?>
