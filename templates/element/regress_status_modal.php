<?php
/**
 * Modal genérico "Regresar al paso anterior" — sustituye al copy-paste
 * que existía en Invoices/edit, Refunds/edit, PettyCashRecords/edit y
 * PaymentSchedulings/edit.
 *
 * @var \App\View\AppView $this
 * @var string $actionUrl     URL absoluta del POST (resuelta con $this->Url->build).
 * @var string $entityNoun    Sustantivo singular: "factura", "reintegro", "registro", "programación".
 * @var string $currLabel     Label del estado actual.
 * @var string $prevLabel     Label del estado al que se va a regresar.
 * @var string $entityArticle (opcional) Artículo demostrativo: "Esta" (default) o "Este".
 * @var string $extraNote     (opcional) Nota adicional debajo del párrafo principal.
 * @var array<string,string> $extraHiddenInputs (opcional) name => value de hidden inputs adicionales.
 */

$entityArticle     = $entityArticle ?? 'Esta';
$extraNote         = $extraNote ?? '';
$extraHiddenInputs = $extraHiddenInputs ?? [];
?>
<div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post"
              action="<?= h($actionUrl) ?>"
              id="regressStatusForm">
            <input type="hidden" name="_csrfToken" value="<?= h($this->request->getAttribute('csrfToken')) ?>">
            <?php foreach ($extraHiddenInputs as $name => $value): ?>
                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
            <?php endforeach; ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Regresar al paso anterior
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        <?= h($entityArticle) ?> <?= h($entityNoun) ?> volverá del paso
                        <strong><?= h($currLabel) ?></strong>
                        al paso
                        <strong><?= h($prevLabel) ?></strong>.
                        <?php if ($extraNote !== ''): ?>
                            <?= h($extraNote) ?>
                        <?php endif; ?>
                    </p>
                    <div class="mb-2">
                        <label for="regressReason" class="form-label">
                            Motivo de la regresión <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" id="regressReason"
                                  class="form-control" rows="4"
                                  required minlength="10" maxlength="500"
                                  placeholder="Describa por qué está regresando <?= h($entityArticle === 'Este' ? 'este' : 'esta') ?> <?= h($entityNoun) ?>..."></textarea>
                        <div class="form-text">Mín. 10 caracteres · Máx. 500.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" id="regressConfirmBtn" class="btn btn-warning" disabled>
                        Confirmar regreso
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var ta = document.getElementById('regressReason');
    var btn = document.getElementById('regressConfirmBtn');
    if (!ta || !btn) return;
    ta.addEventListener('input', function () {
        btn.disabled = ta.value.trim().length < 10;
    });
})();
</script>
