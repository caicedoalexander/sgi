/**
 * SGI Common JS - Flatpickr + AutoNumeric + Row click
 */

window.SGI_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
window.SGI_MAX_UPLOAD_LABEL = '20 MB';

// Global guard: bloquea el submit de cualquier formulario con un archivo >20 MB
// para evitar el 413 de nginx. No interfiere con validaciones locales más estrictas
// (DIAN 10 MB, Leaves 5 MB) porque esas se ejecutan en sus propios handlers.
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.querySelectorAll) return;
    var fileInputs = form.querySelectorAll('input[type="file"]');
    if (!fileInputs.length) return;
    var max = window.SGI_MAX_UPLOAD_BYTES;
    var label = window.SGI_MAX_UPLOAD_LABEL;
    for (var i = 0; i < fileInputs.length; i++) {
        var files = fileInputs[i].files || [];
        for (var j = 0; j < files.length; j++) {
            if (files[j].size > max) {
                e.preventDefault();
                e.stopPropagation();
                alert('El archivo "' + files[j].name + '" supera el tamaño máximo de ' + label + '.');
                return;
            }
        }
    }
}, true);

document.addEventListener('DOMContentLoaded', function () {

    // ── Flatpickr para inputs de fecha ──────────────────────────────────────
    if (typeof flatpickr !== 'undefined') {
        flatpickr.localize(flatpickr.l10ns.es);

        document.querySelectorAll('input.flatpickr-date').forEach(function (el) {
            flatpickr(el, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'd/m/Y',
                locale: 'es',
                animate: true,
                allowInput: true,
            });
        });
    }

    // ── Tooltips Bootstrap en botones deshabilitados con title ──
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('button[disabled][title], a.disabled[title]').forEach(function (el) {
            var wrapper = document.createElement('span');
            wrapper.setAttribute('data-bs-toggle', 'tooltip');
            wrapper.setAttribute('title', el.getAttribute('title'));
            wrapper.style.display = 'inline-block';
            el.parentNode.insertBefore(wrapper, el);
            wrapper.appendChild(el);
            new bootstrap.Tooltip(wrapper);
        });
    }

    // ── AutoNumeric para campo de monto COP ─────────────────────────────────
    if (typeof AutoNumeric !== 'undefined') {
        document.querySelectorAll('input.currency-input').forEach(function (el) {
            new AutoNumeric(el, {
                digitGroupSeparator: '.',
                decimalCharacter: ',',
                currencySymbol: '$ ',
                currencySymbolPlacement: 'p',
                decimalPlaces: 0,
                unformatOnSubmit: true,
                modifyValueOnUpDownArrow: false,
            });
        });
    }

    // ── Sidebar: mantener --sidebar-width sincronizado con el ancho real ──────
    (function () {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;
        function syncWidth() {
            document.documentElement.style.setProperty('--sidebar-width', sidebar.offsetWidth + 'px');
        }
        // Actualizar cuando carguen fuentes (Bootstrap Icons, Inter)
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(syncWidth);
        }
        // Actualizar si el sidebar cambia de tamaño por cualquier razón
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(syncWidth).observe(sidebar);
        }
    })();

    // ── Sidebar: persistir estado de todos los collapses con localStorage ────
    document.querySelectorAll('.sidebar .sidebar-chevron-btn[data-bs-target]').forEach(function (btn) {
        var targetId = btn.getAttribute('data-bs-target').replace('#', '');
        var collapseEl = document.getElementById(targetId);
        if (!collapseEl) return;

        var storageKey = 'sgi_sidebar_' + targetId;
        var stored = localStorage.getItem(storageKey);
        var serverShow = collapseEl.classList.contains('show');

        // Aplicar preferencia guardada sin animación (antes del primer render)
        if (!serverShow && stored === 'show') {
            collapseEl.classList.add('show');
            btn.setAttribute('aria-expanded', 'true');
        } else if (serverShow && stored === 'hide') {
            collapseEl.classList.remove('show');
            btn.setAttribute('aria-expanded', 'false');
        }

        collapseEl.addEventListener('show.bs.collapse', function () {
            localStorage.setItem(storageKey, 'show');
            btn.setAttribute('aria-expanded', 'true');
        });
        collapseEl.addEventListener('hide.bs.collapse', function () {
            localStorage.setItem(storageKey, 'hide');
            btn.setAttribute('aria-expanded', 'false');
        });
    });

    // ── Auto-dismiss flash notifications ────────────────────────────────────
    document.querySelectorAll('#sgi-flash-container .alert').forEach(function (el) {
        setTimeout(function () {
            bootstrap.Alert.getOrCreateInstance(el).close();
        }, 4000);
    });

    // ── Select2 solo para selects con clase .select2-enable ──────────────────
    if (typeof $ !== 'undefined' && $.fn && $.fn.select2) {
        $('select.select2-enable').each(function () {
            var $modal = $(this).closest('.modal');
            $(this).select2({
                width: '100%',
                language: 'es',
                minimumResultsForSearch: 7,
                dropdownParent: $modal.length ? $modal : $(document.body),
            });
        });
    }

    // ── Click en fila/card para navegar ─────────────────────────────────────
    document.querySelectorAll('.clickable-row').forEach(function (el) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form')) return;
            var href = this.dataset.href;
            if (href) window.location.href = href;
        });
    });

    // ── Documento Equivalente toggle ──────────────────────────────────────
    (function () {
        var checkbox = document.getElementById('is-equivalent-document');
        if (!checkbox) return;

        var holderTypeWrapper = document.getElementById('holder-type-wrapper');
        var holderTypeSelect = document.getElementById('equivalent-holder-type');
        var providerWrapper = document.getElementById('provider-wrapper');
        var employeeWrapper = document.getElementById('employee-wrapper');
        var manualDocWrapper = document.getElementById('manual-doc-wrapper');
        var purchaseOrderWrapper = document.getElementById('purchase-order-wrapper');
        var dueDateWrapper = document.getElementById('due-date-wrapper');

        function toggleEquivalent() {
            var isEquiv = checkbox.checked;

            // Show/hide holder type selector
            if (holderTypeWrapper) holderTypeWrapper.classList.toggle('d-none', !isEquiv);

            // Disable purchase order and due date
            if (purchaseOrderWrapper) {
                var poInput = purchaseOrderWrapper.querySelector('input');
                if (poInput) { poInput.disabled = isEquiv; if (isEquiv) poInput.value = ''; }
            }
            if (dueDateWrapper) {
                var ddInput = dueDateWrapper.querySelector('input:not([type=hidden])');
                if (ddInput) { ddInput.disabled = isEquiv; if (isEquiv) ddInput.value = ''; }
            }

            if (!isEquiv) {
                // Reset: show provider, hide employee/manual
                if (providerWrapper) providerWrapper.classList.remove('d-none');
                if (employeeWrapper) employeeWrapper.classList.add('d-none');
                if (manualDocWrapper) manualDocWrapper.classList.add('d-none');
                if (holderTypeSelect) holderTypeSelect.value = '';
            } else {
                toggleHolderType();
            }
        }

        function toggleHolderType() {
            if (!holderTypeSelect) return;
            var type = holderTypeSelect.value;

            // Provider: show only when type is 'provider' or empty (default)
            if (providerWrapper) providerWrapper.classList.toggle('d-none', type === 'employee' || type === 'manual');
            if (employeeWrapper) employeeWrapper.classList.toggle('d-none', type !== 'employee');
            if (manualDocWrapper) manualDocWrapper.classList.toggle('d-none', type !== 'manual');
        }

        checkbox.addEventListener('change', toggleEquivalent);
        if (holderTypeSelect) holderTypeSelect.addEventListener('change', toggleHolderType);

        // Initialize state on page load
        toggleEquivalent();
    })();

});
