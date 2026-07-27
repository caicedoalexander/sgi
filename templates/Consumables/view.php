<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Consumable $consumable
 * @var array<int, \App\Model\Entity\ConsumableMovement> $movements
 * @var bool $canEdit
 */
use App\Constants\ConsumableConstants;
use App\View\Presentation\ConsumablePresentation;

$this->assign('title', 'Consumible ' . h($consumable->reference));
[$stockLabel, $stockPill] = ConsumablePresentation::stockBadge($consumable);
?>
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div>
        <h1 class="spi-page-title">Consumible <?= h($consumable->reference) ?></h1>
        <span class="pill <?= h($stockPill) ?>"><?= h($stockLabel) ?></span>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $consumable->id], ['class' => 'btn btn-secondary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-card" style="margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;">
        <div class="field-row"><span class="k">Descripción</span><span class="v"><?= h($consumable->description) ?></span></div>
        <div class="field-row"><span class="k">Stock actual</span><span class="v mono"><?= $this->Number->format($consumable->current_stock) ?> <?= h($consumable->unit) ?></span></div>
        <div class="field-row"><span class="k">Mínimo / Máximo</span><span class="v mono"><?= $this->Number->format($consumable->minimum_stock) ?> / <?= $consumable->maximum_stock !== null ? $this->Number->format($consumable->maximum_stock) : '—' ?></span></div>
        <div class="field-row"><span class="k">Sede</span><span class="v"><?= h($consumable->operation_center->name ?? '—') ?></span></div>
    </div>
    <?php if ($canEdit): ?>
    <div class="d-flex gap-2 mt-3">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-stock-in"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Entrada</button>
        <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-stock-out"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Salida</button>
    </div>
    <?php endif; ?>
</div>

<div class="spi-card">
    <div style="font-weight:600;margin-bottom:12px;">Movimientos de stock (<?= count($movements) ?>)</div>
    <?php if ($movements === []): ?>
        <div style="color:var(--text-faint);font-size:13px;">Sin movimientos.</div>
    <?php else: ?>
        <?php foreach ($movements as $m): ?>
        <div class="d-flex justify-content-between align-items-center" style="padding:6px 0;border-bottom:1px solid var(--border-subtle);">
            <span><?= h(ConsumableConstants::MOVEMENT_LABELS[$m->movement_type] ?? $m->movement_type) ?>
                <span class="mono" style="color:var(--text-faint);">(<?= $m->quantity > 0 ? '+' : '' ?><?= $this->Number->format($m->quantity) ?> → <?= $this->Number->format($m->balance_after) ?>)</span>
            </span>
            <span class="mono" style="font-size:12px;color:var(--text-muted);"><?= $m->movement_date?->format('d/m/Y H:i') ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<?php
$stockModal = function (string $id, string $title, string $action) use ($consumable): string {
    $html = '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">';
    $html .= $this->Form->create(null, ['url' => ['action' => $action, $consumable->id]]);
    $html .= '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>';
    $html .= '<div class="modal-body">';
    $html .= $this->Form->control('quantity', ['type' => 'number', 'min' => 1, 'required' => true, 'class' => 'form-control', 'label' => ['text' => 'Cantidad', 'class' => 'input-label']]);
    $html .= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 2, 'class' => 'form-control mt-2', 'label' => ['text' => 'Motivo', 'class' => 'input-label']]);
    $html .= '</div><div class="modal-footer"><button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Confirmar</button></div>';
    $html .= $this->Form->end() . '</div></div></div>';

    return $html;
};
?>
<?= $stockModal('modal-stock-in', 'Entrada de stock', 'stockIn') ?>
<?= $stockModal('modal-stock-out', 'Salida de stock', 'stockOut') ?>
<?php endif; ?>
