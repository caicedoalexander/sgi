<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyLiquidationDoc> $liquidationDocs
 * @var string|null $statusFilter
 * @var array<string> $visibleStatuses
 */
use App\Constants\NoveltyConstants;
use App\View\Presentation\NoveltyPresentation;

$action = $this->request->getParam('action');
$titleMap = [
    'index' => 'Mis Documentos de Liquidación',
    'all' => 'Todos los Documentos de Liquidación',
    'rejected' => 'Documentos de Liquidación Rechazados',
];
$pageTitle = $titleMap[$action] ?? 'Documentos de Liquidación';

$this->assign('title', $pageTitle);
$statusLabels = NoveltyConstants::STATUS_LABELS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;

// Estados aplicables a documentos de liquidación (excluye registro/aprobacion/rrhh).
$liquidationApplicableStatuses = [
    NoveltyConstants::STATUS_CONTABILIDAD,
    NoveltyConstants::STATUS_REVISION_FIRMAS,
    NoveltyConstants::STATUS_GDP,
    NoveltyConstants::STATUS_TESORERIA,
    NoveltyConstants::STATUS_AUTORIZACION_PAGO,
    NoveltyConstants::STATUS_PAGADA,
    NoveltyConstants::STATUS_RECHAZADA,
];

// Opciones del filtro: en `index` usa los estados del rol; en `all` todos los aplicables.
$filterStatuses = !empty($visibleStatuses ?? []) ? $visibleStatuses : $liquidationApplicableStatuses;
$showFilter = $action !== 'rejected';
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= h($pageTitle) ?></span>
</div>

<?php if ($showFilter) : ?>
<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
            <select name="pipeline_status" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach ($filterStatuses as $s) : ?>
                <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statusFilter) : ?>
            <a href="<?= $this->Url->build(['action' => $action]) ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

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
                <?php foreach ($liquidationDocs as $doc) : ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'edit', $doc->id]) ?>">
                    <td><strong><?= h($doc->liquidation_number) ?></strong></td>
                    <td><?= $periodLabels[$doc->period] ?? h($doc->period) ?></td>
                    <td><span class="badge <?= NoveltyPresentation::STATUS_BADGES[$doc->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$doc->pipeline_status] ?? ucfirst(h($doc->pipeline_status)) ?></span></td>
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
