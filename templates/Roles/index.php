<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Role> $roles
 * @var array<int, array{moduleCount:int,pipelineCount:int,userCount:int,userNames:list<string>,isSystem:bool}> $roleStats
 * @var array{roleCount:int,customCount:int,systemCount:int,userCount:int,moduleTotal:int,pipelineTotal:int} $kpis
 * @var int $moduleTotal
 * @var int $pipelineTotal
 */
$this->assign('title', 'Roles');

$canEdit   = !empty($userPermissions['roles']['can_edit']);
$canDelete = !empty($userPermissions['roles']['can_delete']);
$canCreate = !empty($userPermissions['roles']['can_create']);

// Inicial en mayúscula para el avatar.
$initial = static fn(string $n): string => mb_strtoupper(mb_substr(trim($n), 0, 1)) ?: '·';

// Columnas de la tabla: Rol · Descripción · Permisos · Usuarios · Modificado · Acciones.
$gridCols = '2fr 2.3fr 1.4fr 1.3fr 1.2fr 92px';

$kpiCards = [
    ['Roles definidos', $kpis['roleCount'], $kpis['customCount'] . ' personalizados', 'var(--primary-color)', 'bi-shield-check'],
    ['Usuarios asignados', $kpis['userCount'], 'En todos los roles', 'var(--secondary-color)', 'bi-people'],
    ['Módulos', $kpis['moduleTotal'], 'Catálogo de permisos', 'var(--accent-color)', 'bi-grid-3x3-gap'],
    ['Pasos de pipeline', $kpis['pipelineTotal'], 'Flujos operativos', 'var(--bg-dark)', 'bi-diagram-3'],
];
?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="spi-page-title">Roles del sistema</span>
        <div class="spi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            Define qué módulos, acciones y pasos del pipeline puede operar cada usuario.
        </div>
    </div>
    <?php if ($canCreate): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo rol',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<!-- ═══ KPIs ═══ -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;">
    <?php foreach ($kpiCards as [$label, $value, $sub, $color, $icon]): ?>
    <div class="spi-card" style="padding:14px 16px;position:relative;overflow:hidden;">
        <span style="position:absolute;left:0;top:0;bottom:0;width:3px;background:<?= $color ?>;"></span>
        <div class="d-flex justify-content-between align-items-start">
            <div class="spi-label" style="margin:0;"><?= h($label) ?></div>
            <i class="bi <?= h($icon) ?>" style="color:<?= $color ?>;opacity:.7;font-size:14px;" aria-hidden="true"></i>
        </div>
        <div class="mono" style="font-size:24px;font-weight:800;color:<?= $color ?>;margin-top:4px;letter-spacing:-0.5px;line-height:1.05;">
            <?= $this->Number->format($value) ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= h($sub) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══ Buscador + filtros ═══ -->
<div class="d-flex gap-2 align-items-center" style="margin-bottom:14px;">
    <div class="spi-card compact d-flex align-items-center" style="flex:1;gap:10px;padding:0 14px;">
        <i class="bi bi-search" style="color:var(--text-faint);" aria-hidden="true"></i>
        <input type="text" id="roles-search" autocomplete="off"
               placeholder="Buscar rol por nombre o descripción…"
               style="flex:1;border:0;outline:0;padding:10px 0;font-size:13px;color:var(--text-default);background:transparent;font-family:inherit;">
        <button type="button" id="roles-search-clear" class="btn-icon" style="display:none;" aria-label="Limpiar búsqueda">
            <i class="bi bi-x" aria-hidden="true"></i>
        </button>
    </div>
    <div class="d-flex" style="gap:4px;background:#fff;padding:3px;">
        <button type="button" class="chip is-active" data-filter="todos" style="color:var(--primary-color);">
            Todos · <?= $kpis['roleCount'] ?>
        </button>
        <button type="button" class="chip" data-filter="sistema">
            Sistema · <?= $kpis['systemCount'] ?>
        </button>
        <button type="button" class="chip" data-filter="personalizados">
            Personalizados · <?= $kpis['customCount'] ?>
        </button>
    </div>
</div>

<!-- ═══ Tabla de roles ═══ -->
<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Rol</span>
        <span>Descripción</span>
        <span>Permisos</span>
        <span>Usuarios</span>
        <span>Modificado</span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <div id="roles-rows">
    <?php $rowCount = 0; foreach ($roles as $role):
        $rowCount++;
        $st = $roleStats[$role->id] ?? ['moduleCount' => 0, 'pipelineCount' => 0, 'userCount' => 0, 'userNames' => [], 'isSystem' => false];
        $rowAction = $canEdit ? 'edit' : 'view';
        $href = $this->Url->build(['action' => $rowAction, $role->id]);

        $modDot = $st['moduleCount'] === $moduleTotal
            ? 'var(--primary-color)'
            : ($st['moduleCount'] > 0 ? 'var(--warning-color)' : 'var(--text-disabled)');
        $pipeDot = $st['pipelineCount'] === $pipelineTotal
            ? 'var(--primary-color)'
            : ($st['pipelineCount'] > 0 ? 'var(--warning-color)' : 'var(--text-disabled)');
    ?>
    <div class="row-fact roles-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= h($href) ?>"
         data-name="<?= h(mb_strtolower($role->name)) ?>"
         data-desc="<?= h(mb_strtolower((string)$role->description)) ?>"
         data-system="<?= $st['isSystem'] ? '1' : '0' ?>"
         role="row">

        <!-- Rol -->
        <div class="d-flex align-items-center" style="gap:12px;min-width:0;">
            <span aria-hidden="true" style="width:34px;height:34px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);
                <?= $st['isSystem']
                    ? 'background:var(--primary-color);color:#fff;'
                    : 'background:var(--bg-subtle);color:var(--primary-color);' ?>">
                <i class="bi bi-shield-check" style="font-size:16px;"></i>
            </span>
            <div style="min-width:0;">
                <div class="d-flex align-items-center" style="gap:6px;">
                    <span style="font-size:13px;font-weight:700;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h($role->name) ?>
                    </span>
                    <?php if ($st['isSystem']): ?>
                    <span class="pill pill-sm pill-primary-soft">Sistema</span>
                    <?php endif; ?>
                </div>
                <div class="mono" style="font-size:10.5px;color:var(--text-faint);margin-top:2px;">
                    ROL-<?= str_pad((string)$role->id, 3, '0', STR_PAD_LEFT) ?>
                </div>
            </div>
        </div>

        <!-- Descripción -->
        <div style="font-size:12px;color:var(--text-muted);line-height:1.45;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
            <?= h($role->description) ?: '<span style="color:var(--text-disabled);">Sin descripción</span>' ?>
        </div>

        <!-- Permisos -->
        <div style="font-size:11.5px;color:var(--text-default);line-height:1.5;">
            <div class="d-flex align-items-center" style="gap:5px;">
                <span style="width:6px;height:6px;border-radius:50%;flex-shrink:0;background:<?= $modDot ?>;"></span>
                <span><strong><?= $st['moduleCount'] ?></strong><span style="color:var(--text-faint);">/<?= $moduleTotal ?></span> módulos</span>
            </div>
            <div class="d-flex align-items-center" style="gap:5px;margin-top:3px;">
                <span style="width:6px;height:6px;border-radius:50%;flex-shrink:0;background:<?= $pipeDot ?>;"></span>
                <span><strong><?= $st['pipelineCount'] ?></strong><span style="color:var(--text-faint);">/<?= $pipelineTotal ?></span> pasos</span>
            </div>
        </div>

        <!-- Usuarios -->
        <div class="d-flex align-items-center" style="gap:8px;">
            <?php if ($st['userCount'] > 0): ?>
            <div class="d-flex">
                <?php foreach (array_slice($st['userNames'], 0, 3) as $i => $uName): ?>
                <span class="av av-sm" title="<?= h($uName) ?>"
                      style="<?= $i > 0 ? 'margin-left:-6px;' : '' ?>outline:2px solid #fff;">
                    <?= h($initial($uName)) ?>
                </span>
                <?php endforeach; ?>
                <?php if ($st['userCount'] > 3): ?>
                <span style="margin-left:-6px;width:24px;height:24px;background:var(--bg-subtle);color:var(--text-muted);outline:2px solid #fff;border-radius:var(--radius-sm);display:inline-flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700;">
                    +<?= $st['userCount'] - 3 ?>
                </span>
                <?php endif; ?>
            </div>
            <span style="font-size:12px;color:var(--text-default);font-weight:600;"><?= $st['userCount'] ?></span>
            <?php else: ?>
            <span style="font-size:11.5px;color:var(--text-disabled);">Sin usuarios</span>
            <?php endif; ?>
        </div>

        <!-- Modificado -->
        <div style="font-size:11.5px;color:var(--text-default);">
            <div class="mono"><?= $role->modified?->format('d/m/Y') ?: '—' ?></div>
            <div style="color:var(--text-faint);font-size:10.5px;margin-top:2px;">
                <?= $role->modified?->format('H:i') ?: '' ?>
            </div>
        </div>

        <!-- Acciones -->
        <div class="row-actions d-flex justify-content-end" style="gap:4px;">
            <?php if ($canEdit): ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $role->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar', 'aria-label' => 'Editar rol']
            ) ?>
            <?php else: ?>
            <?= $this->Html->link(
                '<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $role->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver', 'aria-label' => 'Ver rol']
            ) ?>
            <?php endif; ?>
            <?php if ($canDelete && !$st['isSystem']): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $role->id],
                [
                    'confirm' => '¿Eliminar el rol "' . $role->name . '"? Esta acción no se puede deshacer.',
                    'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar', 'aria-label' => 'Eliminar rol',
                ]
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-shield-slash" aria-hidden="true"></i></div>
        <div class="es-title">Sin roles</div>
        <div class="es-msg">No hay roles definidos todavía.</div>
    </div>
    <?php endif; ?>

    <!-- Mensaje de búsqueda sin resultados (lo controla el JS) -->
    <div id="roles-empty" class="empty-state" style="display:none;">
        <div class="es-icon es-icon-neutral"><i class="bi bi-search" aria-hidden="true"></i></div>
        <div class="es-title">Sin resultados</div>
        <div class="es-msg">Ningún rol coincide con la búsqueda o el filtro aplicado.</div>
    </div>
</div>

<div class="d-flex justify-content-between" style="margin-top:10px;padding:10px 16px;background:#fff;font-size:11px;color:var(--text-faint);">
    <span id="roles-count"><?= $rowCount ?> <?= $rowCount === 1 ? 'rol' : 'roles' ?></span>
    <span class="mono">Los roles "Sistema" no pueden eliminarse</span>
</div>

<?= $this->element('pagination') ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('roles-search');
    var clearBtn = document.getElementById('roles-search-clear');
    var chips = Array.prototype.slice.call(document.querySelectorAll('.chip[data-filter]'));
    var rows = Array.prototype.slice.call(document.querySelectorAll('.roles-row'));
    var emptyMsg = document.getElementById('roles-empty');
    var countEl = document.getElementById('roles-count');
    var activeFilter = 'todos';

    function apply() {
        var q = search.value.trim().toLowerCase();
        var visible = 0;
        rows.forEach(function (row) {
            var isSystem = row.dataset.system === '1';
            var matchFilter = activeFilter === 'todos'
                || (activeFilter === 'sistema' && isSystem)
                || (activeFilter === 'personalizados' && !isSystem);
            var matchSearch = q === ''
                || row.dataset.name.indexOf(q) !== -1
                || row.dataset.desc.indexOf(q) !== -1;
            var show = matchFilter && matchSearch;
            row.style.display = show ? '' : 'none';
            if (show) { visible++; }
        });
        clearBtn.style.display = q === '' ? 'none' : '';
        emptyMsg.style.display = visible === 0 && rows.length > 0 ? '' : 'none';
        countEl.textContent = visible + (visible === 1 ? ' rol' : ' roles');
    }

    search.addEventListener('input', apply);
    clearBtn.addEventListener('click', function () { search.value = ''; apply(); search.focus(); });
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (c) { c.classList.remove('is-active'); c.style.color = ''; });
            chip.classList.add('is-active');
            chip.style.color = 'var(--primary-color)';
            activeFilter = chip.dataset.filter;
            apply();
        });
    });

    // Clic en la fila → navega, salvo sobre los botones de acción.
    rows.forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.row-actions')) { return; }
            window.location.href = row.dataset.href;
        });
    });
});
</script>
