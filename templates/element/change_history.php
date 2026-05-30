<?php
/**
 * Historial de cambios campo-a-campo (audit trail). Compartido por las vistas de
 * Novedades: individual (EmployeeNovelties/view) y liquidación grupal
 * (NoveltyLiquidationDocs/view). Renderiza nada si no hay historial.
 *
 * @var \App\View\AppView $this
 * @var iterable $histories       Filas de historial (con user, field_changed, old_value, new_value, created).
 * @var array $fieldLabels        Mapa field_changed => label legible.
 * @var string $title             Título de la card.
 * @var bool $showNoveltyLink     Si renderiza la columna con link a la novedad origen (vista grupal).
 */
$histories = $histories ?? [];
$fieldLabels = $fieldLabels ?? [];
$title = $title ?? 'Historial de Cambios';
$showNoveltyLink = $showNoveltyLink ?? false;

$histArr = is_array($histories) ? $histories : iterator_to_array($histories, false);
if (empty($histArr)) {
    return;
}
$histCount = count($histArr);
$initialsOf = static function (?string $name): string {
    if (!$name) {
        return '?';
    }
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $ini ?: mb_strtoupper(mb_substr($name, 0, 2));
};
?>
<div class="sgi-card">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
        <span class="sgi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <?= h($title) ?>
            <span class="sgi-folder-count"><?= $histCount ?></span>
        </span>
    </div>
    <div class="col-flex">
    <?php foreach ($histArr as $hi => $history):
        $hUser = $history->hasValue('user') ? $history->user->full_name : '—';
        $hField = $fieldLabels[$history->field_changed] ?? $history->field_changed;
    ?>
        <div class="d-flex align-items-center" style="gap:12px;padding:10px 0;<?= $hi === 0 ? '' : 'border-top:1px solid var(--rule);' ?>font-size:var(--fs-body-sm);">
            <span class="mono" style="color:var(--text-muted);flex-shrink:0;min-width:110px;">
                <?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?>
            </span>
            <?php if ($showNoveltyLink): ?>
            <span class="mono" style="flex-shrink:0;min-width:46px;">
                <?php if ($history->has('employee_novelty')): ?>
                <?= $this->Html->link(
                    '#' . $history->employee_novelty->id,
                    ['controller' => 'EmployeeNovelties', 'action' => 'view', $history->employee_novelty->id],
                    ['class' => 'mono']
                ) ?>
                <?php else: ?>—<?php endif; ?>
            </span>
            <?php endif; ?>
            <span class="d-inline-flex align-items-center" style="gap:6px;flex-shrink:0;min-width:140px;">
                <span class="av av-sm"><?= h($initialsOf($hUser)) ?></span>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($hUser) ?></span>
            </span>
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($hField) ?>
            </span>
            <span style="color:var(--text-muted);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $history->old_value ? h($history->old_value) : '—' ?>
            </span>
            <i class="bi bi-arrow-right" aria-hidden="true" style="color:var(--text-faint);font-size:11px;flex-shrink:0;"></i>
            <span style="color:var(--primary-color);font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $history->new_value ? h($history->new_value) : '—' ?>
            </span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
