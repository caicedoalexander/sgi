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
    'invoice'       => 'pill-primary-soft',
    'advance'       => 'pill-warning-soft',
    'refund'        => 'pill-danger-soft',
    'debit_note'    => 'pill-primary-soft',
    'receipt'       => 'pill-primary-soft',
    'credit_card'   => 'pill-primary-soft',
    'reintegro_doc' => 'pill-danger-soft',
    'liquidation'   => 'pill-info-soft',
];

$typeOptions = [
    'invoice'     => 'Factura',
    'advance'     => 'Anticipo',
    'refund'      => 'Reintegro',
    'debit_note'  => 'Nota Débito',
    'receipt'     => 'Recibo',
    'credit_card' => 'Tarjeta de Crédito',
    'liquidation' => 'Liquidación',
];

$hasFilters = !empty(array_filter($filters, fn($v) => $v !== null && $v !== ''));
$queryParams = array_filter($filters, fn($v) => $v !== null && $v !== '');

// Solicitud · Entidad · Monto · Fechas · Estado · Registrado por
$gridCols = '1.2fr 1.4fr 1fr 1fr 1.3fr 1fr';
?>

<?= $this->element('cdn_select2') ?>
<?php $filterCount = count($queryParams); ?>

<!-- ═══ Header ═══ -->
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:18px;">
    <div>
        <span class="spi-page-title">Registro de Pagos</span>
        <div class="spi-body-faint mt-1" style="font-size:var(--fs-body-sm);">
            <span class="spi-fg-muted"><?= $total ?> pago<?= $total !== 1 ? 's' : '' ?> · vista consolidada</span>
        </div>
    </div>
    <div>
        <button type="button" class="btn btn-default" data-filter-drawer-open aria-label="Filtros">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <span>Filtros<?php if ($filterCount > 0): ?> · <span style="color:var(--primary-color);font-weight:700;"><?= $filterCount ?></span><?php endif; ?></span>
        </button>
    </div>
</div>

<!-- ═══ Filtros ═══ -->
<?= $this->Form->create(null, [
    'type' => 'get',
    'valueSources' => ['query'],
]) ?>
<?php ob_start(); ?>
    <div class="filter-field">
        <label class="input-label" for="pr-type">Tipo</label>
        <select name="type" id="pr-type" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach ($typeOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ($filters['type'] ?? '') === $value ? 'selected' : '' ?>>
                <?= h($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label class="input-label" for="pr-authorized">Estado</label>
        <select name="authorized" id="pr-authorized" class="form-select form-select-sm">
            <option value="">Todos</option>
            <option value="yes" <?= ($filters['authorized'] ?? '') === 'yes' ? 'selected' : '' ?>>Autorizado</option>
            <option value="no"  <?= ($filters['authorized'] ?? '') === 'no'  ? 'selected' : '' ?>>Pendiente</option>
        </select>
    </div>
    <div class="filter-field">
        <label class="input-label" for="pr-entity">Entidad</label>
        <select name="banking_entity_id" id="pr-entity" class="form-select form-select-sm select2-enable">
            <option value="">Todas</option>
            <?php foreach ($bankingEntities as $beId => $beName): ?>
            <option value="<?= h((string)$beId) ?>" <?= ($filters['banking_entity_id'] ?? '') == $beId ? 'selected' : '' ?>>
                <?= h($beName) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label class="input-label" for="pr-range">Período</label>
        <?= $this->element('date_range_filter', [
            'id' => 'pr-range',
            'from' => $filters['date_from'] ?? '',
            'to' => $filters['date_to'] ?? '',
            'inputStyle' => 'width:100%;',
        ]) ?>
    </div>
<?php $filterFields = ob_get_clean(); ?>
<?= $this->element('filter_drawer', [
    'body' => $filterFields,
    'count' => $filterCount,
    'clearUrl' => ['action' => 'index'],
]) ?>
<?= $this->Form->end() ?>

<!-- ═══ Tabla ═══ -->
<div class="spi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span>Tipo · Referencia</span>
        <span>Entidad · Origen</span>
        <span style="text-align:right;">Monto</span>
        <span>Fecha pago</span>
        <span>Estado · Autorizado por</span>
        <span>Registrado por</span>
    </div>

    <?php if (empty($payments)): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral">
            <i class="bi bi-cash-stack" aria-hidden="true"></i>
        </div>
        <div class="es-title">Sin pagos</div>
        <div class="es-msg">No se encontraron pagos para los filtros aplicados.</div>
    </div>
    <?php else: ?>
    <?php foreach ($payments as $p):
        $hasSource = !empty($p['source_url']);
        $tag = $hasSource ? 'a' : 'div';
        $sourceIcon = match ($p['source_type'] ?? '') {
            'scheduling' => 'calendar-check',
            'petty_cash' => 'wallet2',
            default => 'box-arrow-up-right',
        };
    ?>
    <<?= $tag ?> class="row-fact" style="grid-template-columns:<?= $gridCols ?>;<?= $hasSource ? '' : 'cursor:default;' ?>"
        <?= $hasSource ? 'href="' . h($this->Url->build($p['source_url'])) . '"' : '' ?> role="row">

        <!-- Tipo · Referencia -->
        <div style="min-width:0;">
            <span class="pill pill-sm <?= h($typeBadge[$p['type']] ?? 'pill-muted') ?>">
                <?= h($p['type_label']) ?>
            </span>
            <div class="mono" style="font-size:11.5px;font-weight:700;color:var(--text-strong);margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($p['reference']) ?>
            </div>
        </div>

        <!-- Entidad · Origen -->
        <div style="min-width:0;">
            <div style="font-size:12.5px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($p['banking_entity']) ?: '—' ?>
            </div>
            <?php if ($hasSource): ?>
            <div class="d-inline-flex align-items-center" style="gap:4px;font-size:10.5px;color:var(--text-faint);margin-top:3px;">
                <i class="bi bi-<?= h($sourceIcon) ?>" aria-hidden="true"></i><?= h($p['source_label']) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Monto -->
        <div class="mono" style="text-align:right;font-weight:700;color:var(--primary-color);font-size:14px;white-space:nowrap;">
            $ <?= number_format((float)$p['amount'], 0, ',', '.') ?>
        </div>

        <!-- Fecha pago + registro -->
        <div class="mono" style="font-size:11.5px;color:var(--text-default);white-space:nowrap;">
            <?= $p['payment_date'] ? date('d/m/Y', strtotime($p['payment_date'])) : '—' ?>
            <?php if (!empty($p['created'])): ?>
            <div style="font-size:10px;color:var(--text-faint);margin-top:2px;">
                Reg. <?= date('d/m/Y H:i', strtotime($p['created'])) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Estado · Autorizado por -->
        <div style="min-width:0;">
            <?php if ($p['authorized']): ?>
            <span class="pill pill-sm pill-primary-soft">
                <i class="bi bi-check" aria-hidden="true"></i>Autorizado
            </span>
            <?php else: ?>
            <span class="pill pill-sm pill-warning-soft">
                <i class="bi bi-clock" aria-hidden="true"></i>Pendiente
            </span>
            <?php endif; ?>
            <div style="font-size:10px;color:var(--text-faint);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $p['authorized_by'] ? h($p['authorized_by']) : '—' ?>
                <?php if (!empty($p['authorized_date'])): ?>
                · <?= date('d/m/Y', strtotime($p['authorized_date'])) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Registrado por -->
        <div style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= h($p['created_by']) ?: '—' ?>
        </div>
    </<?= $tag ?>>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Paginación (manual: el servicio pagina cross-módulo en SQL) -->
    <?php if ($total > 0): ?>
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
