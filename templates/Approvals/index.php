<?php
/**
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\ApprovalInboxRowView[] $rows
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string|null $activeStatus
 * @var string|null $activeModule
 * @var string|null $search
 */
use App\Constants\ApprovalInboxConstants;

$this->assign('title', 'Mis Aprobaciones');

$activeStatus = $activeStatus ?? '';
$activeModule = $activeModule ?? '';
$search       = $search ?? '';

$statuses = [
    ''                                    => 'Todas',
    ApprovalInboxConstants::STATUS_PENDING  => 'Pendientes',
    ApprovalInboxConstants::STATUS_APPROVED => 'Aprobadas',
    ApprovalInboxConstants::STATUS_REJECTED => 'Rechazadas',
];
$modules = [
    ''                                      => 'Todos los módulos',
    ApprovalInboxConstants::MODULE_INVOICE  => 'Facturas',
    ApprovalInboxConstants::MODULE_NOVELTY  => 'Novedades',
];
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            Mis Aprobaciones
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            <?= $total ?> <?= $total === 1 ? 'solicitud' : 'solicitudes' ?>
        </div>
    </div>
</div>

<?php /* ════════════════════════ FILTROS ════════════════════════ */ ?>
<?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index']]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q"
               value="<?= h($search) ?>"
               placeholder="Buscar por código o nombre…"
               aria-label="Buscar aprobaciones">
        <?php if ($search !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => 'index', '?' => array_filter([
                    'status' => $activeStatus ?: null,
                    'module' => $activeModule ?: null,
                ])],
                ['escape' => false,
                 'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                 'title'  => 'Limpiar búsqueda']
            ) ?>
        <?php endif; ?>
    </label>
    <select name="module" class="form-select" style="max-width:200px;" onchange="this.form.submit()" aria-label="Filtrar por módulo">
        <?php foreach ($modules as $value => $label): ?>
            <option value="<?= h($value) ?>"<?= $activeModule === $value ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-default">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span>Buscar</span>
    </button>
</div>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR ESTADO ════════════════════════ */ ?>
<div class="d-flex" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por estado">
    <?php foreach ($statuses as $value => $label):
        $isActive = $activeStatus === $value;
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:var(--primary-color);"></span>' : '') . h($label),
            ['action' => 'index', '?' => array_filter([
                'status' => $value ?: null,
                'module' => $activeModule ?: null,
                'q'      => $search ?: null,
            ])],
            [
                'class'         => 'chip' . ($isActive ? ' is-active' : ''),
                'escape'        => false,
                'role'          => 'tab',
                'aria-selected' => $isActive ? 'true' : 'false',
                'style'         => $isActive ? 'color:var(--primary-color);' : '',
            ]
        ) ?>
    <?php endforeach; ?>
</div>

<?php /* ════════════════════════ TABLA ════════════════════════ */ ?>
<?php
$gridStyle = 'display:grid;grid-template-columns:0.8fr 2fr 1.2fr 1.1fr 1.2fr 28px;gap:14px;align-items:center;';
?>
<div class="spi-card" style="padding:0;">
    <?php if (!empty($rows)): ?>
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Módulo</span>
        <span>Código · Contraparte</span>
        <span>Resumen</span>
        <span>Estado</span>
        <span>Asignado</span>
        <span aria-hidden="true"></span>
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $row):
        $href = $this->Url->build(['action' => 'view', $row->module, $row->entityId]);
    ?>
        <a href="<?= h($href) ?>" role="row" class="row-fact"
           style="<?= $gridStyle ?>padding:14px 18px;">

            <?php /* 1. Módulo */ ?>
            <div>
                <span class="pill <?= h($row->moduleBadgeClass) ?> pill-sm">
                    <?= h(strtoupper($row->moduleLabel)) ?>
                </span>
            </div>

            <?php /* 2. Código + contraparte */ ?>
            <div style="min-width:0;">
                <div class="mono" style="font-size:12.5px;font-weight:700;color:var(--text-strong);">
                    <?= h($row->code) ?>
                </div>
                <div style="font-size:10.5px;color:var(--text-faint);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= h($row->counterparty) ?>
                </div>
            </div>

            <?php /* 3. Resumen */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($row->summary) ?>
            </div>

            <?php /* 4. Estado */ ?>
            <div>
                <span class="pill <?= h($row->statusBadgeClass) ?> pill-sm">
                    <?= h(strtoupper($row->statusLabel)) ?>
                </span>
            </div>

            <?php /* 5. Asignado */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-faint);">
                <?= h($row->assignedAtLabel) ?>
            </div>

            <?php /* 6. Chevron */ ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;color:var(--text-faint);">
                <i class="bi bi-chevron-right" style="font-size:14px;" aria-hidden="true"></i>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if (empty($rows)): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral">
                <i class="bi bi-inbox" aria-hidden="true"></i>
            </div>
            <div class="es-title">No tienes aprobaciones</div>
            <div class="es-msg">Aquí aparecerán las facturas y novedades que te asignen para aprobar.</div>
        </div>
    <?php endif; ?>

    <?php /* ════════════════════════ PAGINACIÓN INLINE ════════════════════════ */
    // element('pagination') usa $this->Paginator->* (ORM Paginator) — incompatible
    // con nuestra paginación manual ($total/$page/$perPage). Se renderiza inline
    // usando las clases .pgn/.pgn-btn del sistema de diseño.
    if (!empty($rows) && $total > $perPage):
        $totalPages  = (int)ceil($total / $perPage);
        $queryBase   = array_filter([
            'status' => $activeStatus ?: null,
            'module' => $activeModule ?: null,
            'q'      => $search ?: null,
        ]);
        $pageUrl = function (int $p) use ($queryBase): string {
            return $this->Url->build(['action' => 'index', '?' => $queryBase + ['page' => $p]]);
        };
        $prevDisabled = $page <= 1;
        $nextDisabled = $page >= $totalPages;
    ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small style="font-size:11px;color:var(--text-faint);">
            Mostrando <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $total) ?> de <?= $total ?>
        </small>
        <div class="pgn">
            <?php if ($prevDisabled): ?>
                <span class="pgn-btn disabled" title="Primera"><i class="bi bi-chevron-double-left"></i></span>
                <span class="pgn-btn disabled" title="Anterior"><i class="bi bi-chevron-left"></i></span>
            <?php else: ?>
                <a class="pgn-btn" href="<?= h($pageUrl(1)) ?>" title="Primera"><i class="bi bi-chevron-double-left"></i></a>
                <a class="pgn-btn" href="<?= h($pageUrl($page - 1)) ?>" title="Anterior"><i class="bi bi-chevron-left"></i></a>
            <?php endif; ?>

            <?php
            $window = 2;
            $shown  = [];
            for ($p = 1; $p <= $totalPages; $p++) {
                if ($p === 1 || $p === $totalPages || abs($p - $page) <= $window) {
                    $shown[] = $p;
                }
            }
            $prev = null;
            foreach ($shown as $p):
                if ($prev !== null && $p - $prev > 1): ?>
                    <span class="pgn-ellipsis">…</span>
                <?php endif; ?>
                <?php if ($p === $page): ?>
                    <span class="pgn-btn active"><?= $p ?></span>
                <?php else: ?>
                    <a class="pgn-btn" href="<?= h($pageUrl($p)) ?>"><?= $p ?></a>
                <?php endif;
                $prev = $p;
            endforeach; ?>

            <?php if ($nextDisabled): ?>
                <span class="pgn-btn disabled" title="Siguiente"><i class="bi bi-chevron-right"></i></span>
                <span class="pgn-btn disabled" title="Última"><i class="bi bi-chevron-double-right"></i></span>
            <?php else: ?>
                <a class="pgn-btn" href="<?= h($pageUrl($page + 1)) ?>" title="Siguiente"><i class="bi bi-chevron-right"></i></a>
                <a class="pgn-btn" href="<?= h($pageUrl($totalPages)) ?>" title="Última"><i class="bi bi-chevron-double-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
