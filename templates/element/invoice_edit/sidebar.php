<?php
/**
 * Soportes + Observaciones del Invoices/edit. Se renderiza dentro de
 * la columna principal (sgi-edit-side-grid: 2 columnas), no como
 * sidebar vertical. Alineado al spec del Sistema de Diseño v2:
 * sin bordes, sin shadow, header con sgi-section-head + sgi-label.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 * @var array<string,array> $documentsByStatus
 * @var int $totalDocs
 * @var bool $showUploadSection
 * @var array<string,string> $statusLabels
 * @var array<string,string> $badgeColors
 */
$obsCount = count($viewModel->invoice->invoice_observations ?? []);
$multipleStatuses = count($documentsByStatus) > 1;
?>
<!-- Soportes -->
<div class="card" style="padding:18px 20px;display:flex;flex-direction:column;">
    <div class="sgi-section-head" style="margin-bottom:12px;">
        <span class="sgi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            Soportes
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUploadSection): ?>
        <button type="button" class="btn btn-ghost-card btn-sm" data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal">
            <i class="bi bi-upload" aria-hidden="true"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <div id="docs-empty-state" class="sgi-dropzone-empty" <?= !empty($documentsByStatus) ? 'style="display:none;"' : '' ?>>
        <i class="bi bi-paperclip" aria-hidden="true"></i>
        <div>Sin soportes adjuntos</div>
        <?php if ($showUploadSection): ?>
        <div style="font-size:10.5px;margin-top:4px;">PDF, JPG, PNG · máximo 10 MB por archivo</div>
        <?php endif; ?>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($documentsByStatus as $status => $docs): ?>
        <?php if ($multipleStatuses): ?>
        <div style="padding:.3rem .5rem;background:var(--bg-subtle);display:flex;align-items:center;gap:.4rem;margin-top:.5rem;">
            <?php
            $stageStatusPills = [
                \App\Constants\InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
                \App\Constants\InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
                \App\Constants\InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
                \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
                \App\Constants\InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
                \App\Constants\InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
                \App\Constants\InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
            ];
            $pillKind = $stageStatusPills[$status] ?? 'pill-muted';
            ?>
            <span class="pill <?= $pillKind ?>"><?= $statusLabels[$status] ?? $status ?></span>
            <span style="font-size:10.5px;color:var(--text-faint);"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'          => $doc,
                'canDelete'    => $viewModel->canDeleteDocuments && $doc->pipeline_status === $viewModel->currentStatus,
                'deleteUrl'    => $this->Url->build(['action' => 'deleteDocument', $viewModel->invoice->id, $doc->id]),
                'showBadge'    => !$multipleStatuses,
                'badgeColors'  => $badgeColors,
                'statusLabels' => $statusLabels,
            ]) ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Observaciones -->
<div class="card sgi-obs-card" style="padding:18px 20px;display:flex;flex-direction:column;">
    <div class="sgi-section-head" style="margin-bottom:12px;">
        <span class="sgi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
            Observaciones
            <span id="obs-count" class="sgi-folder-count" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
        </span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list">
        <?php foreach ($viewModel->invoice->invoice_observations ?? [] as $obs): ?>
            <?= $this->element('observation_bubble', [
                'observation' => $obs,
                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
        <i class="bi bi-chat-square-dots" aria-hidden="true" style="font-size:1.5rem;"></i>
        <span style="font-size:11.5px;">Sin observaciones aún</span>
    </div>

    <div class="sgi-obs-input-bar">
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $viewModel->invoice->id], 'id' => 'obs-form']) ?>
        <div class="sgi-obs-compose">
            <textarea id="obs-message" name="message" class="auto-resize" rows="1"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                <i class="bi bi-send" aria-hidden="true"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
