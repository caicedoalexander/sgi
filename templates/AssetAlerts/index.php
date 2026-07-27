<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AssetAlert> $alerts
 * @var string $status
 * @var array<string, string> $statusLabels
 * @var array<string, string> $typeLabels
 */
use App\Constants\AssetAlertConstants;
use App\View\Presentation\AssetAlertPresentation;

$this->assign('title', 'Alertas de Inventario');

$canResolve = !empty($userPermissions['asset_alerts']['can_edit']);
$gridCols = '160px 1fr 90px 90px 110px';
$tabs = [
    [AssetAlertConstants::STATUS_ABIERTA, 'Abiertas'],
    [AssetAlertConstants::STATUS_VENCIDA, 'Vencidas'],
    [AssetAlertConstants::STATUS_RESUELTA, 'Resueltas'],
    ['', 'Todas'],
];
?>
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Alertas de Inventario</span>
</div>

<div class="d-flex flex-wrap" style="gap:8px;margin-bottom:14px;" role="tablist">
    <?php foreach ($tabs as [$value, $label]): ?>
        <?= $this->Html->link(h($label),
            ['action' => 'index', '?' => $value !== '' ? ['status' => $value] : []],
            ['class' => 'chip' . ($status === $value ? ' is-active' : ''), 'role' => 'tab']) ?>
    <?php endforeach; ?>
</div>

<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Tipo</span>
        <span>Mensaje</span>
        <span>Prioridad</span>
        <span>Estado</span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($alerts as $alert): $rowCount++; ?>
    <div class="row-fact" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span style="font-weight:600;"><?= h($typeLabels[$alert->alert_type] ?? $alert->alert_type) ?></span>
        <span style="color:var(--text-muted);"><?= h($alert->message) ?></span>
        <span><span class="pill <?= h(AssetAlertPresentation::PRIORITY_BADGES[$alert->priority] ?? 'pill-secondary-soft') ?>"><?= h(AssetAlertConstants::PRIORITY_LABELS[$alert->priority] ?? $alert->priority) ?></span></span>
        <span><span class="pill <?= h(AssetAlertPresentation::STATUS_BADGES[$alert->status] ?? 'pill-secondary-soft') ?>"><?= h($statusLabels[$alert->status] ?? $alert->status) ?></span></span>
        <span class="d-flex justify-content-end">
            <?php if ($canResolve && $alert->status !== AssetAlertConstants::STATUS_RESUELTA): ?>
            <?= $this->Form->postLink('<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Resolver',
                ['action' => 'resolve', $alert->id],
                ['confirm' => '¿Marcar la alerta como resuelta?', 'class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-bell-slash" aria-hidden="true"></i></div>
        <div class="es-title">Sin alertas</div>
        <div class="es-msg">No hay alertas para el filtro seleccionado.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
