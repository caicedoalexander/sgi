<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $entity
 */
?>
<div class="spi-label" style="margin-bottom:10px;">Detalle de Novedad</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
    <div>
        <div class="field-row">
            <span class="k">Empleado</span>
            <span class="v"><?= h($entity->employee?->full_name ?? '—') ?></span>
        </div>
        <div class="field-row">
            <span class="k">Tipo de novedad</span>
            <span class="v"><?= h($entity->novelty_type?->name ?? '—') ?></span>
        </div>
        <div class="field-row is-last">
            <span class="k">Motivo</span>
            <span class="v"><?= h($entity->reason ?? '—') ?></span>
        </div>
    </div>
    <div>
        <div class="field-row">
            <span class="k">Desde</span>
            <span class="v mono"><?= $entity->start_date ? h($entity->start_date->format('d/m/Y')) : '—' ?></span>
        </div>
        <div class="field-row is-last">
            <span class="k">Hasta</span>
            <span class="v mono"><?= $entity->end_date ? h($entity->end_date->format('d/m/Y')) : '—' ?></span>
        </div>
    </div>
</div>
