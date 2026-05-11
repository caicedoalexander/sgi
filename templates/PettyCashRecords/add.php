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

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Registro de Caja Menor</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<!-- Filtros de agrupación -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span class="fw-semibold" style="font-size:.85rem;">Filtrar facturas para agrupar</span>
    </div>
    <div class="card-body py-2 px-3">
        <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label mb-1 sgi-filter-label">Fecha Emisión Desde</label>
                <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($groupFilters['date_from'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1 sgi-filter-label">Fecha Emisión Hasta</label>
                <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($groupFilters['date_to'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 sgi-filter-label">Centro de Operación</label>
                <select name="operation_center_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($operationCenters as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($groupFilters['operation_center_id'] ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 sgi-filter-label">Proveedor</label>
                <select name="provider_id" class="form-select form-select-sm select2-enable">
                    <option value="">Todos</option>
                    <?php foreach (($providers ?? []) as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($groupFilters['provider_id'] ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar</button>
                <?= $this->Html->link('Limpiar', ['action' => 'add'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
            </div>
        </div>
        <div class="mt-2 sgi-hint">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Por defecto se muestran facturas emitidas en los últimos 90 días. Use "Fecha Desde" para ampliar el rango.
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<div class="card card-primary">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="sgi-icon-chip">
            <i class="bi bi-wallet2" aria-hidden="true"></i>
        </div>
        <div>
            <div class="sgi-card-title">Crear Registro</div>
            <div class="sgi-card-subtitle">El código se autogenera</div>
        </div>
    </div>
    <div class="card-body p-4">
        <?= $this->Form->create($record) ?>

        <div class="mb-4">
            <label class="form-label" for="record-operation-center">Centro de Operación <span class="text-danger">*</span></label>
            <select name="operation_center_id" id="record-operation-center" class="form-select select2-enable" required>
                <option value="">Selecciona un centro...</option>
                <?php foreach ($operationCenters as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($record->operation_center_id ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">El código se generará automáticamente como <code>CM-{año}-{centro}-{consecutivo}</code>.</small>
        </div>

        <div class="mb-4">
            <label class="form-label">Notas</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Notas opcionales sobre este registro..."><?= h($record->notes ?? '') ?></textarea>
        </div>

        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="sgi-micro-caps flex-shrink-0">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Facturas Disponibles
                </span>
                <div class="sgi-flex-divider"></div>
                <span class="sgi-folder-count"><?= count($availableInvoices) ?></span>
            </div>

            <?php if (empty($availableInvoices) || count($availableInvoices) === 0): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                No hay facturas de tipo "Caja menor" disponibles<?= !empty($groupFilters['date_from']) || !empty($groupFilters['date_to']) || !empty($groupFilters['operation_center_id']) || !empty($groupFilters['provider_id']) ? ' con los filtros seleccionados' : '' ?>.
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
            <small class="text-muted">Facturas tipo "Caja menor" en estado "aprobación" sin agrupar.</small>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2 pt-2" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="sgi-btn-primary btn">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear Registro
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
