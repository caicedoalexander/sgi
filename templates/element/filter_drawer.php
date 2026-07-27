<?php
/**
 * Drawer lateral de filtros (panel deslizante desde la derecha).
 *
 * Se renderiza DENTRO del <form> GET del índice (Form->create con
 * valueSources => ['query']). El panel es position:fixed pero descendiente
 * del form en el DOM, por lo que sus campos se envían con el form al pulsar
 * "Aplicar filtros" (type=submit). El disparador vive en el header del índice:
 *
 *   <button type="button" class="btn btn-default" data-filter-drawer-open>
 *       <i class="bi bi-funnel"></i><span>Filtros<?= $count ? ' · '.$count : '' ?></span>
 *   </button>
 *
 * Los campos se pasan como HTML capturado (ob_start / ob_get_clean), cada uno
 * envuelto en <div class="filter-field">.
 *
 * @var \App\View\AppView $this
 * @var string $body              HTML de los campos de filtro.
 * @var int $count                Nº de filtros activos (0 = sin botón Limpiar).
 * @var array|string|null $clearUrl URL para "Limpiar" (null = sin botón).
 * @var string $title             Título del panel (default 'Filtros').
 * @var string $applyLabel        Texto del botón aplicar (default 'Aplicar filtros').
 */
$title = $title ?? 'Filtros';
$applyLabel = $applyLabel ?? 'Aplicar filtros';
$count = $count ?? 0;
$clearUrl = $clearUrl ?? null;
?>
<div class="filter-drawer-backdrop"></div>
<aside class="filter-drawer" role="dialog" aria-modal="true" aria-label="<?= h($title) ?>">
    <div class="filter-drawer-head">
        <span class="filter-drawer-title">
            <i class="bi bi-funnel" aria-hidden="true"></i><?= h($title) ?>
        </span>
        <button type="button" class="filter-drawer-close" data-filter-drawer-close aria-label="Cerrar">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="filter-drawer-body">
        <?= $body ?? '' ?>
    </div>
    <div class="filter-drawer-foot">
        <?php if ($clearUrl !== null && $count > 0): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Limpiar</span>',
                $clearUrl,
                ['class' => 'btn btn-ghost btn-sm', 'escape' => false, 'style' => 'color:var(--danger-color);']
            ) ?>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-check2" aria-hidden="true"></i><span><?= h($applyLabel) ?></span>
        </button>
    </div>
</aside>
