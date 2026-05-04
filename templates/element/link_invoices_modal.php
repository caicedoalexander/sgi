<?php
/**
 * Modal genérico para vincular facturas a un registro (Anticipo, Reintegro, Caja Menor).
 *
 * @var \App\View\AppView $this
 * @var string $modalId               DOM id del modal (ej. "linkInvoicesModal")
 * @var array $formUrl                URL array para el form POST que hace la vinculación
 * @var iterable $candidates          Facturas candidatas (Invoice entities con provider/employee si aplica)
 * @var string $title                 Título del modal
 * @var string|null $helpText         Texto de ayuda opcional bajo el título
 * @var array|null $filterUrl         URL array para el form GET de filtros (opcional)
 * @var array|null $filters           Valores actuales de filtros (date_from, date_to, operation_center_id)
 * @var iterable|null $operationCenters  Lista [id => name] para filtro de centro op (opcional)
 */
$title       = $title       ?? 'Vincular Facturas';
$helpText    = $helpText    ?? null;
$filterUrl   = $filterUrl   ?? null;
$filters     = $filters     ?? [];
$operationCenters = $operationCenters ?? [];
$filterFormId = $modalId . 'Filter';
?>
<div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <?= $this->Form->create(null, ['url' => $formUrl]) ?>
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-1"></i><?= h($title) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($helpText): ?>
                <p class="text-muted small mb-3"><?= h($helpText) ?></p>
                <?php endif; ?>

                <?php if ($filterUrl !== null): ?>
                <div class="p-2 mb-3" style="background:#f9fafb;border:1px solid var(--border-color);">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Desde</label>
                            <input type="text" form="<?= h($filterFormId) ?>" name="date_from" class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($filters['date_from'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Hasta</label>
                            <input type="text" form="<?= h($filterFormId) ?>" name="date_to" class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($filters['date_to'] ?? '') ?>">
                        </div>
                        <?php if (!empty($operationCenters)): ?>
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Centro Op.</label>
                            <select form="<?= h($filterFormId) ?>" name="operation_center_id" class="form-select form-select-sm">
                                <option value="">Todos</option>
                                <?php foreach ($operationCenters as $ocId => $ocName): ?>
                                <option value="<?= (int)$ocId ?>" <?= ($filters['operation_center_id'] ?? '') == $ocId ? 'selected' : '' ?>><?= h($ocName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <button type="submit" form="<?= h($filterFormId) ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-search me-1"></i>Buscar
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="table-responsive" style="max-height: 50vh;">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th style="width:32px;"></th>
                                <th># Factura</th>
                                <th>Beneficiario</th>
                                <th>Centro Op.</th>
                                <th>Fecha</th>
                                <th class="text-end">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $hasAny = false; foreach ($candidates as $c): $hasAny = true; ?>
                            <tr>
                                <td><?= $this->Form->checkbox('invoice_ids[]', ['value' => $c->id, 'hiddenField' => false]) ?></td>
                                <td style="font-family:monospace;font-weight:600;"><?= h($c->invoice_number ?: '#' . $c->id) ?></td>
                                <td><?= h($c->provider->name ?? ($c->employee->full_name ?? '—')) ?></td>
                                <td><?= h($c->operation_center->name ?? '—') ?></td>
                                <td><?= $c->issue_date ? $c->issue_date->format('d/m/Y') : '—' ?></td>
                                <td class="text-end">$ <?= number_format((float)$c->amount, 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$hasAny): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">
                                <i class="bi bi-info-circle me-1"></i>No hay facturas disponibles<?= !empty($filters) ? ' con los filtros seleccionados' : '' ?>.
                            </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn sgi-btn-primary"><i class="bi bi-link-45deg me-1"></i>Vincular seleccionadas</button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php if ($filterUrl !== null): ?>
<form id="<?= h($filterFormId) ?>" method="get" action="<?= $this->Url->build($filterUrl) ?>"></form>
<?php endif; ?>
