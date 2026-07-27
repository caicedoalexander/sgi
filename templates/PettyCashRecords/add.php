<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PettyCashRecord $record
 * @var iterable $availableInvoices
 * @var iterable $operationCenters
 * @var array $groupFilters
 */
$this->assign('title', 'Nuevo Registro de Caja Menor');
$groupFilters = $groupFilters ?? [];
?>
<?= $this->element('cdn_select2') ?>

<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Caja Menor', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Nuevo Registro</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Nuevo Registro de Caja Menor</span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Filtros de agrupación -->
<div class="spi-card compact" style="margin-bottom:14px;">
    <div class="d-flex align-items-center gap-2" style="margin-bottom:12px;">
        <i class="bi bi-funnel" aria-hidden="true" style="color:var(--text-muted);"></i>
        <span class="spi-label">Filtrar facturas para agrupar</span>
    </div>
    <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="input-label">Fecha Emisión Desde</label>
            <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date"
                   value="<?= h($groupFilters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="input-label">Fecha Emisión Hasta</label>
            <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date"
                   value="<?= h($groupFilters['date_to'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="input-label">Centro de Operación</label>
            <select name="operation_center_id" class="form-select form-select-sm select2-enable">
                <option value="">Todos</option>
                <?php foreach ($operationCenters as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($groupFilters['operation_center_id'] ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="input-label">Proveedor</label>
            <select name="provider_id" class="form-select form-select-sm select2-enable">
                <option value="">Todos</option>
                <?php foreach (($providers ?? []) as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($groupFilters['provider_id'] ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar</button>
            <?= $this->Html->link('Limpiar', ['action' => 'add'], ['class' => 'btn btn-sm btn-default']) ?>
        </div>
    </div>
    <div class="mt-2 spi-hint">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Por defecto se muestran facturas emitidas en los últimos 90 días. Use "Fecha Desde" para ampliar el rango.
    </div>
    <?= $this->Form->end() ?>
</div>

<div class="spi-card">
    <div class="d-flex align-items-center gap-3" style="margin-bottom:20px;">
        <div class="spi-icon-chip">
            <i class="bi bi-wallet2" aria-hidden="true"></i>
        </div>
        <div>
            <div class="spi-card-title">Crear Registro</div>
            <div class="spi-card-subtitle">El código se autogenera</div>
        </div>
    </div>

    <?= $this->Form->create($record) ?>

    <div class="mb-4">
        <label class="input-label" for="record-operation-center">Centro de Operación <span class="text-danger">*</span></label>
        <select name="operation_center_id" id="record-operation-center" class="form-select select2-enable" required>
            <option value="">Selecciona un centro...</option>
            <?php foreach ($operationCenters as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($record->operation_center_id ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="input-help">El código se generará automáticamente como <code>CM-{año}-{centro}-{consecutivo}</code>.</small>
    </div>

    <div class="mb-4">
        <label class="input-label">Notas</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Notas opcionales sobre este registro..."><?= h($record->notes ?? '') ?></textarea>
    </div>

    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="spi-label flex-shrink-0">
                <i class="bi bi-receipt me-1" aria-hidden="true"></i>Facturas Disponibles
            </span>
            <div class="spi-flex-divider"></div>
            <span class="spi-folder-count"><?= count($availableInvoices) ?></span>
        </div>

        <?php if (empty($availableInvoices) || count($availableInvoices) === 0): ?>
        <div class="banner info">
            <div class="banner-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            <div class="banner-body">
                <div class="banner-msg">
                    No hay facturas de tipo "Caja menor" disponibles<?= !empty($groupFilters['date_from']) || !empty($groupFilters['date_to']) || !empty($groupFilters['operation_center_id']) || !empty($groupFilters['provider_id']) ? ' con los filtros seleccionados' : '' ?>.
                </div>
            </div>
        </div>
        <?php else: ?>
        <select name="invoice_ids[]" class="form-select select2-enable" multiple
                data-placeholder="Seleccione las facturas a agrupar...">
            <?php foreach ($availableInvoices as $inv): ?>
            <?php
                $label = ($inv->invoice_number ?? '#' . $inv->id)
                    . ' - ' . ($inv->provider->name ?? 'Sin proveedor')
                    . ' - ' . ($inv->operation_center->name ?? '')
                    . ' - $' . number_format((float)$inv->amount, 0, ',', '.')
                    . ' (' . ($inv->issue_date?->format('d/m/Y') ?? '') . ')';
            ?>
            <option value="<?= $inv->id ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="input-help">Facturas tipo "Caja menor" en estado "aprobación" sin agrupar.</small>
        <?php endif; ?>
    </div>

    <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--rule);">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear Registro
        </button>
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-default']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>
