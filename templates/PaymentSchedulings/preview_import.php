<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PaymentScheduling $record
 * @var array $result
 */
$this->assign('title', 'Previsualización — ' . h($record->code));

$validItems = $result['valid'] ?? [];
$errors = $result['errors'] ?? [];
?>

<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Programación de Pagos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($record->code), ['action' => 'edit', $record->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Previsualización</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Previsualización de Importación</span>
            <span class="spi-edit-id-chip"><?= h($record->code) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Cancelar',
            ['action' => 'edit', $record->id],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="card card-primary mb-4" style="border-top:2px solid var(--danger-color);">
    <div class="card-header" style="background:var(--danger-soft);">
        <span style="font-size:.85rem;font-weight:600;color:var(--danger-color);">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Errores (<?= count($errors) ?>)
        </span>
    </div>
    <div class="card-body">
        <ul class="mb-0" style="font-size:.85rem;">
            <?php foreach ($errors as $error): ?>
            <li class="text-danger"><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($validItems)): ?>
<div class="card card-primary mb-4" style="border-top:2px solid var(--primary-color);">
    <div class="card-header">
        <span style="font-size:.85rem;font-weight:600;color:var(--primary-color);">
            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Facturas válidas (<?= count($validItems) ?>)
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fila</th>
                    <th>N. Factura</th>
                    <th>Proveedor</th>
                    <th>Banco</th>
                    <th class="text-end">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($validItems as $item): ?>
                <tr>
                    <td><?= $item['row'] ?></td>
                    <td class="mono"><?= h($item['invoice_number']) ?></td>
                    <td><?= h($item['provider_name']) ?></td>
                    <td><?= h($item['bank_name']) ?></td>
                    <td class="text-end fw-bold">$ <?= number_format((float)$item['amount'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <th colspan="4">Total</th>
                    <th class="text-end">$ <?= number_format(array_sum(array_column($validItems, 'amount')), 0, ',', '.') ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="d-flex gap-3">
    <?= $this->Form->postLink(
        '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Confirmar importación (' . count($validItems) . ' facturas)',
        ['action' => 'confirmImport', $record->id],
        ['confirm' => '¿Confirmar la importación de ' . count($validItems) . ' facturas?', 'class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?= $this->Html->link(
        'Cancelar',
        ['action' => 'edit', $record->id],
        ['class' => 'btn btn-ghost-card']
    ) ?>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>No se encontraron facturas válidas en el archivo.
    <?= $this->Html->link('Volver', ['action' => 'edit', $record->id], ['class' => 'alert-link']) ?>
</div>
<?php endif; ?>
