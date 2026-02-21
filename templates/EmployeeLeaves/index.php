<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeLeave> $employeeLeaves
 * @var string|null $statusFilter
 */
$this->assign('title', 'Permisos y Licencias');

$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Permisos y Licencias</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nueva Solicitud',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Filtros -->
<div class="d-flex gap-2 mb-3">
    <?= $this->Html->link('Todos', ['action' => 'index'],
        ['class' => 'btn btn-sm ' . (empty($statusFilter) ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Pendientes', ['action' => 'index', '?' => ['status' => 'pendiente']],
        ['class' => 'btn btn-sm ' . ($statusFilter === 'pendiente' ? 'btn-warning' : 'btn-outline-warning')]) ?>
    <?= $this->Html->link('Aprobados', ['action' => 'index', '?' => ['status' => 'aprobado']],
        ['class' => 'btn btn-sm ' . ($statusFilter === 'aprobado' ? 'btn-success' : 'btn-outline-success')]) ?>
    <?= $this->Html->link('Rechazados', ['action' => 'index', '?' => ['status' => 'rechazado']],
        ['class' => 'btn btn-sm ' . ($statusFilter === 'rechazado' ? 'btn-danger' : 'btn-outline-danger')]) ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Tipo</th>
                    <th>Fecha Inicio</th>
                    <th>Fecha Fin</th>
                    <th>Estado</th>
                    <th>Solicitado por</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employeeLeaves as $leave): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $leave->id]) ?>">
                    <td class="fw-semibold" style="font-size:.85rem;">
                        <?= h($leave->employee->full_name ?? '') ?>
                    </td>
                    <td style="font-size:.85rem;">
                        <?= h($leave->leave_type->name ?? '') ?>
                    </td>
                    <td style="font-size:.85rem;">
                        <?= $leave->start_date?->format('d/m/Y') ?: '—' ?>
                    </td>
                    <td style="font-size:.85rem;">
                        <?= $leave->end_date?->format('d/m/Y') ?: '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= $statusBadges[$leave->status] ?? 'bg-secondary' ?>">
                            <?= ucfirst(h($leave->status)) ?>
                        </span>
                    </td>
                    <td style="font-size:.8rem;color:#777;">
                        <?= h($leave->requested_by_user->full_name ?? '') ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty(iterator_to_array($employeeLeaves))): ?>
                <tr>
                    <td colspan="6">
                        <div class="sgi-doc-empty">
                            <i class="bi bi-inbox sgi-doc-empty-icon"></i>
                            No hay solicitudes de permisos.
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
