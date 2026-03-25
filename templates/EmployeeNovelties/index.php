<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var array $noveltyTypes
 * @var array $visibleStatuses
 */
use App\Constants\NoveltyConstants;

$action = $this->request->getParam('action');

$pageTitles = [
    'all' => 'Todas las Novedades',
    'rejected' => 'Novedades Rechazadas',
];
$pageTitle = $pageTitles[$action] ?? 'Mis Novedades';
$this->assign('title', $pageTitle);

$linkAction = ($action === 'index') ? 'edit' : 'view';

$statusBadges = [
    'aprobacion' => 'bg-warning text-dark',
    'rrhh' => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$statusLabels = NoveltyConstants::STATUS_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $pageTitle ?></span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nueva Novedad',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- View navigation -->
<div class="d-flex gap-2 mb-3">
    <?= $this->Html->link('Mis Novedades', ['action' => 'index'],
        ['class' => 'btn btn-sm ' . ($action === 'index' ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Todas las Novedades', ['action' => 'all'],
        ['class' => 'btn btn-sm ' . ($action === 'all' ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Rechazadas', ['action' => 'rejected'],
        ['class' => 'btn btn-sm ' . ($action === 'rejected' ? 'btn-danger' : 'btn-outline-danger')]) ?>
</div>

<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
            <?php if ($action !== 'rejected'): ?>
            <select name="pipeline_status" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statusFilter || $typeFilter): ?>
            <a href="<?= $this->Url->build(['action' => $action]) ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Tipo de Novedad</th>
                    <th>Fecha Permiso</th>
                    <th>Horario</th>
                    <th>Remunerado</th>
                    <th>Estado</th>
                    <th>Registrado por</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($novelties as $novelty): ?>
                <tr class="clickable-row <?= $novelty->isRejected() ? 'table-danger' : '' ?>"
                    data-href="<?= $this->Url->build(['action' => $linkAction, $novelty->id]) ?>">
                    <td><?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?></td>
                    <td><?= h($novelty->novelty_type->name ?? '—') ?></td>
                    <td><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></td>
                    <td><?= $scheduleLabels[$novelty->schedule_type] ?? '—' ?></td>
                    <td>
                        <?php if ($novelty->is_paid): ?>
                            <span class="badge bg-success">Sí</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $statusBadges[$novelty->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)) ?></span></td>
                    <td style="font-size:.8125rem;color:#888"><?= h($novelty->registered_by_user->full_name ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
