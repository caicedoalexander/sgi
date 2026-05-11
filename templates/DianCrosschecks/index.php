<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\DianCrosscheck> $dianCrosschecks
 * @var string|null $statusFilter
 */
$this->assign('title', 'Cruce DIAN');

$statusBadges = [
    'enviado' => 'bg-info text-dark',
    'procesando' => 'bg-warning text-dark',
    'completado' => 'bg-success',
    'error' => 'bg-danger',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Cruce DIAN</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['dian_crosschecks']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-upload me-1" aria-hidden="true"></i>Subir Archivo',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Estado</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="enviado" <?= ($statusFilter ?? '') === 'enviado' ? 'selected' : '' ?>>Enviado</option>
                    <option value="procesando" <?= ($statusFilter ?? '') === 'procesando' ? 'selected' : '' ?>>Procesando</option>
                    <option value="completado" <?= ($statusFilter ?? '') === 'completado' ? 'selected' : '' ?>>Completado</option>
                    <option value="error" <?= ($statusFilter ?? '') === 'error' ? 'selected' : '' ?>>Error</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Subido por</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dianCrosschecks as $crosscheck): ?>
                <tr>
                    <td><?= h($crosscheck->file_name) ?></td>
                    <td><?= h($crosscheck->uploaded_by_user->full_name ?? '—') ?></td>
                    <td>
                        <span class="badge <?= $statusBadges[$crosscheck->status] ?? 'bg-secondary' ?>">
                            <?= h(ucfirst($crosscheck->status)) ?>
                        </span>
                    </td>
                    <td><?= $crosscheck->created?->format('d/m/Y H:i') ?: '—' ?></td>
                    <td>
                        <?php if ($crosscheck->error_message): ?>
                            <small class="text-danger"><?= h(\Cake\Utility\Text::truncate($crosscheck->error_message, 80)) ?></small>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty(iterator_to_array($dianCrosschecks))): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No hay registros de cruce DIAN.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
