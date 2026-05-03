<?php
/**
 * Inicialización compartida del chat de observaciones.
 *
 * Emite:
 *   - <script src="sgi-observation-chat.js"> (block para evitar duplicados)
 *   - <template id="observation-bubble-template">
 *   - bloque inline con auto-resize de textareas + SgiObservationChat.init({...})
 *
 * Consumido por los 6 templates con chat (Invoices/edit, PaymentSchedulings/edit,
 * EmployeeNovelties/edit, Employees/view, PettyCashRecords/edit, Refunds/edit).
 *
 * Se asume que en la vista existen los selectores estándar:
 *   #obs-form, #obs-chat-scroll, #obs-empty-state, #obs-count.
 *
 * Required: ninguno. El partial es autocontenido.
 */
?>
<?= $this->Html->script('sgi-observation-chat', ['block' => true]) ?>
<?= $this->element('observation_bubble_template') ?>

<?php $this->append('script') ?>
<script>
(function () {
    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function (el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function () { syncHeight(this); });
    });

    if (window.SgiObservationChat) {
        SgiObservationChat.init({
            formSelector:           '#obs-form',
            listSelector:           '#obs-chat-scroll',
            emptySelector:          '#obs-empty-state',
            counterSelector:        '#obs-count',
            bubbleTemplateSelector: '#observation-bubble-template',
            csrfToken:              <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>,
        });
    }
})();
</script>
<?php $this->end() ?>
