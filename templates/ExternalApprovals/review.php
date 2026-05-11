<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var object $tokenRecord
 * @var object $entity
 * @var object $currentUser
 */
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\NoveltyPresentation;
$this->assign('title', 'Revisión de Aprobación');

$entityType = $tokenRecord->entity_type;
$badgeMap = $entityType === 'employee_novelties'
    ? NoveltyPresentation::STATUS_BADGES
    : InvoicePresentation::STATUS_BADGES;
?>

<div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="font-size:.875rem">
    <i class="bi bi-person-check" aria-hidden="true"></i>
    <span>Aprobando como: <strong><?= h($currentUser->full_name) ?></strong></span>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center flex-shrink-0"
             style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
            <i class="bi bi-clipboard-check" aria-hidden="true"></i>
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
                <span class="sgi-data-value"><?= h(\App\Constants\InvoiceConstants::STATUS_LABELS[$entity->pipeline_status] ?? $entity->pipeline_status) ?></span>
            </div>
        <?php elseif ($entityType === 'employee_novelties'): ?>
            <div class="sgi-section-title">Novedad</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($entity->employee->full_name ?? $entity->custom_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo de Novedad</span>
                <span class="sgi-data-value"><?= h($entity->novelty_type->name ?? '—') ?></span>
            </div>
            <?php if (!empty($entity->reason)): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Motivo</span>
                <span class="sgi-data-value"><?= h($entity->reason) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($entity->start_date || $entity->end_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fechas</span>
                <span class="sgi-data-value">
                    <?= $entity->start_date ? (is_string($entity->start_date) ? $entity->start_date : $entity->start_date->format('d/m/Y')) : '' ?>
                    <?php if ($entity->end_date): ?>
                     — <?= is_string($entity->end_date) ? $entity->end_date : $entity->end_date->format('d/m/Y') ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado Actual</span>
                <span class="sgi-data-value"><?= h(\App\Constants\NoveltyConstants::STATUS_LABELS[$entity->pipeline_status] ?? $entity->pipeline_status) ?></span>
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

    <?php if ($entityType === 'invoices' && !empty($entity->invoice_documents)): ?>
    <?php
    $statusLabels = ['aprobacion' => 'Aprobación', 'contabilidad' => 'Contabilidad', 'tesoreria' => 'Tesorería', 'pagada' => 'Pagada'];
    ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Soportes</div>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($entity->invoice_documents as $doc): ?>
                <div class="col">
                    <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                        <!-- Header: icono + nombre -->
                        <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:#fafafa;display:flex;align-items:center;gap:.5rem;min-width:0;">
                            <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> flex-shrink-0"
                               style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.1rem;"></i>
                            <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;" title="<?= h($doc->file_name) ?>">
                                <?= h($doc->file_name) ?>
                            </span>
                        </div>
                        <!-- Body: badge estado + fecha + tamaño -->
                        <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                            <?php if (!empty($doc->pipeline_status)): ?>
                            <div>
                                <span class="badge <?= $badgeMap[$doc->pipeline_status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
                                    <?= $statusLabels[$doc->pipeline_status] ?? $doc->pipeline_status ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                                <i class="bi bi-clock" style="font-size:.75rem;" aria-hidden="true"></i>
                                <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                            </div>
                            <?php if ($doc->file_size): ?>
                            <div style="color:#aaa;font-size:.72rem;"><?= $this->Number->toReadableSize($doc->file_size) ?></div>
                            <?php endif; ?>
                        </div>
                        <!-- Footer: botón abrir -->
                        <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                            <?= $this->Html->link(
                                '<i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir',
                                '/' . $doc->file_path,
                                ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                            ) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>

        <div class="mb-3">
            <label class="form-label">Observaciones (opcional)</label>
            <textarea name="observations" class="form-control" rows="3" placeholder="Comentarios adicionales..."></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" name="action" value="approve" class="btn btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar
            </button>
            <button type="submit" name="action" value="reject" class="btn btn-danger">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar
            </button>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
