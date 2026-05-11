<?php
/**
 * @var \App\View\AppView $this
 * @var array $payments
 * @var array $filters
 * @var array $bankingEntities
 * @var int $page
 * @var int $limit
 * @var int $total
 * @var int $totalPages
 */
$this->assign('title', 'Registro de Pagos');

$typeBadge = [
    'invoice'       => 'bg-primary',
    'advance'       => 'bg-warning text-dark',
    'refund'        => 'bg-danger',
    'debit_note'    => 'bg-primary',
    'receipt'       => 'bg-primary',
    'credit_card'   => 'bg-primary',
    'reintegro_doc' => 'bg-danger',
    'liquidation'   => 'bg-info text-dark',
];

$hasFilters = !empty(array_filter($filters, fn($v) => $v !== null && $v !== ''));
$queryParams = array_filter($filters, fn($v) => $v !== null && $v !== '');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Registro de Pagos</span>
    <span class="text-muted" style="font-size:.8rem;font-weight:500;">
        <?= $total ?> pago<?= $total !== 1 ? 's' : '' ?>
    </span>
</div>

<!-- Filtros -->
<div class="sgi-search-bar mb-4">
    <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="sgi-filter-label" for="filter-type">Tipo</label>
            <select name="type" id="filter-type" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="invoice"       <?= ($filters['type'] ?? '') === 'invoice'       ? 'selected' : '' ?>>Factura</option>
                <option value="advance"       <?= ($filters['type'] ?? '') === 'advance'       ? 'selected' : '' ?>>Anticipo</option>
                <option value="refund"        <?= ($filters['type'] ?? '') === 'refund'        ? 'selected' : '' ?>>Reintegro</option>
                <option value="debit_note"    <?= ($filters['type'] ?? '') === 'debit_note'    ? 'selected' : '' ?>>Nota Débito</option>
                <option value="receipt"       <?= ($filters['type'] ?? '') === 'receipt'       ? 'selected' : '' ?>>Recibo</option>
                <option value="credit_card"   <?= ($filters['type'] ?? '') === 'credit_card'   ? 'selected' : '' ?>>Tarjeta de Crédito</option>
                <option value="liquidation"   <?= ($filters['type'] ?? '') === 'liquidation'   ? 'selected' : '' ?>>Liquidación</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="sgi-filter-label" for="filter-authorized">Estado</label>
            <select name="authorized" id="filter-authorized" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="yes" <?= ($filters['authorized'] ?? '') === 'yes' ? 'selected' : '' ?>>Autorizado</option>
                <option value="no"  <?= ($filters['authorized'] ?? '') === 'no'  ? 'selected' : '' ?>>Pendiente</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="sgi-filter-label" for="filter-banking-entity">Entidad Bancaria</label>
            <select name="banking_entity_id" id="filter-banking-entity" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($bankingEntities as $beId => $beName): ?>
                <option value="<?= $beId ?>" <?= ($filters['banking_entity_id'] ?? '') == $beId ? 'selected' : '' ?>><?= h($beName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="sgi-filter-label" for="filter-date-from">Desde</label>
            <input type="text" name="date_from" id="filter-date-from"
                   class="form-control form-control-sm flatpickr-date"
                   value="<?= h($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="sgi-filter-label" for="filter-date-to">Hasta</label>
            <input type="text" name="date_to" id="filter-date-to"
                   class="form-control form-control-sm flatpickr-date"
                   value="<?= h($filters['date_to'] ?? '') ?>">
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary flex-fill">
                <i class="bi bi-search" aria-hidden="true"></i>
            </button>
            <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                ['action' => 'index'],
                ['class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Limpiar filtros']
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<!-- Tabla -->
<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Referencia</th>
                    <th>Entidad Bancaria</th>
                    <th class="text-end">Monto</th>
                    <th>Fecha Pago</th>
                    <th>Estado</th>
                    <th>Autorizado por</th>
                    <th>Registrado por</th>
                    <th>Origen</th>
                    <th>Fecha Registro</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="10">
                        <div class="sgi-doc-empty">
                            <i class="bi bi-cash-stack sgi-doc-empty-icon" aria-hidden="true"></i>
                            No se encontraron pagos.
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td>
                        <span class="badge <?= $typeBadge[$p['type']] ?? 'bg-dark' ?>">
                            <?= h($p['type_label']) ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-family:monospace;font-weight:600;font-size:.8rem;color:#111;letter-spacing:-.01em;">
                            <?= h($p['reference']) ?>
                        </span>
                    </td>
                    <td style="font-size:.8125rem;color:#555;">
                        <?= h($p['banking_entity']) ?>
                    </td>
                    <td class="text-end fw-semibold" style="white-space:nowrap;color:var(--primary-color);font-size:.875rem;">
                        $ <?= number_format($p['amount'], 0, ',', '.') ?>
                    </td>
                    <td style="font-size:.8125rem;color:#555;white-space:nowrap;">
                        <?= $p['payment_date'] ? date('d/m/Y', strtotime($p['payment_date'])) : '—' ?>
                    </td>
                    <td>
                        <?php if ($p['authorized']): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Autorizado
                        </span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-clock me-1" aria-hidden="true"></i>Pendiente
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['authorized_by']): ?>
                        <div style="font-size:.8125rem;font-weight:500;color:#222;line-height:1.3;">
                            <?= h($p['authorized_by']) ?>
                        </div>
                        <?php if ($p['authorized_date']): ?>
                        <div style="font-size:.7rem;color:#bbb;margin-top:.1rem;">
                            <?= date('d/m/Y', strtotime($p['authorized_date'])) ?>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:#ddd;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8125rem;color:#555;">
                        <?= h($p['created_by']) ?>
                    </td>
                    <td>
                        <?php if (!empty($p['source_url'])): ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-' . h(match ($p['source_type']) {
                                    'scheduling' => 'calendar-check',
                                    'petty_cash' => 'wallet2',
                                    default => 'box-arrow-up-right',
                                }) . ' me-1" aria-hidden="true"></i>' . h($p['source_label']),
                                $p['source_url'],
                                ['class' => 'badge badge-outline-dark text-decoration-none', 'escape' => false]
                            ) ?>
                        <?php else: ?>
                        <span style="color:#ddd;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8125rem;color:#555;white-space:nowrap;">
                        <?= $p['created'] ? date('d/m/Y H:i', strtotime($p['created'])) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1 || $total > 0): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">
            <?php
            $from = min(($page - 1) * $limit + 1, $total);
            $to   = min($page * $limit, $total);
            ?>
            Mostrando <?= $from ?> - <?= $to ?> de <?= $total ?>
        </small>
        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <?= $this->Html->link('«', ['action' => 'index', '?' => array_merge($queryParams, ['page' => 1])], [
                        'class' => 'page-link', 'escape' => false,
                    ]) ?>
                </li>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <?= $this->Html->link('‹', ['action' => 'index', '?' => array_merge($queryParams, ['page' => $page - 1])], [
                        'class' => 'page-link', 'escape' => false,
                    ]) ?>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                if ($start > 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <?php for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <?= $this->Html->link((string)$i, ['action' => 'index', '?' => array_merge($queryParams, ['page' => $i])], [
                        'class' => 'page-link',
                    ]) ?>
                </li>
                <?php endfor; ?>
                <?php if ($end < $totalPages): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <?= $this->Html->link('›', ['action' => 'index', '?' => array_merge($queryParams, ['page' => $page + 1])], [
                        'class' => 'page-link', 'escape' => false,
                    ]) ?>
                </li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <?= $this->Html->link('»', ['action' => 'index', '?' => array_merge($queryParams, ['page' => $totalPages])], [
                        'class' => 'page-link', 'escape' => false,
                    ]) ?>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
