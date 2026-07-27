<?php
/**
 * Card "Soportes" de una legalización de anticipo: relación de facturas,
 * cajón general de soportes (documentos libres) e historial de firmas. Element
 * COMPARTIDO por la vista operativa (`Advances/legalization.php`, editable=true,
 * canManageDocuments real) y el hub de consulta (`Advances/view.php`,
 * editable=false, canManageDocuments=false). Con editable=false oculta los
 * formularios de subir/reemplazar la relación. Con canManageDocuments=false
 * omite los IDs docs-list/docs-empty-state/docs-folder-count para no colisionar
 * con los del `documents_section` del anticipo en el hub.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\AdvanceLegalizationSignature|null $relationDocument
 * @var array $signatureHistory
 * @var bool $editable
 * @var list<\App\Model\Entity\AdvanceLegalizationDocument> $documents
 * @var int $totalDocs
 * @var bool $canManageDocuments
 */
use App\Constants\AdvanceConstants;

$editable = $editable ?? true;
$documents = $documents ?? [];
$totalDocs = (int)($totalDocs ?? 0);
$canManageDocuments = $canManageDocuments ?? false;
?>
<div class="spi-card d-flex flex-column">
    <div class="d-flex align-items-center" style="margin-bottom:12px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            Soportes
        </span>
    </div>
    <div class="hr" style="margin-bottom:16px;"></div>

    <div style="max-height:420px;overflow-y:auto;">

    <!-- Documento especial: Relación de facturas -->
    <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);">
        <span class="pill pill-primary-soft">Relación de facturas</span>
    </div>
    <?php if ($relationDocument): ?>
    <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
        <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
            <i class="bi <?= h($this->DocumentIcon->iconClass($relationDocument->mime_type ?? null)) ?>"
               style="color:<?= h($this->DocumentIcon->iconColor($relationDocument->mime_type ?? null)) ?>;font-size:18px;" aria-hidden="true"></i>
        </div>
        <div class="grow">
            <div title="<?= h($relationDocument->file_name ?? '') ?>"
                 style="font-size:var(--fs-body);font-weight:600;color:var(--text-strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($relationDocument->file_name ?? 'Documento') ?>
            </div>
            <div class="row-flex gap-6" style="margin-top:4px;flex-wrap:wrap;">
                <?php if ($relationDocument->isSigned()): ?>
                <span class="pill pill-primary-soft pill-sm">
                    <i class="bi bi-check-circle" aria-hidden="true"></i>Firmado<?php if ($relationDocument->signed_by_user): ?> · <?= h($relationDocument->signed_by_user->full_name ?? '') ?><?php endif; ?>
                </span>
                <?php else: ?>
                <span class="pill pill-warning-soft pill-sm">
                    <i class="bi bi-clock" aria-hidden="true"></i>Pendiente de firma
                </span>
                <?php endif; ?>
                <?php if ($relationDocument->created): ?>
                <span class="mono spi-body-faint" style="font-size:var(--fs-label);">
                    <?= $relationDocument->created->format('d/m/Y H:i') ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="row-flex gap-4" style="flex-shrink:0;">
            <?php if ($editable && in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)): ?>
            <form id="rel-doc-update-form" class="d-inline"
                  data-upload-url="<?= $this->Url->build(['action' => 'uploadRelationDocument', $leg->advance_invoice_id]) ?>">
            <input type="file" name="relation_document" id="rel-doc-file-update" required
                   accept=".pdf,.jpg,.jpeg,.png" style="display:none;" data-rel-doc-trigger>
            <label for="rel-doc-file-update" class="btn-icon" style="cursor:pointer;" title="Reemplazar">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            </label>
            </form>
            <?php endif; ?>
            <?php if (!empty($relationDocument->file_path)): ?>
            <?= $this->Html->link(
                '<i class="bi bi-eye" aria-hidden="true"></i>',
                '/' . $relationDocument->file_path,
                ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($editable && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
    <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
        <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
            <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
        </div>
        <div class="grow">
            <span class="spi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento adjunto</span>
        </div>
        <form id="rel-doc-upload-form" class="d-inline flex-shrink-0"
              data-upload-url="<?= $this->Url->build(['action' => 'uploadRelationDocument', $leg->advance_invoice_id]) ?>">
        <input type="file" name="relation_document" id="rel-doc-file-new" required
               accept=".pdf,.jpg,.jpeg,.png" style="display:none;" data-rel-doc-trigger>
        <label for="rel-doc-file-new" class="btn btn-default btn-sm" style="cursor:pointer;" title="Subir">
            <i class="bi bi-upload" aria-hidden="true"></i>Subir
        </label>
        </form>
    </div>
    <?php else: ?>
    <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
        <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
            <i class="bi bi-file-earmark-x" style="color:var(--text-disabled);font-size:18px;" aria-hidden="true"></i>
        </div>
        <div class="grow">
            <span class="spi-body-faint" style="font-size:var(--fs-body-sm);">Sin documento</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Soportes generales -->
    <div class="d-flex align-items-center justify-content-between"
         style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
        <span class="pill pill-secondary-soft">Soportes</span>
        <div class="d-flex align-items-center gap-2">
            <span<?= $canManageDocuments ? ' id="docs-folder-count"' : '' ?> class="spi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
            <?php if ($canManageDocuments): ?>
            <button type="button" class="btn btn-default btn-sm"
                    data-bs-toggle="modal" data-bs-target="#uploadLegDocModal">
                <i class="bi bi-upload" aria-hidden="true"></i>Subir
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManageDocuments): ?>
    <div id="docs-empty-state" class="dropzone" data-bs-toggle="modal" data-bs-target="#uploadLegDocModal"
         style="cursor:pointer;margin:6px 0;<?= $totalDocs > 0 ? 'display:none;' : '' ?>">
        <i class="bi bi-paperclip" aria-hidden="true"></i>
        <div>Arrastra archivos o <a class="dz-link">examina</a></div>
        <div class="dz-hint">PDF, imágenes, Word o Excel · máximo 10 MB</div>
    </div>
    <?php elseif ($totalDocs === 0): ?>
    <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;">
        <span class="spi-body-faint" style="font-size:var(--fs-body-sm);">Sin soportes adjuntos</span>
    </div>
    <?php endif; ?>
    <div<?= $canManageDocuments ? ' id="docs-list"' : '' ?>>
        <?php foreach ($documents as $doc): ?>
        <?= $this->element('document_row', [
            'doc' => $doc,
            'canDelete' => $canManageDocuments,
            'deleteUrl' => $canManageDocuments
                ? $this->Url->build(['action' => 'deleteLegalizationDocument', $leg->advance_invoice_id, $doc->id])
                : '',
            'showBadge' => false,
        ]) ?>
        <?php endforeach; ?>
    </div>

    <!-- Documento especial: Historial de firmas rechazadas -->
    <?php if (!empty($signatureHistory)): ?>
    <div class="d-flex align-items-center gap-2" style="padding:.3rem .5rem;background:var(--bg-subtle);margin-top:.5rem;">
        <span class="pill pill-muted">Historial de firmas</span>
    </div>
    <?php foreach ($signatureHistory as $sig): ?>
    <div class="doc-row row-flex gap-12" style="padding:10px 12px;background:var(--bg-muted);margin:6px 0;opacity:.7;">
        <div class="doc-icon row-flex" style="justify-content:center;flex-shrink:0;width:30px;">
            <i class="bi <?= h($this->DocumentIcon->iconClass($sig->mime_type ?? null)) ?>"
               style="color:var(--text-faint);font-size:18px;" aria-hidden="true"></i>
        </div>
        <div class="grow">
            <div title="<?= h($sig->file_name ?? '') ?>"
                 style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= h($sig->file_name ?? '—') ?>
            </div>
            <div class="row-flex gap-6" style="margin-top:4px;flex-wrap:wrap;">
                <span class="pill pill-danger-soft pill-sm">Rechazado</span>
                <?php if ($sig->rejection_reason): ?>
                <span class="spi-body-faint" style="font-size:var(--fs-label);"><?= h($sig->rejection_reason) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="row-flex gap-4" style="flex-shrink:0;">
            <?php if (!empty($sig->file_path)): ?>
            <?= $this->Html->link(
                '<i class="bi bi-eye" aria-hidden="true"></i>',
                '/' . $sig->file_path,
                ['class' => 'btn-icon', 'escape' => false, 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Abrir']
            ) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    </div>
</div>
