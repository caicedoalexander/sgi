<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeLeave $employeeLeave
 * @var bool $canApprove
 */
$this->assign('title', 'Detalle de Permiso');

$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle de Permiso</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;">
                    <?= h($employeeLeave->employee->full_name ?? '') ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    <?= h($employeeLeave->leave_type->name ?? '') ?>
                </div>
            </div>
        </div>
        <span class="badge <?= $statusBadges[$employeeLeave->status] ?? 'bg-secondary' ?>">
            <?= ucfirst(h($employeeLeave->status)) ?>
        </span>
    </div>

    <div class="row g-0" style="border-top:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información del Permiso</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($employeeLeave->employee->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h($employeeLeave->leave_type->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Inicio</span>
                <span class="sgi-data-value"><?= $employeeLeave->start_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Fin</span>
                <span class="sgi-data-value"><?= $employeeLeave->end_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Gestión</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Solicitado por</span>
                <span class="sgi-data-value"><?= h($employeeLeave->requested_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobado por</span>
                <span class="sgi-data-value"><?= h($employeeLeave->approved_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Aprobación</span>
                <span class="sgi-data-value"><?= $employeeLeave->approved_at ? $employeeLeave->approved_at->format('d/m/Y H:i') : '—' ?></span>
            </div>
        </div>
    </div>

    <?php if ($employeeLeave->observations): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#555;line-height:1.65;">
            <?= nl2br(h($employeeLeave->observations)) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canApprove): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <div class="d-flex gap-2">
            <?= $this->Form->create(null, ['url' => ['action' => 'approve', $employeeLeave->id]]) ?>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Aprobar
            </button>
            <?= $this->Form->end() ?>

            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-lg me-1"></i>Rechazar
            </button>
        </div>
    </div>

    <!-- Reject modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <?= $this->Form->create(null, ['url' => ['action' => 'reject', $employeeLeave->id]]) ?>
                <div class="modal-header">
                    <h5 class="modal-title">Rechazar Permiso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Motivo del rechazo</label>
                    <textarea name="observations" class="form-control" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Rechazar</button>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
