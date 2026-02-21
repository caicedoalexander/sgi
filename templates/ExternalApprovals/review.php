<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var object $tokenRecord
 * @var object $entity
 */
$this->assign('title', 'Revisión de Aprobación');

$entityType = $tokenRecord->entity_type;
?>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
            <i class="bi bi-clipboard-check"></i>
        </div>
        <div>
            <div style="font-size:.95rem;font-weight:700;color:#111;">Solicitud de Aprobación</div>
            <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                Enlace válido hasta <?= $tokenRecord->expires_at->format('d/m/Y H:i') ?>
            </div>
        </div>
    </div>

    <div style="border-top:1px solid var(--border-color);">
        <?php if ($entityType === 'invoices'): ?>
            <div class="sgi-section-title">Factura</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Número</span>
                <span class="sgi-data-value"><?= h($entity->invoice_number ?? '#' . $entity->id) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Proveedor</span>
                <span class="sgi-data-value"><?= h($entity->provider->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Monto</span>
                <span class="sgi-data-value fw-semibold" style="color:var(--primary-color);">
                    $ <?= number_format((float)$entity->amount, 2, ',', '.') ?>
                </span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado Actual</span>
                <span class="sgi-data-value"><?= h(\App\Service\InvoicePipelineService::STATUS_LABELS[$entity->pipeline_status] ?? $entity->pipeline_status) ?></span>
            </div>
        <?php elseif ($entityType === 'employee_leaves'): ?>
            <div class="sgi-section-title">Permiso / Licencia</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($entity->employee->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h($entity->leave_type->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fechas</span>
                <span class="sgi-data-value">
                    <?= $entity->start_date?->format('d/m/Y') ?> — <?= $entity->end_date?->format('d/m/Y') ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>

        <div class="mb-3">
            <label class="form-label">Observaciones (opcional)</label>
            <textarea name="observations" class="form-control" rows="3" placeholder="Comentarios adicionales..."></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" name="action" value="approve" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Aprobar
            </button>
            <button type="submit" name="action" value="reject" class="btn btn-danger">
                <i class="bi bi-x-lg me-1"></i>Rechazar
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
