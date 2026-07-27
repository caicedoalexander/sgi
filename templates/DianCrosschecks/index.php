<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\DianCrosscheck> $dianCrosschecks
 * @var string|null $statusFilter
 */

use App\View\Presentation\DianCrosscheckPresentation;

$this->assign('title', 'Cruce DIAN');
?>

<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Cruce DIAN</span>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-default" data-filter-drawer-open aria-label="Filtros">
            <i class="bi bi-funnel" aria-hidden="true"></i><span>Filtros</span>
        </button>
        <?php if (!empty($userPermissions['dian_crosschecks']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-upload me-1" aria-hidden="true"></i>Subir Archivo',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<form method="get">
<?php ob_start(); ?>
    <div class="filter-field">
        <label class="input-label" for="filter-status">Estado</label>
        <select name="status" id="filter-status" class="form-select form-select-sm">
            <option value="">Todos</option>
            <option value="enviado" <?= ($statusFilter ?? '') === 'enviado' ? 'selected' : '' ?>>Enviado</option>
            <option value="procesando" <?= ($statusFilter ?? '') === 'procesando' ? 'selected' : '' ?>>Procesando</option>
            <option value="completado" <?= ($statusFilter ?? '') === 'completado' ? 'selected' : '' ?>>Completado</option>
            <option value="error" <?= ($statusFilter ?? '') === 'error' ? 'selected' : '' ?>>Error</option>
        </select>
    </div>
<?php $filterFields = ob_get_clean(); ?>
<?= $this->element('filter_drawer', [
    'body' => $filterFields,
    'count' => 0,
    'clearUrl' => ['action' => 'index'],
]) ?>
</form>

<?php $rows = iterator_to_array($dianCrosschecks); ?>
<?php $grid = 'minmax(0,2.4fr) minmax(0,1.5fr) 120px 150px minmax(0,2.2fr)'; ?>
<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $grid ?>;" role="row">
        <span>Archivo</span>
        <span>Subido por</span>
        <span>Estado</span>
        <span>Fecha</span>
        <span>Error</span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-clipboard-x" aria-hidden="true"></i></div>
        <div class="es-title">Sin registros</div>
        <div class="es-msg">No hay registros de cruce DIAN.</div>
    </div>
    <?php else: ?>
    <?php foreach ($rows as $crosscheck): ?>
    <div class="row-fact" style="grid-template-columns:<?= $grid ?>;cursor:default;" role="row">
        <div class="mono" style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;">
            <?= h($crosscheck->file_name) ?>
        </div>
        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;">
            <?= h($crosscheck->uploaded_by_user->full_name ?? '—') ?>
        </div>
        <div>
            <span class="pill pill-sm <?= DianCrosscheckPresentation::STATUS_BADGES[$crosscheck->status] ?? DianCrosscheckPresentation::DEFAULT_BADGE ?>">
                <?= h(ucfirst($crosscheck->status)) ?>
            </span>
        </div>
        <div class="mono" style="font-size:11.5px;color:var(--text-default);white-space:nowrap;">
            <?= $crosscheck->created?->format('d/m/Y H:i') ?: '—' ?>
        </div>
        <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?php if ($crosscheck->error_message): ?>
            <span style="font-size:11px;color:var(--danger-color);"><?= h(\Cake\Utility\Text::truncate($crosscheck->error_message, 80)) ?></span>
            <?php else: ?>
            <span style="color:var(--text-faint);">—</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?= $this->element('pagination') ?>
