/**
 * SpiGroupedInvoices — acciones inline en la tabla de facturas hijas de un
 * registro padre (Reintegro / Caja Menor / Anticipo). Spec 2026-07-14 §3.4.
 *
 * - Select DIAN por fila → POST /invoices/update-dian-inline/{id} (JSON),
 *   toast + actualización del checklist en sitio.
 * - Botón de soporte por fila → abre el upload_doc_modal compartido apuntando
 *   el form al uploadDocument de esa hija; tras subir OK recarga la página.
 */
(function (global) {
    'use strict';

    function toast(msg, variant) {
        if (global.SpiToast) { global.SpiToast.show(msg, variant || 'danger'); return; }
        global.alert(msg);
    }

    function updateChecklist(root, readiness) {
        if (!readiness) return;
        var box = root.querySelector('[data-grouped-checklist]');
        if (!box) return;
        var dian = box.querySelector('[data-slot="dian-pending"]');
        var support = box.querySelector('[data-slot="support-missing"]');
        if (dian) dian.textContent = readiness.dian_pending + ' con DIAN pendiente';
        if (support) support.textContent = readiness.support_missing + ' sin soporte';
        box.style.display = readiness.blocked ? '' : 'none';
    }

    function init(opts) {
        var root = document.querySelector(opts.rootSelector);
        if (!root) return;
        var csrfToken = opts.csrfToken || '';

        root.addEventListener('change', function (e) {
            var select = e.target.closest('.grouped-dian-select');
            if (!select) return;

            var body = new FormData();
            body.append('dian_validation', select.value);
            body.append('parent_field', root.dataset.parentField);
            body.append('parent_id', root.dataset.parentId);
            select.disabled = true;

            fetch('/invoices/update-dian-inline/' + select.dataset.invoiceId, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json'
                },
                body: body
            })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                if (res.ok && res.data.success) {
                    toast('Validación DIAN actualizada.', 'success');
                    updateChecklist(root, res.data.readiness);
                    var row = select.closest('tr,[role="row"]');
                    if (row && res.data.readiness) {
                        var warn = row.querySelector('[data-slot="row-warn"]');
                        if (warn && select.value === select.dataset.approvedValue) warn.style.display = 'none';
                    }
                } else {
                    toast(res.data.error || 'No se pudo actualizar.', 'danger');
                    select.value = select.dataset.currentValue;
                }
            })
            .catch(function () {
                toast('Error de conexión. Intente nuevamente.', 'danger');
                select.value = select.dataset.currentValue;
            })
            .finally(function () {
                select.disabled = false;
                select.dataset.currentValue = select.value;
            });
        });

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('.grouped-upload-btn');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();

            var form = opts.uploadFormSelector ? document.querySelector(opts.uploadFormSelector) : null;
            var modalEl = opts.uploadModalSelector ? document.querySelector(opts.uploadModalSelector) : null;
            if (!form || !modalEl || !global.bootstrap) return;
            form.dataset.url = btn.dataset.uploadUrl;
            global.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });

        // Navegación de fila scoped: el handler global de .clickable-row
        // (spi-common.js) aborta con closest('form') cuando la tabla vive dentro
        // del <form> de edit.php. Aquí navegamos sin ese guard. Las celdas
        // interactivas (DIAN/Soporte/desvincular) hacen stopPropagation en su
        // <td>/<span>, por lo que no llegan hasta este listener del root.
        root.addEventListener('click', function (e) {
            if (e.target.closest('a, button, select, input, textarea, label')) return;
            var row = e.target.closest('[data-href]');
            if (row && root.contains(row) && row.dataset.href) {
                global.location.href = row.dataset.href;
            }
        });

        var form = opts.uploadFormSelector ? document.querySelector(opts.uploadFormSelector) : null;
        if (form && !form.dataset.groupedBound) {
            form.dataset.groupedBound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fileInput = form.querySelector('input[type="file"]');
                if (!fileInput || !fileInput.files.length) return;

                fetch(form.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: new FormData(form)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        global.location.reload();
                    } else {
                        toast(data.error || 'Error al subir el archivo.', 'danger');
                    }
                })
                .catch(function () { toast('Error de conexión. Intente nuevamente.', 'danger'); });
            });
        }
    }

    global.SpiGroupedInvoices = { init: init };
})(window);
