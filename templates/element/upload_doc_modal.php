<?php
/**
 * Modal compartido "Subir Soporte" para los módulos de flujo. El form lo
 * consume SpiDocumentUploader (ver el bloque <script> de cada edit). DRY de
 * los modales antes duplicados en Refunds/PettyCash/EmployeeNovelties/
 * NoveltyLiquidationDocs/PaymentSchedulings edit (audit paridad visual, A7).
 *
 * @var \App\View\AppView $this
 * @var string $modalId   id del modal; debe coincidir con el uploadModalId pasado a documents_section
 * @var string $uploadUrl URL destino del upload (data-url leído por SpiDocumentUploader)
 * @var string $formId    id del form (default 'upload-doc-form')
 * @var string $accept    atributo accept del input file
 * @var bool   $showDocumentType  muestra el input opcional "Tipo de Documento" (default false)
 */
$formId           = $formId ?? 'upload-doc-form';
$accept           = $accept ?? '.pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx';
$showDocumentType = $showDocumentType ?? false;
?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="<?= h($formId) ?>"
                  data-url="<?= h($uploadUrl) ?>"
                  enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2" aria-hidden="true"></i>Subir Soporte</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($showDocumentType): ?>
                    <div class="mb-3">
                        <label class="input-label">Tipo de Documento (opcional)</label>
                        <input type="text" name="document_type" class="form-control" placeholder="Ej. Soporte causación, Comprobante...">
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="input-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required
                               accept="<?= h($accept) ?>">
                        <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
