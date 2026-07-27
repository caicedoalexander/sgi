<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\AssetViewViewModel $viewModel
 */
use App\Constants\AssetConstants;
use App\View\Presentation\AssetPresentation;

$asset = $viewModel->asset;
[$statusLabel, $statusPill] = $viewModel->currentStatusBadge;
$docTypeOptions = [];
foreach (AssetConstants::DOCUMENT_TYPES as $dt) {
    $docTypeOptions[$dt] = \App\Constants\Domain\Asset\DocumentType::from($dt)->label();
}
// Correction 3: wrap entity data in h() for title consistency.
$this->assign('title', 'Activo ' . h($asset->code));
?>
<div class="d-flex justify-content-between align-items-start" style="margin-bottom:16px;">
    <div style="min-width:0;">
        <div class="d-flex align-items-center gap-1" style="font-size:11.5px;color:var(--text-faint);margin-bottom:4px;">
            <?= $this->Html->link('Activos', ['action' => 'index'], ['style' => 'color:inherit;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:10px;"></i>
            <span><?= h($asset->code) ?></span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <h1 class="spi-page-title">Detalle del Activo</h1>
            <span class="mono" style="font-size:14px;color:var(--text-muted);padding:3px 8px;background:var(--bg-subtle);"><?= h($asset->code) ?></span>
            <span class="pill <?= h($statusPill) ?>"><?= h($statusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?php if ($viewModel->canEdit): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $asset->id],
            ['class' => 'btn btn-secondary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="spi-invoice-view-grid">
    <aside class="spi-invoice-view-left">
        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Resumen</div>
            <div class="field-row"><span class="k">Estado</span><span class="v"><span class="pill <?= h($statusPill) ?>"><?= h($statusLabel) ?></span></span></div>
            <div class="field-row"><span class="k">Categoría</span><span class="v"><?= h($asset->asset_category->name ?? '—') ?></span></div>
            <?php // Correction 1: use full_name virtual field (no last_name column). ?>
            <div class="field-row"><span class="k">Responsable</span><span class="v"><?= h($asset->responsible_employee->full_name ?? '—') ?></span></div>
            <div class="field-row is-last"><span class="k">Sede</span><span class="v"><?= h($asset->operation_center->name ?? '—') ?></span></div>
        </div>
        <?php if ($viewModel->canCreateMovement && $asset->status !== AssetConstants::STATUS_DADO_DE_BAJA): ?>
        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Acciones</div>
            <div class="d-flex flex-column gap-2">
                <?php if ($asset->status === AssetConstants::STATUS_DISPONIBLE): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-assign"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Asignar</button>
                    <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-lend"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Prestar</button>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-return"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Devolver</button>
                <?php endif; ?>
                <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-transfer"><i class="bi bi-truck me-1" aria-hidden="true"></i>Trasladar</button>
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modal-dispose"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Dar de baja</button>
            </div>
        </div>
        <?php endif; ?>
    </aside>

    <main class="spi-invoice-view-right">
        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:16px;">Información</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">
                <div class="field-row"><span class="k">Código</span><span class="v mono"><?= h($asset->code) ?></span></div>
                <div class="field-row"><span class="k">N° de serie</span><span class="v mono"><?= h($asset->serial_number) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Marca</span><span class="v"><?= h($asset->brand) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Modelo</span><span class="v"><?= h($asset->model) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Centro de costo</span><span class="v"><?= h($asset->cost_center->name ?? '—') ?></span></div>
                <div class="field-row"><span class="k">Adquisición</span><span class="v mono"><?= $asset->acquisition_date?->format('d/m/Y') ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Descripción</span><span class="v"><?= h($asset->description) ?: '—' ?></span></div>
                <div class="field-row"><span class="k">Observaciones</span><span class="v"><?= h($asset->observations) ?: '—' ?></span></div>
            </div>
        </div>

        <div class="spi-card">
            <div style="font-weight:600;margin-bottom:12px;">Historial de movimientos (<?= count($viewModel->movements) ?>)</div>
            <?php if ($viewModel->movements === []): ?>
                <div style="color:var(--text-faint);font-size:13px;">Sin movimientos registrados.</div>
            <?php else: ?>
                <?php foreach ($viewModel->movements as $m): ?>
                <div class="d-flex justify-content-between align-items-start" style="padding:8px 0;border-bottom:1px solid var(--border-subtle);">
                    <div>
                        <span style="font-weight:600;"><?= h(AssetConstants::MOVEMENT_LABELS[$m->movement_type] ?? $m->movement_type) ?></span>
                        <?php if ($m->acta_status): ?>
                            <span class="pill <?= h(AssetPresentation::ACTA_BADGES[$m->acta_status] ?? 'pill-secondary-soft') ?> pill-sm">Acta: <?= h(\App\Constants\Domain\Asset\ActaStatus::from($m->acta_status)->label()) ?></span>
                        <?php endif; ?>
                        <div style="font-size:12px;color:var(--text-faint);"><?= h($m->reason) ?></div>
                    </div>
                    <div class="mono" style="font-size:12px;color:var(--text-muted);"><?= $m->movement_date?->format('d/m/Y H:i') ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="spi-card">
            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:12px;">
                <span style="font-weight:600;">Documentos y actas (<?= count($viewModel->documents) ?>)</span>
                <?php if ($viewModel->canCreateMovement): ?>
                <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#modal-upload-doc"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                <?php endif; ?>
            </div>
            <?php if ($viewModel->documents === []): ?>
                <div style="color:var(--text-faint);font-size:13px;">Sin documentos.</div>
            <?php else: ?>
                <?php foreach ($viewModel->documents as $doc): ?>
                <div class="d-flex justify-content-between align-items-center" style="padding:6px 0;">
                    <span><i class="bi bi-file-earmark me-1" aria-hidden="true"></i><?= h($doc->name) ?>
                        <span class="pill pill-secondary-soft pill-sm"><?= h(\App\Constants\Domain\Asset\DocumentType::from($doc->document_type)->label()) ?></span>
                    </span>
                    <span class="d-flex gap-1">
                        <?= $this->Html->link('<i class="bi bi-download" aria-hidden="true"></i>',
                            ['action' => 'downloadDocument', $doc->id],
                            ['class' => 'btn-icon', 'escape' => false, 'title' => 'Descargar']) ?>
                        <?php if ($viewModel->canCreateMovement): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                            ['action' => 'deleteDocument', $doc->id],
                            ['confirm' => '¿Eliminar este documento?', 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if ($viewModel->canCreateMovement && $asset->status !== AssetConstants::STATUS_DADO_DE_BAJA): ?>
    <?= $this->element('asset/movement_modals', ['viewModel' => $viewModel]) ?>
<?php endif; ?>
<?php if ($viewModel->canCreateMovement): ?>
<div class="modal fade" id="modal-upload-doc" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $asset->id], 'type' => 'file']) ?>
        <div class="modal-header">
            <h5 class="modal-title">Subir documento</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
            <?= $this->Form->control('document_type', [
                'options' => $docTypeOptions, 'class' => 'form-select',
                'label' => ['text' => 'Tipo de documento', 'class' => 'input-label'],
            ]) ?>
            <?= $this->Form->control('document', [
                'type' => 'file', 'class' => 'form-control mt-2',
                'label' => ['text' => 'Archivo (PDF, imagen, Word o Excel)', 'class' => 'input-label'],
            ]) ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">Subir</button>
        </div>
        <?= $this->Form->end() ?>
    </div></div>
</div>
<?php endif; ?>
