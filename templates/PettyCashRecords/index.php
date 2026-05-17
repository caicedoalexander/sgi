<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $records
 */
$this->assign('title', 'Caja Menor');

$statusBadge = \App\View\Presentation\PettyCashPresentation::STATUS_BADGES;
$statusLabels = \App\Constants\PettyCashConstants::STATUS_LABELS;

$params = $this->request->getQueryParams();
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Caja Menor</span>
    <?php if (!empty($userPermissions['petty_cash']['can_create'])): ?>
    <div>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Registro',
            ['action' => 'add'],
            ['class' => 'btn-primary btn btn-sm', 'escape' => false]
        ) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Filtros -->
<div class="card card-primary mb-4">
    <div class="card-body py-2 px-3">
        <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Código</label>
                <input type="text" name="code" class="form-control form-control-sm"
                       value="<?= h($params['code'] ?? '') ?>" placeholder="CM-2026-...">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Estado</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($params['status'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Desde</label>
                <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($params['date_from'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Hasta</label>
                <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($params['date_to'] ?? '') ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1" aria-hidden="true"></i>Filtrar</button>
                <?= $this->Html->link('Limpiar', ['action' => 'index'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<!-- Tabla -->
<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Estado</th>
                    <th class="text-end">Total</th>
                    <th class="text-center"># Facturas</th>
                    <th>Creado por</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records) || $records->count() === 0): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No hay registros de Caja Menor.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($records as $record): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'edit', $record->id]) ?>">
                    <td>
                        <span class="mono" style="font-weight:600;font-size:.85rem;">
                            <?= h($record->code) ?>
                        </span>
                    </td>
                    <td>
                        <span class="pill <?= $statusBadge[$record->status] ?? 'pill-muted' ?>">
                            <?= $statusLabels[$record->status] ?? $record->status ?>
                        </span>
                    </td>
                    <td class="text-end" style="font-weight:600;">
                        $ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?>
                    </td>
                    <td class="text-center">
                        <?= count($record->invoices ?? []) ?>
                    </td>
                    <td><?= $record->hasValue('created_by_user') ? h($record->created_by_user->full_name) : '—' ?></td>
                    <td><?= $record->created?->format('d/m/Y') ?? '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>
