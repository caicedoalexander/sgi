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
    'invoice' => 'bg-primary',
    'liquidation' => 'bg-info text-dark',
    'petty_cash' => 'bg-warning text-dark',
    'legalization' => 'bg-secondary',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Registro de Pagos</span>
    <span class="text-muted" style="font-size:.8rem;"><?= $total ?> pago<?= $total !== 1 ? 's' : '' ?></span>
</div>

<!-- Filtros -->
<div class="card card-primary mb-4">
    <div class="card-body py-2 px-3">
        <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.75rem;">Tipo</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="invoice" <?= ($filters['type'] ?? '') === 'invoice' ? 'selected' : '' ?>>Factura</option>
                    <option value="liquidation" <?= ($filters['type'] ?? '') === 'liquidation' ? 'selected' : '' ?>>Liquidacion</option>
                    <option value="legalization" <?= ($filters['type'] ?? '') === 'legalization' ? 'selected' : '' ?>>Legalizacion</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.75rem;">Estado</label>
                <select name="authorized" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="yes" <?= ($filters['authorized'] ?? '') === 'yes' ? 'selected' : '' ?>>Autorizado</option>
                    <option value="no" <?= ($filters['authorized'] ?? '') === 'no' ? 'selected' : '' ?>>Pendiente</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.75rem;">Entidad Bancaria</label>
                <select name="banking_entity_id" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($bankingEntities as $beId => $beName): ?>
                    <option value="<?= $beId ?>" <?= ($filters['banking_entity_id'] ?? '') == $beId ? 'selected' : '' ?>><?= h($beName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.75rem;">Desde</label>
                <input type="text" name="date_from" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($filters['date_from'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.75rem;">Hasta</label>
                <input type="text" name="date_to" class="form-control form-control-sm flatpickr-date"
                       value="<?= h($filters['date_to'] ?? '') ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm sgi-btn-primary flex-fill">
                    <i class="bi bi-search"></i>
                </button>
                <?= $this->Html->link('<i class="bi bi-x-lg"></i>', ['action' => 'index'], [
                    'class' => 'btn btn-sm btn-outline-secondary', 'escape' => false,
                ]) ?>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<!-- Tabla -->
<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
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
                <tr><td colspan="10" class="text-center text-muted py-4">No se encontraron pagos.</td></tr>
                <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><span class="badge <?= $typeBadge[$p['type']] ?? 'bg-dark' ?>"><?= h($p['type_label']) ?></span></td>
                    <td><?= h($p['reference']) ?></td>
                    <td><?= h($p['banking_entity']) ?></td>
                    <td class="text-end">$ <?= number_format($p['amount'], 0, ',', '.') ?></td>
                    <td><?= $p['payment_date'] ? date('d/m/Y', strtotime($p['payment_date'])) : '—' ?></td>
                    <td>
                        <?php if ($p['authorized']): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Autorizado</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['authorized_by']): ?>
                        <?= h($p['authorized_by']) ?>
                        <?php if ($p['authorized_date']): ?>
                        <br><small class="text-muted"><?= date('d/m/Y', strtotime($p['authorized_date'])) ?></small>
                        <?php endif; ?>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </td>
                    <td><?= h($p['created_by']) ?></td>
                    <td>
                        <?php if (!empty($p['source_url'])): ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-' . h(match ($p['source_type']) {
                                    'scheduling' => 'calendar-check',
                                    'petty_cash' => 'wallet2',
                                    'legalization' => 'journal-check',
                                    default => 'dash',
                                }) . ' me-1"></i>' . h($p['source_label']),
                                $p['source_url'],
                                ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
                            ) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $p['created'] ? date('d/m/Y H:i', strtotime($p['created'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $queryParams = array_filter($filters, fn($v) => $v !== null && $v !== '');
        ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <?= $this->Html->link('&laquo;', ['action' => 'index', '?' => array_merge($queryParams, ['page' => $page - 1])], [
                'class' => 'page-link', 'escape' => false,
            ]) ?>
        </li>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <?= $this->Html->link((string)$i, ['action' => 'index', '?' => array_merge($queryParams, ['page' => $i])], [
                'class' => 'page-link',
            ]) ?>
        </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <?= $this->Html->link('&raquo;', ['action' => 'index', '?' => array_merge($queryParams, ['page' => $page + 1])], [
                'class' => 'page-link', 'escape' => false,
            ]) ?>
        </li>
    </ul>
</nav>
<?php endif; ?>
