<?php
/**
 * Modal "Subir Soporte" del Invoices/edit. El form lo consume
 * SgiDocumentUploader desde scripts.php.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 */
?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadInvoiceDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form" data-url="<?= $this->Url->build(['action' => 'uploadDocument', $invoice->id]) ?>" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2" aria-hidden="true"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Tipo de Documento (opcional)</label>
                    <input type="text" name="document_type" class="form-control" placeholder="Ej. Factura, Cotización, Soporte...">
                </div>
                <div class="mb-3">
                    <label class="form-label">Archivo</label>
                    <input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Máximo 20 MB — PDF, imágenes, Word o Excel.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" id="upload-doc-btn" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
            </div>
            </form>
        </div>
    </div>
</div>
