<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $entity
 */
?>
<div class="spi-label" style="margin-bottom:10px;">Detalle de Factura</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
    <div>
        <div class="field-row">
            <span class="k">Número</span>
            <span class="v mono"><?= h($entity->invoice_number ?? '—') ?></span>
        </div>
        <div class="field-row">
            <span class="k">Proveedor</span>
            <span class="v"><?= h($entity->provider?->name ?? '—') ?></span>
        </div>
        <div class="field-row is-last">
            <span class="k">Monto</span>
            <span class="v mono">$ <?= h(number_format((float)($entity->amount ?? 0), 0, ',', '.')) ?></span>
        </div>
    </div>
    <div>
        <div class="field-row">
            <span class="k">Tipo de documento</span>
            <span class="v"><?= h($entity->document_type ?? '—') ?></span>
        </div>
        <div class="field-row is-last">
            <span class="k">Detalle</span>
            <span class="v"><?= h($entity->detail ?? '—') ?></span>
        </div>
    </div>
</div>
