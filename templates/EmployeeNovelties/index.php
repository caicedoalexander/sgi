<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var array $noveltyTypes
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Novedades de Empleados');

$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Novedades de Empleados</span>
    <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1"></i>Nueva Novedad',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
            <select name="status" class="form-select form-select-sm" style="max-width:160px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach (NoveltyConstants::STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statusFilter || $typeFilter): ?>
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
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $novelty->id]) ?>">
                    <td><?= h($novelty->employee->full_name ?? '—') ?></td>
                    <td><?= h($novelty->novelty_type->name ?? '—') ?></td>
                    <td><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></td>
                    <td><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></td>
                    <td>
                        <?php if ($novelty->is_paid): ?>
                            <span class="badge bg-success">Sí</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $statusBadges[$novelty->status] ?? 'bg-secondary' ?>"><?= ucfirst(h($novelty->status)) ?></span></td>
                    <td style="font-size:.8125rem;color:#888"><?= h($novelty->registered_by_user->full_name ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
