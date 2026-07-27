<?php
/**
 * Bandeja "Mis Pendientes" — tabla unificada cross-módulo.
 *
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\PendingRowView[] $rows
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string|null $activeModule
 * @var string|null $search
 */
use App\View\Presentation\PendingModuleMeta;

$this->assign('title', 'Mis Pendientes');

$activeModule = $activeModule ?? '';
$search       = $search ?? '';

/* Chips de módulo: [slug => label] desde el registry (fuente única). */
$moduleChips = ['' => 'Todos'];
foreach (PendingModuleMeta::MODULES as $slug => $meta) {
    $moduleChips[$slug] = $meta['label'];
}

$gridStyle = 'display:grid;grid-template-columns:1fr 2.2fr 1.4fr 1.9fr 1fr 28px;gap:14px;align-items:center;';
?>

<?php /* ════════════════════════ HEADER ════════════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start" style="padding:4px 0 16px;">
    <div>
        <div style="font-size:22px;font-weight:700;color:var(--text-strong);letter-spacing:-0.2px;">
            Mis Pendientes
        </div>
        <div style="font-size:12px;color:var(--text-faint);margin-top:4px;">
            <?= (int)$total ?> <?= $total === 1 ? 'pendiente' : 'pendientes' ?>
        </div>
    </div>
</div>

<?php /* ════════════════════════ BUSCADOR ════════════════════════ */ ?>
<?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index']]) ?>
<div class="d-flex align-items-stretch" style="gap:8px;margin-bottom:14px;">
    <label class="input flex-grow-1" style="margin:0;">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q"
               value="<?= h($search) ?>"
               placeholder="Buscar por código o contraparte…"
               aria-label="Buscar pendientes">
        <?php if ($search !== ''): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x" aria-hidden="true"></i>',
                ['action' => 'index', '?' => array_filter(['module' => $activeModule ?: null])],
                ['escape' => false,
                 'style'  => 'background:transparent;border:0;color:var(--text-faint);padding:4px;display:inline-flex;',
                 'title'  => 'Limpiar búsqueda']
            ) ?>
        <?php endif; ?>
    </label>
    <button type="submit" class="btn btn-default">
        <i class="bi bi-search" aria-hidden="true"></i><span>Buscar</span>
    </button>
</div>
<?php if ($activeModule !== ''): ?>
    <input type="hidden" name="module" value="<?= h($activeModule) ?>">
<?php endif; ?>
<?= $this->Form->end() ?>

<?php /* ════════════════════════ CHIPS POR MÓDULO ════════════════════════ */ ?>
<div class="d-flex flex-wrap" style="gap:4px;margin-bottom:14px;" role="tablist" aria-label="Filtrar por módulo">
    <?php foreach ($moduleChips as $slug => $label):
        $isActive = $activeModule === $slug;
    ?>
        <?= $this->Html->link(
            ($isActive ? '<span class="dot" style="background:var(--primary-color);"></span>' : '') . h($label),
            ['action' => 'index', '?' => array_filter([
                'module' => $slug ?: null,
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
<div class="spi-card" style="padding:0;">
    <?php if (!empty($rows)): ?>
    <div style="<?= $gridStyle ?>padding:12px 18px;background:var(--bg-subtle);font-size:10px;font-weight:700;color:var(--text-faint);letter-spacing:0.8px;text-transform:uppercase;" role="row">
        <span>Módulo</span>
        <span>Código · Contraparte</span>
        <span>Resumen</span>
        <span>Estado · Pipeline</span>
        <span>Fecha</span>
        <span aria-hidden="true"></span>
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $row):
        $href = $this->Url->build($row->route);
    ?>
        <a href="<?= h($href) ?>" role="row" class="row-fact" style="<?= $gridStyle ?>padding:14px 18px;">
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
                <?= h($row->summary ?: '—') ?>
            </div>

            <?php /* 4. Estado · Pipeline */ ?>
            <div style="min-width:0;">
                <?php if ($row->pipelineSteps !== [] && $row->stageIdx >= 0): ?>
                    <div class="pipeline-mini <?= h($row->pipelineVariant) ?>" aria-hidden="true" style="margin-bottom:5px;max-width:100%;">
                        <?php for ($s = 0, $n = count($row->pipelineSteps); $s < $n; $s++): ?>
                            <div class="<?= $s <= $row->stageIdx ? 'on' : '' ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
                <span class="pill <?= h($row->pillClass) ?> pill-sm">
                    <?= h(strtoupper($row->statusLabel)) ?>
                </span>
            </div>

            <?php /* 5. Fecha */ ?>
            <div class="mono" style="font-size:12px;color:var(--text-faint);">
                <?= h($row->dateLabel) ?>
            </div>

            <?php /* 6. Chevron */ ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;color:var(--text-faint);">
                <i class="bi bi-chevron-right" style="font-size:14px;" aria-hidden="true"></i>
            </div>
        </a>
    <?php endforeach; ?>

    <?php if (empty($rows)): ?>
        <div class="empty-state" style="padding:48px 16px;">
            <div class="es-icon es-icon-neutral"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
            <div class="es-title">No tienes pendientes</div>
            <div class="es-msg">Aquí aparecerán los ítems de todos los módulos que requieren tu acción.</div>
        </div>
    <?php endif; ?>

    <?php /* ════════════════════════ PAGINACIÓN INLINE ════════════════════════ */
    if (!empty($rows) && $total > $perPage):
        $totalPages = (int)ceil($total / $perPage);
        $queryBase  = array_filter(['module' => $activeModule ?: null, 'q' => $search ?: null]);
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
                <span class="pgn-btn disabled"><i class="bi bi-chevron-left"></i></span>
            <?php else: ?>
                <a class="pgn-btn" href="<?= h($pageUrl($page - 1)) ?>"><i class="bi bi-chevron-left"></i></a>
            <?php endif; ?>
            <?php
            for ($p = 1; $p <= $totalPages; $p++):
                if ($p === $page): ?>
                    <span class="pgn-btn active"><?= $p ?></span>
                <?php else: ?>
                    <a class="pgn-btn" href="<?= h($pageUrl($p)) ?>"><?= $p ?></a>
                <?php endif;
            endfor; ?>
            <?php if ($nextDisabled): ?>
                <span class="pgn-btn disabled"><i class="bi bi-chevron-right"></i></span>
            <?php else: ?>
                <a class="pgn-btn" href="<?= h($pageUrl($page + 1)) ?>"><i class="bi bi-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
