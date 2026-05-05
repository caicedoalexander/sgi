<?php
/**
 * Fragment HTML del modal "Vincular facturas — Legalización".
 *
 * Renderizado por AdvancesController::linkCandidates() vía AJAX (audit SU-003).
 * Reemplaza el contenido completo de `.modal-content` cuando se abre el modal
 * o se aplica un filtro. Devuelve solo el `.modal-content` — el shell del modal
 * (`<div class="modal fade" id="advanceLinkModal">`) está en legalization.php.
 *
 * Layout: hay dos forms hermanos (no anidados) — uno para los filtros (GET) y
 * otro para el submit de vinculación (POST). Los inputs visibles (checkboxes,
 * botón submit) referencian el form de submit vía atributo `form="..."` para
 * mantenerse al mismo nivel del DOM.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var iterable<\App\Model\Entity\Invoice> $candidates
 * @var array{date_from:string, date_to:string, provider_id:int|string, operation_center_id:int|string} $filters
 * @var array<int, string> $providers
 * @var array<int, string> $operationCenters
 */
$filterUrl = $this->Url->build([
    'controller' => 'Advances',
    'action' => 'linkCandidates',
    $leg->advance_invoice_id,
]);
$submitUrl = $this->Url->build([
    'controller' => 'Advances',
    'action' => 'linkInvoices',
    $leg->advance_invoice_id,
]);
$submitFormId = 'advanceLinkSubmitForm';
$filterFormId = 'advanceLinkFilterForm';
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="bi bi-link-45deg me-1"></i>Vincular facturas — Legalización</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <p class="text-muted small mb-3">
        Facturas tipo "Legalización" sin anticipo asignado.
    </p>

    <div class="p-2 mb-3" style="background:#f9fafb;border:1px solid var(--border-color);">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Desde</label>
                <input form="<?= h($filterFormId) ?>" type="text" name="date_from"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($filters['date_from']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Hasta</label>
                <input form="<?= h($filterFormId) ?>" type="text" name="date_to"
                       class="form-control form-control-sm flatpickr-date"
                       value="<?= h($filters['date_to']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Centro Op.</label>
                <select form="<?= h($filterFormId) ?>" name="operation_center_id" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($operationCenters as $ocId => $ocName): ?>
                    <option value="<?= (int)$ocId ?>" <?= (string)$filters['operation_center_id'] === (string)$ocId ? 'selected' : '' ?>><?= h($ocName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.7rem;color:#888;">Proveedor</label>
                <select form="<?= h($filterFormId) ?>" name="provider_id" class="form-select form-select-sm select2-enable">
                    <option value="">Todos</option>
                    <?php foreach ($providers as $pId => $pName): ?>
                    <option value="<?= (int)$pId ?>" <?= (string)$filters['provider_id'] === (string)$pId ? 'selected' : '' ?>><?= h($pName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 ms-auto">
                <button form="<?= h($filterFormId) ?>" type="submit" class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Buscar
                </button>
            </div>
        </div>
    </div>

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
                    <td>
                        <input form="<?= h($submitFormId) ?>" type="checkbox" name="invoice_ids[]"
                               value="<?= (int)$c->id ?>" class="form-check-input">
                    </td>
                    <td style="font-family:monospace;font-weight:600;"><?= h($c->invoice_number ?: '#' . $c->id) ?></td>
                    <td><?= h($c->provider->name ?? ($c->employee->full_name ?? '—')) ?></td>
                    <td><?= h($c->operation_center->name ?? '—') ?></td>
                    <td><?= $c->issue_date ? $c->issue_date->format('d/m/Y') : '—' ?></td>
                    <td class="text-end">$ <?= number_format((float)$c->amount, 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$hasAny): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">
                    <i class="bi bi-info-circle me-1"></i>No hay facturas disponibles<?= !empty(array_filter($filters)) ? ' con los filtros seleccionados' : '' ?>.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
    <button form="<?= h($submitFormId) ?>" type="submit" class="btn sgi-btn-primary">
        <i class="bi bi-link-45deg me-1"></i>Vincular seleccionadas
    </button>
</div>

<form id="<?= h($filterFormId) ?>" method="get" action="<?= h($filterUrl) ?>" data-modal-filter-form></form>
<?= $this->Form->create(null, ['url' => $submitUrl, 'id' => $submitFormId]) ?>
<?= $this->Form->end() ?>
