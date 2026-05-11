<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var array $approvers  [id => name]
 */
?>
<div class="modal fade" id="modifyApproversModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $this->Url->build(['action' => 'modifyApprovers', $invoice->id]) ?>">
                <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Modificar aprobadores</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2" style="font-size:.8rem;">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        Los enlaces de aprobación previos quedarán invalidados.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nuevos aprobadores</label>
                        <select name="approver_ids[]" class="form-select select2-enable" multiple required
                                data-placeholder="Seleccione...">
                            <?php foreach ($approvers as $id => $name): ?>
                                <option value="<?= $id ?>"><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Motivo *</label>
                        <textarea name="reason" class="form-control" rows="3" required
                                  placeholder="Indique por qué se modifican los aprobadores"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Guardar y reenviar enlaces
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
