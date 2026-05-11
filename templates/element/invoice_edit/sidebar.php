<?php
/**
 * Columna derecha del Invoices/edit: soportes adjuntos + chat de observaciones.
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
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;" aria-hidden="true"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUploadSection): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadInvoiceDocModal">
            <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($documentsByStatus) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;" aria-hidden="true"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($documentsByStatus as $status => $docs): ?>
        <?php if ($multipleStatuses): ?>
        <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
            <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
            <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
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

<!-- Observaciones: chat -->
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);" aria-hidden="true"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
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
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;" aria-hidden="true"></i>
        <span style="font-size:.78rem;">Sin observaciones aún</span>
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
