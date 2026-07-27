<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var \App\Service\Approval\TokenRecord $tokenRecord
 * @var object $entity
 * @var object $currentUser
 */
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\NoveltyPresentation;
$this->assign('title', 'Revisión de Aprobación');

$entityType = $tokenRecord->entityType;
$badgeMap = $entityType === 'employee_novelties'
    ? NoveltyPresentation::STATUS_BADGES
    : InvoicePresentation::STATUS_BADGES;
?>

<div class="mb-3">
    <span class="pill pill-info-soft">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        Aprobando como: <strong><?= h($currentUser->full_name) ?></strong>
    </span>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <div>
        <div class="spi-title-card"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Solicitud de Aprobación</div>
        <div class="spi-body-faint">
            Enlace válido hasta <?= $tokenRecord->expiresAt->format('d/m/Y H:i') ?>
        </div>
    </div>

    <div>
        <?php if ($entityType === 'invoices'): ?>
            <div class="spi-label">Factura</div>
            <div class="field-row">
                <span class="k">Número</span>
                <span class="v"><?= h($entity->invoice_number ?? '#' . $entity->id) ?></span>
            </div>
            <div class="field-row">
                <span class="k">Proveedor</span>
                <span class="v"><?= h($entity->provider->name ?? '—') ?></span>
            </div>
            <div class="field-row">
                <span class="k">Monto</span>
                <span class="v mono spi-fg-primary">
                    $ <?= number_format((float)$entity->amount, 2, ',', '.') ?>
                </span>
            </div>
            <div class="field-row">
                <span class="k">Estado Actual</span>
                <span class="v">
                    <span class="pill <?= $badgeMap[$entity->pipeline_status] ?? 'pill-muted' ?>">
                        <?= h(\App\Constants\InvoiceConstants::STATUS_LABELS[$entity->pipeline_status] ?? $entity->pipeline_status) ?>
                    </span>
                </span>
            </div>
        <?php elseif ($entityType === 'employee_novelties'): ?>
            <div class="spi-label">Novedad</div>
            <div class="field-row">
                <span class="k">Empleado</span>
                <span class="v"><?= h($entity->employee->full_name ?? $entity->custom_name ?? '—') ?></span>
            </div>
            <div class="field-row">
                <span class="k">Tipo de Novedad</span>
                <span class="v"><?= h($entity->novelty_type->name ?? '—') ?></span>
            </div>
            <?php if (!empty($entity->reason)): ?>
            <div class="field-row">
                <span class="k">Motivo</span>
                <span class="v"><?= h($entity->reason) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($entity->start_date || $entity->end_date): ?>
            <div class="field-row">
                <span class="k">Fechas</span>
                <span class="v">
                    <?= $entity->start_date ? (is_string($entity->start_date) ? $entity->start_date : $entity->start_date->format('d/m/Y')) : '' ?>
                    <?php if ($entity->end_date): ?>
                     — <?= is_string($entity->end_date) ? $entity->end_date : $entity->end_date->format('d/m/Y') ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="field-row">
                <span class="k">Estado Actual</span>
                <span class="v">
                    <span class="pill <?= $badgeMap[$entity->pipeline_status] ?? 'pill-muted' ?>">
                        <?= h(\App\Constants\NoveltyConstants::STATUS_LABELS[$entity->pipeline_status] ?? $entity->pipeline_status) ?>
                    </span>
                </span>
            </div>
        <?php elseif ($entityType === 'employee_leaves'): ?>
            <div class="spi-label">Permiso / Licencia</div>
            <div class="field-row">
                <span class="k">Empleado</span>
                <span class="v"><?= h($entity->employee->full_name ?? '—') ?></span>
            </div>
            <div class="field-row">
                <span class="k">Tipo</span>
                <span class="v"><?= h($entity->leave_type->name ?? '—') ?></span>
            </div>
            <div class="field-row">
                <span class="k">Fechas</span>
                <span class="v">
                    <?= $entity->start_date?->format('d/m/Y') ?> — <?= $entity->end_date?->format('d/m/Y') ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($entityType === 'invoices' && !empty($entity->invoice_documents)): ?>
    <?php
    $statusLabels = ['aprobacion' => 'Aprobación', 'contabilidad' => 'Contabilidad', 'tesoreria' => 'Tesorería', 'pagada' => 'Pagada'];
    ?>
    <div>
        <div class="spi-label">Soportes</div>
        <div class="row row-cols-1 row-cols-md-3 g-3">
            <?php foreach ($entity->invoice_documents as $doc): ?>
            <div class="col">
                <div class="spi-card compact h-100 d-flex flex-column gap-2">
                    <!-- Header: icono + nombre -->
                    <div class="d-flex align-items-center gap-2" style="min-width:0;">
                        <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> flex-shrink-0"
                           style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1.1rem;"></i>
                        <span class="text-truncate" style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);min-width:0;" title="<?= h($doc->file_name) ?>">
                            <?= h($doc->file_name) ?>
                        </span>
                    </div>
                    <!-- Body: badge estado + fecha + tamaño -->
                    <div class="d-flex flex-column gap-1" style="font-size:var(--fs-body);color:var(--text-muted);">
                        <?php if (!empty($doc->pipeline_status)): ?>
                        <div>
                            <span class="pill <?= $badgeMap[$doc->pipeline_status] ?? 'pill-muted' ?>" style="font-size:var(--fs-label);">
                                <?= $statusLabels[$doc->pipeline_status] ?? $doc->pipeline_status ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex align-items-center gap-1" style="color:var(--text-faint);">
                            <i class="bi bi-clock" style="font-size:var(--fs-body-sm);" aria-hidden="true"></i>
                            <span><?= $doc->created?->format('d/m/Y H:i') ?></span>
                        </div>
                        <?php if ($doc->file_size): ?>
                        <div style="color:var(--text-disabled);font-size:.72rem;"><?= $this->Number->toReadableSize($doc->file_size) ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Footer: botón abrir -->
                    <div class="mt-auto text-end">
                        <?= $this->Html->link(
                            '<i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir',
                            '/' . $doc->file_path,
                            ['class' => 'btn btn-default btn-sm', 'escape' => false, 'target' => '_blank']
                        ) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div>
        <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>

        <div class="mb-3">
            <label class="input-label">Observaciones (opcional)</label>
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
