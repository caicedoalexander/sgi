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
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<!-- Filtros de agrupación -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-funnel"></i>
        <span style="font-size:.85rem;font-weight:600;">Filtrar facturas para agrupar</span>
    </div>
    <div class="card-body py-2 px-3">
        <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.75rem;">Fecha Emisión Desde</label>
                <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($groupFilters['date_from'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.75rem;">Fecha Emisión Hasta</label>
                <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($groupFilters['date_to'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.75rem;">Centro de Operación</label>
                <select name="operation_center_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($operationCenters as $id => $name): ?>
                    <option value="<?= $id ?>" <?= ($groupFilters['operation_center_id'] ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Buscar</button>
                <?= $this->Html->link('Limpiar', ['action' => 'add'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<div class="card card-primary">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
            <i class="bi bi-wallet2"></i>
        </div>
        <div>
            <div style="font-size:.95rem;font-weight:700;color:#111;">Crear Registro</div>
            <div style="font-size:.72rem;color:#aaa;">El código se generará automáticamente</div>
        </div>
    </div>
    <div class="card-body p-4">
        <?= $this->Form->create($record) ?>

        <div class="mb-4">
            <label class="form-label">Notas</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Notas opcionales sobre este registro..."><?= h($record->notes ?? '') ?></textarea>
        </div>

        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-receipt me-1"></i>Facturas Disponibles
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
                <span class="sgi-folder-count"><?= count($availableInvoices) ?></span>
            </div>

            <?php if (empty($availableInvoices) || count($availableInvoices) === 0): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                No hay facturas de tipo "Caja menor" disponibles<?= !empty($groupFilters['date_from']) || !empty($groupFilters['date_to']) || !empty($groupFilters['operation_center_id']) ? ' con los filtros seleccionados' : '' ?>.
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
                <i class="bi bi-plus-lg me-1"></i>Crear Registro
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
