<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyLiquidationDoc> $liquidationDocs
 * @var string|null $statusFilter
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Documentos de Liquidación');

$statusBadges = [
    'contabilidad' => 'bg-primary',
    'firmas_aprobacion' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];
$statusLabels = NoveltyConstants::STATUS_LABELS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Documentos de Liquidación</span>
</div>

<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
            <select name="pipeline_status" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statusFilter): ?>
            <a href="<?= $this->Url->build(['action' => 'index']) ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No. Liquidación</th>
                    <th>Período</th>
                    <th>Estado</th>
                    <th>Novedades</th>
                    <th>Elaborado por</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($liquidationDocs as $doc): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $doc->id]) ?>">
                    <td><strong><?= h($doc->liquidation_number) ?></strong></td>
                    <td><?= $periodLabels[$doc->period] ?? h($doc->period) ?></td>
                    <td><span class="badge <?= $statusBadges[$doc->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$doc->pipeline_status] ?? ucfirst(h($doc->pipeline_status)) ?></span></td>
                    <td><span class="badge bg-light text-dark"><?= count($doc->employee_novelties) ?></span></td>
                    <td style="font-size:.8125rem;"><?= h($doc->performed_by_user->full_name ?? '—') ?></td>
                    <td style="font-size:.8125rem;color:#888"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
