<?php
/**
 * Scripts del template Invoices/edit. Tres bloques independientes
 * que el layout consolida en `fetch('script')`:
 *   1. SgiDocumentUploader init (subida/eliminación de soportes).
 *   2. (Solo Anticipo) toggle de beneficiary-type entre provider/employee.
 *   3. Toggle del flujo de Recibo de Caja: doc_type → equivalent_doc_row
 *      + holder → employee/manual/provider wrappers.
 *
 * @var \App\View\AppView $this
 * @var bool $isAdvance
 */

use App\Constants\InvoiceConstants;
?>
<?php $this->append('script') ?>
<script>
(function () {
    // ── Rich approver chips para Select2 (matches spec FldAprobadores) ──
    // Cada item picked se renderiza como avatar + name + pill Pendiente.
    // Color del avatar deriva del nombre (hash → paleta de 7 tonos).
    if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;

    var palette = ['#469D61', '#CD6A15', '#83542B', '#212529', '#5a4a2a', '#4a6f5c', '#7a4c1e'];
    function colorFromName(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) h = ((h << 5) - h + name.charCodeAt(i)) | 0;
        return palette[Math.abs(h) % palette.length];
    }
    function initials(name) {
        return (name || '').split(/\s+/).filter(Boolean).slice(0, 2)
            .map(function (w) { return w.charAt(0).toUpperCase(); })
            .join('') || '·';
    }

    $('select.select2-rich-approvers').each(function () {
        var $sel = $(this);
        if ($sel.hasClass('select2-hidden-accessible')) return;
        var $modal = $sel.closest('.modal');

        $sel.select2({
            width: 'auto',
            language: 'es',
            placeholder: $sel.data('placeholder') || 'Buscar aprobador…',
            minimumResultsForSearch: 0,
            dropdownParent: $modal.length ? $modal : $(document.body),
            templateSelection: function (item) {
                if (!item.id) return item.text;
                var name = item.text;
                var $chip = $(
                    '<span class="sgi-approver-chip-inner">' +
                    '<span class="sgi-av sgi-av-sm" style="background:' + colorFromName(name) + ';">' + initials(name) + '</span>' +
                    '<span class="sgi-approver-chip-info">' +
                    '<span class="sgi-approver-chip-name"></span>' +
                    '<span class="sgi-approver-chip-meta">sin enviar</span>' +
                    '</span>' +
                    '<span class="pill pill-warning-soft"><i class="bi bi-clock" aria-hidden="true" style="font-size:9px;"></i>Pendiente</span>' +
                    '</span>'
                );
                $chip.find('.sgi-approver-chip-name').text(name);
                return $chip;
            },
        });
    });
})();
</script>

<script>
(function () {
    // ── Dirty form indicator (matches spec editar-factura.jsx) ──
    // Revela los `[data-dirty-indicator]` cuando el usuario toca cualquier
    // input/select/textarea del formulario principal.
    var form = document.getElementById('invoiceEditForm');
    if (!form) return;
    var indicators = document.querySelectorAll('[data-dirty-indicator]');
    if (!indicators.length) return;
    var dirty = false;
    function markDirty() {
        if (dirty) return;
        dirty = true;
        indicators.forEach(function (el) { el.hidden = false; });
    }
    form.addEventListener('input',  markDirty, { once: false });
    form.addEventListener('change', markDirty, { once: false });
    form.addEventListener('submit', function () {
        // Al enviar limpiamos para no advertir tras un guardado exitoso.
        dirty = false;
        indicators.forEach(function (el) { el.hidden = true; });
    });
})();
</script>

<script>
(function(){
    // ── Documents (upload + delete) via shared helper ──
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadInvoiceDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>

<?php if ($isAdvance): ?>
<script>
(function () {
    var typeSelect = document.getElementById('beneficiary-type');
    if (!typeSelect) return;
    var providerWrapper = document.getElementById('provider-wrapper');
    var employeeWrapper = document.getElementById('employee-wrapper');
    if (!providerWrapper || !employeeWrapper) return;
    var providerSelect = providerWrapper.querySelector('select');
    var employeeSelect = employeeWrapper.querySelector('select');

    function applyToggle() {
        var value = typeSelect.value;
        if (value === 'provider') {
            providerWrapper.classList.remove('d-none');
            employeeWrapper.classList.add('d-none');
            employeeSelect.value = '';
            if (employeeSelect.dispatchEvent) employeeSelect.dispatchEvent(new Event('change'));
        } else if (value === 'employee') {
            employeeWrapper.classList.remove('d-none');
            providerWrapper.classList.add('d-none');
            providerSelect.value = '';
            if (providerSelect.dispatchEvent) providerSelect.dispatchEvent(new Event('change'));
        } else {
            providerWrapper.classList.add('d-none');
            employeeWrapper.classList.add('d-none');
        }
    }

    typeSelect.addEventListener('change', applyToggle);
})();
</script>
<?php endif; ?>
<?php $this->end() ?>

<?php $this->append('script') ?>
<script>
(function () {
    var docTypeSelect = document.querySelector('select[name="document_type"]');
    if (!docTypeSelect) return;

    var equivalentRow = document.getElementById('equivalent-doc-row');
    var holderSelect  = document.getElementById('equivalent-holder-type');
    var employeeWrap  = document.getElementById('employee-wrapper');
    var manualWrap    = document.getElementById('manual-doc-wrapper');
    var providerWrap  = document.getElementById('provider-wrapper');
    var dueDateInput  = document.querySelector('input[name="due_date"]');
    // Si el form es Anticipo el provider-wrapper lo gobierna otro toggle (beneficiary-type),
    // no debemos tocarlo aquí.
    var hasBeneficiaryToggle = document.getElementById('beneficiary-type') !== null;

    function setVisible(wrapper, visible) {
        if (!wrapper) return;
        wrapper.classList.toggle('d-none', !visible);
        wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
            if (!visible) {
                el.value = '';
                el.checked = false;
                // Reset Select2 widget si aplica (sin esto el label cacheado queda visible).
                if (window.jQuery && el.tagName === 'SELECT' && window.jQuery(el).hasClass('select2-hidden-accessible')) {
                    window.jQuery(el).val(null).trigger('change');
                }
            }
        });
    }

    function applyHolderRules() {
        var holder = holderSelect ? holderSelect.value : '';
        setVisible(employeeWrap, holder === 'employee');
        setVisible(manualWrap,   holder === 'manual');
        if (!hasBeneficiaryToggle) {
            // Proveedor visible sólo cuando el titular es 'provider' o aún no se ha elegido.
            setVisible(providerWrap, holder === '' || holder === 'provider');
        }
    }

    function applyDocTypeRules() {
        var isReciboDeCaja = docTypeSelect.value === <?= json_encode(InvoiceConstants::DOCTYPE_RECIBO_CAJA) ?>;

        setVisible(equivalentRow, isReciboDeCaja);

        if (dueDateInput) {
            dueDateInput.disabled = isReciboDeCaja;
            if (isReciboDeCaja) dueDateInput.value = '';
        }

        if (!isReciboDeCaja) {
            if (holderSelect) holderSelect.value = '';
            setVisible(employeeWrap, false);
            setVisible(manualWrap,   false);
            if (!hasBeneficiaryToggle) setVisible(providerWrap, true);
        } else {
            applyHolderRules();
        }
    }

    docTypeSelect.addEventListener('change', applyDocTypeRules);
    if (holderSelect) holderSelect.addEventListener('change', applyHolderRules);
    // Asimetría intencional con add.php: no llamamos applyDocTypeRules() en load.
    // Razón: el server rehidrata el DOM con clases d-none correctas y respeta la policy
    // (campos disabled cuando $canEdit('document_type') === false). Llamar setVisible()
    // forzaría disabled=false sobre campos que la policy debe mantener bloqueados.
})();
</script>
<?php $this->end() ?>
